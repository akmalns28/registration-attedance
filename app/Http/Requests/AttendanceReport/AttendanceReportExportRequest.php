<?php

namespace App\Http\Requests\AttendanceReport;

class AttendanceReportExportRequest extends AttendanceReportRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('reports.export');
    }
}
