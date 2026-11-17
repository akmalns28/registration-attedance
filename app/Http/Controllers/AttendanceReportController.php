<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceReport\AttendanceReportRequest;
use App\Models\Event;
use App\Services\AttendanceReportService;
use Illuminate\Http\JsonResponse;

class AttendanceReportController extends Controller
{
    public function __invoke(AttendanceReportRequest $request, Event $event, AttendanceReportService $reports): JsonResponse
    {
        return response()->json($reports->data($event, $request->validated()))
            ->header('Cache-Control', 'no-store, private');
    }
}
