<?php

return [
    [
        'title' => 'Main Menu',
        'items' => [
            [
                'label' => 'Dashboard',
                'icon' => 'layout-dashboard',
                'route' => 'dashboard',
                'active' => 'dashboard',
                'permission' => 'dashboard.view',
            ],
            [
                'label' => 'Events',
                'icon' => 'calendar-event',
                'route' => 'events.index',
                'active' => 'events.*',
                'permission' => 'events.view',
            ],
            [
                'label' => 'Registrations',
                'icon' => 'ticket',
                'route' => 'registrations.index',
                'active' => 'registrations.*',
                'permission' => 'registrations.view',
            ],
            [
                'label' => 'QR Scanner',
                'icon' => 'qrcode',
                'route' => 'attendance.scanner',
                'active' => 'attendance.scanner',
                'permission' => 'attendance.scan',
            ],
            [
                'label' => 'Analytic',
                'icon' => 'chart-line',
                'route' => 'analytic.index',
                'active' => 'analytic.*',
                'permission' => 'reports.view',
            ],
            [
                'label' => 'Attendance',
                'icon' => 'clipboard-check',
                'route' => 'attendance.index',
                'active' => 'attendance.*',
                'permission' => 'attendance.view',
            ],
        ],
    ],

    [
        'title' => 'Master Data',
        'items' => [
            [
                'label' => 'Departments',
                'icon' => 'building',
                'route' => 'departments.index',
                'active' => 'departments.*',
                'permission' => 'departments.view',
            ],
            [
                'label' => 'Employees',
                'icon' => 'users',
                'route' => 'employees.index',
                'active' => 'employees.*',
                'permission' => 'employees.view',
            ],
            [
                'label' => 'External Participants',
                'icon' => 'user-plus',
                'route' => 'external-participants.index',
                'active' => 'external-participants.*',
                'permission' => 'external-participants.view',
            ],
        ],
    ],

    [
        'title' => 'Reports',
        'items' => [
            [
                'label' => 'Attendance Reports',
                'icon' => 'chart-bar',
                'route' => 'reports.attendance',
                'active' => 'reports.*',
                'permission' => 'reports.view',
            ],
        ],
    ],

    [
        'title' => 'Administration',
        'items' => [
            [
                'label' => 'User Management',
                'icon' => 'user-cog',
                'route' => 'settings.users.index',
                'active' => 'settings.users.*',
                'permission' => 'users.manage',
            ],
            [
                'label' => 'Role Management',
                'icon' => 'shield-lock',
                'route' => 'settings.roles.index',
                'active' => 'settings.roles.*',
                'permission' => 'roles.manage',
            ],
        ],
    ],
];
