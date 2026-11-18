<?php

namespace App\Services;

use Illuminate\Support\Str;

class PermissionLabelFormatter
{
    public static function action(string $permission): string
    {
        $segments = explode('.', trim($permission));
        $action = trim((string) end($segments));

        return Str::headline($action !== '' ? $action : str_replace('.', ' ', $permission)) ?: 'Permission';
    }
}
