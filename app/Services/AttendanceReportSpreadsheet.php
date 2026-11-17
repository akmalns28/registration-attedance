<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceReportSpreadsheet extends DefaultValueBinder implements FromGenerator, WithColumnWidths, WithCustomValueBinder, WithStrictNullComparison, WithStyles
{
    /** @param array{metadata: array<string, string>, summary: array{expected: int, present: int, not_present: int, percentage: float}, rows: LazyCollection<int, array<string, int|string>>} $context */
    public function __construct(private array $context) {}

    public function generator(): Generator
    {
        foreach (AttendanceReportExportService::headerRows($this->context) as $index => $row) {
            if ($index === 8) {
                $row[9] = $this->context['summary']['percentage'] / 100;
            }
            yield $row === [] ? [''] : $row;
        }
        foreach ($this->context['rows'] as $row) {
            $values = AttendanceReportExportService::displayRow($row);
            $values[6] = Date::dateTimeToExcel(Carbon::parse($row['event_day_date']));
            if ($row['checkin_time'] !== '-') {
                [$hours, $minutes, $seconds] = array_map(intval(...), explode(':', $row['checkin_time']));
                $values[8] = ($hours * 3600 + $minutes * 60 + $seconds) / 86400;
            }
            yield $values;
        }
        if ($this->context['summary']['expected'] === 0) {
            yield ['Tidak ada data yang sesuai dengan filter.'];
        }
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** @return array<string, int> */
    public function columnWidths(): array
    {
        return ['A' => 6, 'B' => 25, 'C' => 30, 'D' => 18, 'E' => 25, 'F' => 28, 'G' => 16, 'H' => 19, 'I' => 16, 'J' => 26];
    }

    /** @return array<int|string, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $last = $sheet->getHighestRow();
        $sheet->mergeCells('A1:J1')->mergeCells('A2:J2');
        for ($row = 3; $row <= 7; $row++) {
            $sheet->mergeCells('B'.$row.':J'.$row);
        }
        $sheet->mergeCells('A9:B9')->mergeCells('H9:I9');
        $sheet->getRowDimension(6)->setRowHeight(52);
        $sheet->getRowDimension(9)->setRowHeight(36);
        $sheet->getRowDimension(11)->setRowHeight(30);
        $sheet->freezePane('A12');
        $sheet->setAutoFilter('A11:J'.max(11, 11 + $this->context['summary']['expected']));
        $sheet->getStyle('A1:J'.$last)->getAlignment()->setVertical('top')->setWrapText(true);
        $sheet->getStyle('A12:A'.$last)->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle('G12:G'.$last)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle('I12:I'.$last)->getNumberFormat()->setFormatCode('hh:mm:ss');
        $sheet->getStyle('J9')->getNumberFormat()->setFormatCode('0.00%');
        $sheet->getStyle('A11:J11')->getFill()->setFillType('solid')->getStartColor()->setRGB('E7EEF8');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(11, 11);
        if ($this->context['summary']['expected'] === 0) {
            $sheet->mergeCells('A12:J12');
            $sheet->getRowDimension(12)->setRowHeight(30);
        }

        return [1 => ['font' => ['bold' => true, 'size' => 14]], 2 => ['font' => ['bold' => true, 'size' => 12]],
            9 => ['font' => ['bold' => true], 'alignment' => ['horizontal' => 'center']], 11 => ['font' => ['bold' => true]]];
    }
}
