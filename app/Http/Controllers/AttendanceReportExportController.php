<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceReport\AttendanceReportExportRequest;
use App\Models\Event;
use App\Services\AttendanceReportExportService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportExportController extends Controller
{
    public function excel(AttendanceReportExportRequest $request, Event $event, AttendanceReportExportService $exports): BinaryFileResponse
    {
        return $exports->excel($event, $request->validated());
    }

    public function csv(AttendanceReportExportRequest $request, Event $event, AttendanceReportExportService $exports): StreamedResponse
    {
        return $exports->csv($event, $request->validated());
    }

    public function pdf(AttendanceReportExportRequest $request, Event $event, AttendanceReportExportService $exports): Response
    {
        return $exports->pdf($event, $request->validated());
    }

    public function print(AttendanceReportExportRequest $request, Event $event, AttendanceReportExportService $exports): StreamedResponse
    {
        return $exports->print($event, $request->validated());
    }
}
