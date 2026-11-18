<?php

namespace App\Services;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ImportSpreadsheetTemplate extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithStyles
{
    public function __construct(private string $module) {}

    /** @return array<int, array<int, string>> */
    public function array(): array
    {
        return [BulkImportService::module($this->module)['columns']];
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

        return true;
    }

    /** @return array<int|string, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->setTitle('Data')->freezePane('A2');
        foreach (BulkImportService::module($this->module)['columns'] as $index => $field) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($column)->setWidth($field === 'name' || $field === 'email' ? 32 : 24);
            $sheet->getStyle($column.'2:'.$column.((int) config('bulk_imports.max_rows') + 1))->getNumberFormat()->setFormatCode('@');
            $hint = match ($field) {
                'nip', 'phone' => 'Gunakan format Text. Nol di depan harus tetap tersimpan.',
                'department_id' => 'ID departemen aktif. Lihat referensi departemen pada halaman Import Excel.',
                'is_active' => $this->module === 'users' ? 'Isi 0. Akun impor selalu nonaktif sampai administrator memverifikasi identitas Keycloak.' : 'Isi 1 untuk aktif atau 0 untuk nonaktif.',
                'role' => 'Nama role web yang sudah ada, selain super-admin. Lihat referensi pada halaman impor.',
                default => 'Isi data sesuai nama kolom. Jangan menggunakan formula.',
            };
            $sheet->getComment($column.'1')->getText()->createTextRun($hint);
        }

        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E7EEF8']]]];
    }
}
