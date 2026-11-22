<?php

namespace App\Http\Controllers;

use App\Http\Requests\Analytic\AnalyticFilterRequest;
use App\Services\AnalyticService;
use App\Services\OrganizationFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class AnalyticController extends Controller
{
    public function index(AnalyticFilterRequest $request, AnalyticService $analytics): View
    {
        return view('analytic.index', [
            'events' => $analytics->events(),
            'organizationOptions' => OrganizationFilters::options(),
            'analytics' => $analytics->data($request->validated()),
        ]);
    }

    public function data(AnalyticFilterRequest $request, AnalyticService $analytics): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $analytics->data($request->validated())])
            ->header('Cache-Control', 'no-store, private');
    }
}
