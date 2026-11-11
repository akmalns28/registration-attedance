<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('dashboard.index', [
            'user' => $user,

            'stats' => [
                'total_events' => null,
                'total_participants' => null,
                'total_registered' => null,
                'total_attendance' => null,
            ],

            'recentEvents' => collect(),

            'permissions' => [
                'manage_events' => $user->can('events.create'),
                'manage_registrations' => $user->can('registrations.manage'),
                'scan_attendance' => $user->can('attendance.scan'),
                'view_attendance' => $user->can('attendance.view'),
                'view_reports' => $user->can('reports.view'),
            ],
        ]);
    }
}