<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;
use ZipArchive;

class ImportSpreadsheetReader
{
    private const MAX_CELL_LENGTH = 4096;

    /**
     * Values are read without calculating formulas or resolving external resources.
     * Identifiers must be text: a number already saved by Excel may have lost zeros.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $required
     * @param  array<string, string>  $aliases
     * @return array<int, array{row: int, data: array<string, string|null>, errors: array<int, string>}>
     */
    public function read(UploadedFile $file, array $columns, array $required, array $aliases): array
    {
        try {
            $path = $file->getRealPath();
            $extension = strtolower($file->getClientOriginalExtension());
            if ($extension === 'csv') {
                return $this->readCsv($path, $columns, $required, $aliases);
            }
            if ($extension === 'xlsx') {
                $this->checkArchive($path);
            }
            $format = IOFactory::identify($path, ['Xlsx', 'Xls']);
            if (strtolower($format) !== $extension) {
                $this->reject('Isi berkas tidak sesuai dengan ekstensi Excel.');
            }
            $reader = IOFactory::createReader($format);
            $info = $reader->listWorksheetInfo($path);
            $maximum = max(1, (int) config('bulk_imports.max_rows'));
            if (empty($info) || $info[0]['totalRows'] > $maximum + 1 || $info[0]['totalColumns'] > count($columns)) {
                $this->reject("Berkas maksimal {$maximum} baris data dan hanya boleh memuat kolom template.");
            }
            $reader->setReadDataOnly(true)->setReadEmptyCells(false)->setIncludeCharts(false)->setLoadSheetsOnly($info[0]['worksheetName']);
            $reader->setReadFilter(new class($maximum + 1, count($columns)) implements IReadFilter
            {
                public function __construct(private int $rows, private int $columns) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->rows && Coordinate::columnIndexFromString($columnAddress) <= $this->columns;
                }
            });
            $workbook = $reader->load($path);
            try {
                if ($workbook->hasMacros()) {
                    $this->reject('Berkas dengan macro tidak didukung. Gunakan template tanpa macro.');
                }
                $sheet = $workbook->getSheet(0);
                $headers = [];
                for ($column = 1; $column <= $info[0]['totalColumns']; $column++) {
                    $cell = $sheet->getCell([$column, 1]);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $this->reject('Judul kolom tidak valid atau berulang. Gunakan nama kolom pada template.');
                    }
                    $headers[$column] = (string) $cell->getValue();
                }
                $headers = $this->headers($headers, $columns, $required, $aliases);
                $rows = [];
                for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
                    $data = array_fill_keys($columns, null);
                    $errors = [];
                    $empty = true;
                    foreach ($headers as $column => $field) {
                        $cell = $sheet->getCell([$column, $number]);
                        $value = $cell->getValue();
                        if ($value !== null && $value !== '') {
                            $empty = false;
                        }
                        if ($cell->getDataType() === DataType::TYPE_FORMULA || $cell->getDataType() === DataType::TYPE_ERROR) {
                            $errors[] = "Kolom {$field}: formula atau error Excel tidak diizinkan. Gunakan nilai biasa.";

                            continue;
                        }
                        if ((in_array($field, ['nip', 'phone'], true) || str_ends_with($field, '_code')) && is_numeric($value) && ! is_string($value)) {
                            $errors[] = "Kolom {$field}: gunakan format Text agar nol di depan tidak hilang.";

                            continue;
                        }
                        if ($value instanceof RichText) {
                            $value = $value->getPlainText();
                        }
                        if ($value !== null) {
                            $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                            $data[$field] = $this->limitCell($text, $field, $errors);
                        }
                    }
                    if (! $empty) {
                        $rows[] = ['row' => $number, 'data' => $data, 'errors' => $errors];
                    }
                }

                return $this->requireRows($rows);
            } finally {
                $workbook->disconnectWorksheets();
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->reject('Berkas rusak atau tidak didukung. Unggah XLSX, XLS, atau CSV dari template.');
        }
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $required
     * @param  array<string, string>  $aliases
     * @return array<int, array{row: int, data: array<string, string|null>, errors: array<int, string>}>
     */
    private function readCsv(string $path, array $columns, array $required, array $aliases): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $this->reject('Berkas CSV tidak dapat dibaca.');
        }
        try {
            if (fread($stream, 3) !== "\xEF\xBB\xBF") {
                rewind($stream);
            }
            $start = ftell($stream);
            $limit = count($columns) * (self::MAX_CELL_LENGTH * 4 * 2 + 3) + 2;
            $delimiter = ',';
            $headers = $this->csvRecord($stream, $delimiter, $limit);
            if (count($headers ?: []) === 1) {
                fseek($stream, $start);
                $delimiter = ';';
                $headers = $this->csvRecord($stream, $delimiter, $limit);
            }
            $headers = $this->headers($headers ?: [], $columns, $required, $aliases);
            $maximum = max(1, (int) config('bulk_imports.max_rows'));
            $rows = [];
            $number = 1;
            while (($values = $this->csvRecord($stream, $delimiter, $limit)) !== false) {
                $number++;
                if ($number > $maximum + 1) {
                    $this->reject("Berkas maksimal {$maximum} baris data.");
                }
                if (! array_any($values, fn (?string $value): bool => $value !== null && $value !== '')) {
                    continue;
                }

                $data = array_fill_keys($columns, null);
                $errors = count($values) > count($headers) ? ['Jumlah nilai melebihi jumlah kolom pada judul CSV.'] : [];
                foreach ($headers as $column => $field) {
                    $value = $values[$column] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    if (str_starts_with(Str::trim($value), '=')) {
                        $errors[] = "Kolom {$field}: formula tidak diizinkan. Gunakan nilai biasa.";

                        continue;
                    }
                    $data[$field] = $this->limitCell($value, $field, $errors);
                }
                $rows[] = ['row' => $number, 'data' => $data, 'errors' => $errors];
            }

            return $this->requireRows($rows);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @return array<int, string|null>|false
     */
    private function csvRecord($stream, string $delimiter, int $limit): array|false
    {
        $offset = ftell($stream);
        $record = fgetcsv($stream, $limit, $delimiter, '"', '');
        if (ftell($stream) - $offset >= $limit) {
            $this->reject('Baris CSV terlalu panjang.');
        }
        if ($record !== false && array_any($record, fn (?string $value): bool => $value !== null && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")))) {
            $this->reject('Berkas CSV harus menggunakan encoding UTF-8.');
        }

        return $record;
    }

    /**
     * @param  array<int, string|null>  $headers
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $required
     * @param  array<string, string>  $aliases
     * @return array<int, string>
     */
    private function headers(array $headers, array $columns, array $required, array $aliases): array
    {
        $fields = [];
        foreach ($headers as $column => $value) {
            $header = Str::lower(Str::trim($value ?? ''));
            $field = $aliases[$header] ?? $header;
            if (! in_array($field, $columns, true) || in_array($field, $fields, true)) {
                $this->reject('Judul kolom tidak valid atau berulang. Gunakan nama kolom pada template.');
            }
            $fields[$column] = $field;
        }
        if (array_diff($required, $fields) !== []) {
            $this->reject('Kolom wajib tidak lengkap: '.implode(', ', array_diff($required, $fields)).'.');
        }

        return $fields;
    }

    /** @param array<int, string> $errors */
    private function limitCell(string $text, string $field, array &$errors): string
    {
        if (mb_strlen($text) > self::MAX_CELL_LENGTH) {
            $errors[] = "Kolom {$field}: isi sel terlalu panjang.";

            return mb_substr($text, 0, self::MAX_CELL_LENGTH);
        }

        return $text;
    }

    /**
     * @param  array<int, array{row: int, data: array<string, string|null>, errors: array<int, string>}>  $rows
     * @return array<int, array{row: int, data: array<string, string|null>, errors: array<int, string>}>
     */
    private function requireRows(array $rows): array
    {
        if ($rows === []) {
            $this->reject('Berkas tidak memiliki baris data. Isi template mulai baris 2.');
        }

        return $rows;
    }

    private function checkArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->reject('Berkas XLSX tidak dapat dibaca.');
        }
        try {
            $size = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $size += $entry['size'];
                if ($size > config('bulk_imports.max_uncompressed_bytes') || preg_match('/vbaProject|externalLinks|\.bin$/i', $entry['name']) === 1) {
                    $this->reject('Workbook terlalu besar atau memuat macro/referensi eksternal yang tidak didukung.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
