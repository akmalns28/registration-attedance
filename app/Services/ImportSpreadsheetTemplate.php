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
                'nip' => 'Contoh EMP000001 atau 0000123. Gunakan format Text untuk menjaga nol di depan.',
                'phone' => 'Contoh 08123456789. Gunakan format Text untuk menjaga nol di depan.',
                'department' => 'Gunakan kode atau nama unik dari daftar departemen aktif, misalnya IT atau Operasional jika tersedia.',
                'status' => $this->module === 'users'
                    ? 'Contoh Tidak Aktif. Akun baru selalu nonaktif sampai administrator memverifikasi identitas Keycloak.'
                    : 'Gunakan Aktif atau Tidak Aktif. Active dan Inactive juga diterima.',
                'role' => 'Gunakan nama role pada halaman impor, misalnya panitia jika tersedia.',
                'name' => 'Contoh Akmal NS.',
                'email' => 'Contoh akmal@example.com.',
                'organization' => 'Contoh PT Nusantara.',
                'position' => 'Contoh Manajer.',
            };
            $sheet->getComment($column.'1')->getText()->createTextRun($hint);
        }

        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E7EEF8']]]];
    }
}
