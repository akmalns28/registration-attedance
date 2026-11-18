<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
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
    /**
     * Values are read without calculating formulas or resolving external resources.
     * Identifiers must be text: a number already saved by Excel may have lost zeros.
     *
     * @param array<int, string> $columns
     * @param array<int, string> $required
     * @return array<int, array{row: int, data: array<string, string|null>, errors: array<int, string>}>
     */
    public function read(UploadedFile $file, array $columns, array $required): array
    {
        try {
            $path = $file->getRealPath();
            if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
                $this->checkArchive($path);
            }
            $format = IOFactory::identify($path, ['Xlsx', 'Xls']);
            if (($format === 'Xlsx' && strtolower($file->getClientOriginalExtension()) !== 'xlsx') ||
                ($format === 'Xls' && strtolower($file->getClientOriginalExtension()) !== 'xls')) {
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
                    $header = strtolower(trim((string) $cell->getValue()));
                    if ($cell->getDataType() === DataType::TYPE_FORMULA || !in_array($header, $columns, true) || in_array($header, $headers, true)) {
                        $this->reject('Judul kolom tidak valid atau berulang. Gunakan nama kolom pada template.');
                    }
                    $headers[$column] = $header;
                }
                if (array_diff($required, $headers) !== []) {
                    $this->reject('Kolom wajib tidak lengkap: '.implode(', ', array_diff($required, $headers)).'.');
                }
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
                        if (in_array($field, ['nip', 'phone'], true) && is_numeric($value) && !is_string($value)) {
                            $errors[] = "Kolom {$field}: gunakan format Text agar nol di depan tidak hilang.";
                            continue;
                        }
                        if ($value instanceof RichText) {
                            $value = $value->getPlainText();
                        }
                        if ($value !== null) {
                            $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                            $data[$field] = mb_substr($text, 0, 4096);
                            if (mb_strlen($text) > 4096) {
                                $errors[] = "Kolom {$field}: isi sel terlalu panjang.";
                            }
                        }
                    }
                    if (!$empty) {
                        $rows[] = ['row' => $number, 'data' => $data, 'errors' => $errors];
                    }
                }
                if ($rows === []) {
                    $this->reject('Berkas tidak memiliki baris data. Isi template mulai baris 2.');
                }

                return $rows;
            } finally {
                $workbook->disconnectWorksheets();
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->reject('Berkas Excel rusak atau tidak didukung. Unggah XLSX atau XLS dari template.');
        }
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
