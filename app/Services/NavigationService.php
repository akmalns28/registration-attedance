<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class NavigationService
{
    public function getMenu(?User $user): array
    {
        if (! $user || ! $user->is_active) {
            return [];
        }

        $navigation = config('navigation', []);

        $filteredNavigation = [];

        foreach ($navigation as $section) {
            $items = [];

            foreach ($section['items'] ?? [] as $item) {
                if (! Route::has($item['route'])) {
                    continue;
                }

                if (isset($item['permission']) && ! $user->can($item['permission'])) {
                    continue;
                }

                $items[] = [...$item, 'url' => route($item['route']), 'is_active' => request()->routeIs(...Arr::wrap($item['active'] ?? $item['route']))];
            }

            if (! empty($items)) {
                $filteredNavigation[] = [
                    'title' => $section['title'],
                    'items' => $items,
                ];
            }
        }

        return $filteredNavigation;
    }

    /** @return list<array{label: string, url?: ?string}> */
    public function getBreadcrumbs(Request $request, string $pageTitle): array
    {
        if ($request->routeIs('dashboard')) {
            return [['label' => 'Home']];
        }

        $user = $request->user();
        $items = [['label' => 'Home', 'url' => $user?->can('dashboard.view') ? route('dashboard') : null]];

        foreach (config('navigation', []) as $section) {
            foreach ($section['items'] as $item) {
                if (! $request->routeIs(...Arr::wrap($item['active'] ?? $item['route'])) || ! Route::has($item['route'])) {
                    continue;
                }

                if ($section['title'] !== 'Main Menu') {
                    $items[] = ['label' => $section['title']];
                }
                if ($request->routeIs($item['route']) || ($item['route'] === 'organizations.index' && $request->routeIs('departments.index'))) {
                    return [...$items, ['label' => $item['label']]];
                }

                $canVisit = ! isset($item['permission']) || $user?->can($item['permission']);
                $items[] = ['label' => $item['label'], 'url' => $canVisit ? route($item['route']) : null];

                break 2;
            }
        }

        $event = $request->route('event');
        if ($event instanceof Event) {
            if ($request->routeIs('events.show')) {
                return [...$items, ['label' => $event->title]];
            }
            $canViewEvent = $user?->can('events.view');
            $items[] = ['label' => $event->title, 'url' => $canViewEvent ? route('events.show', $event) : null];

            $day = $request->route('day');
            if ($day instanceof EventDay) {
                $label = 'Day '.$day->day_number.($day->title ? ' - '.$day->title : '');
                if ($request->routeIs('events.days.show')) {
                    return [...$items, ['label' => $label]];
                }
                $items[] = ['label' => $label, 'url' => $canViewEvent ? route('events.days.show', [$event, $day]) : null];
            }
        }

        if ($request->routeIs('*.import.show', '*.import.result')) {
            $importIndex = Str::beforeLast($request->route()->getName(), '.').'.index';
            $items[] = ['label' => 'Import Excel', 'url' => route($importIndex)];
        }

        return [...$items, ['label' => $pageTitle]];
    }
}
