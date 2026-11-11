<?php

return [
    [
        'title' => 'Main Menu',
        'items' => [
            [
                'label' => 'Dashboard',
                'icon' => 'ti ti-layout-dashboard',
                'route' => 'dashboard',
                'active' => 'dashboard',
                'permission' => 'dashboard.view',
            ],
            [
                'label' => 'Events',
                'icon' => 'ti ti-calendar-event',
                'route' => 'events.index',
                'active' => 'events.*',
                'permission' => 'events.view',
            ],
            [
                'label' => 'Registrations',
                'icon' => 'ti ti-ticket',
                'route' => 'registrations.index',
                'active' => 'registrations.*',
                'permission' => 'registrations.view',
            ],
            [
                'label' => 'QR Scanner',
                'icon' => 'ti ti-qrcode',
                'route' => 'attendance.scanner',
                'active' => 'attendance.scanner',
                'permission' => 'attendance.scan',
            ],
            [
                'label' => 'Attendance',
                'icon' => 'ti ti-clipboard-check',
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
                'icon' => 'ti ti-building',
                'route' => 'departments.index',
                'active' => 'departments.*',
                'permission' => 'departments.view',
            ],
            [
                'label' => 'Employees',
                'icon' => 'ti ti-users',
                'route' => 'employees.index',
                'active' => 'employees.*',
                'permission' => 'employees.view',
            ],
            [
                'label' => 'External Participants',
                'icon' => 'ti ti-user-plus',
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
                'icon' => 'ti ti-chart-bar',
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
                'icon' => 'ti ti-user-cog',
                'route' => 'settings.users.index',
                'active' => 'settings.users.*',
                'permission' => 'users.manage',
            ],
            [
                'label' => 'Role Management',
                'icon' => 'ti ti-shield-lock',
                'route' => 'settings.roles.index',
                'active' => 'settings.roles.*',
                'permission' => 'roles.manage',
            ],
        ],
    ],
];
