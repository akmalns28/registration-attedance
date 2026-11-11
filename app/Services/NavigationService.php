<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;

class NavigationService
{
    public function getMenu(?User $user): array
    {
        if (!$user || !$user->is_active) {
            return [];
        }

        $navigation = config('navigation', []);

        $filteredNavigation = [];

        foreach ($navigation as $section) {
            $items = [];

            foreach ($section['items'] ?? [] as $item) {
                if (!Route::has($item['route'])) {
                    continue;
                }

                if (isset($item['permission']) && !$user->can($item['permission'])) {
                    continue;
                }

                $items[] = [...$item, 'url' => route($item['route']), 'is_active' => request()->routeIs($item['active'] ?? $item['route'])];
            }

            if (!empty($items)) {
                $filteredNavigation[] = [
                    'title' => $section['title'],
                    'items' => $items,
                ];
            }
        }

        return $filteredNavigation;
    }
}
