<?php

/*
|--------------------------------------------------------------------------
| Settings hub registry (docs/specs/settings-spec.md)
|--------------------------------------------------------------------------
| One list of every section and field on the Settings page of the church,
| region and diocese. App\Support\Settings\SettingsRegistry reads it; the
| rail, the forms, validation and permissions all come from here.
|
| sections: key => [
|   label, icon (Remix), colour (one of the sidebar's six: primary,
|   secondary, success, danger, purple, pink), group (a key of 'groups', or
|   null for the top of the rail), levels (church|region|diocese), kind
|   (custom: its own screen | form: generic fields | link: an existing page
|   shown inside the hub), sentence (one plain line under the title),
|   url (link kind: path per level, relative to the site root)
| ]
|
| fields: key => [
|   section, card, label, type (text|email|tel|url|number|textarea|select|
|   switch|time|secret), rules (Laravel), default, levels (where it can be
|   edited: church|region|diocese|system), lockable, secret, help, used_by
|   (the code that reads it - every field ships with its reader), span (6|12),
|   options (select: value => label)
| ]
|
| A field ships in the same PR as the code that reads it, so this list
| grows phase by phase.
*/

return [

    'groups' => [
        'our-place' => 'Our place',
        'money' => 'Money',
        'messages' => 'Messages',
        'ministry' => 'Ministry',
        'system' => 'System',
    ],

    'sections' => [
        'overview' => [
            'label' => 'Overview',
            'icon' => 'ri-dashboard-3-line',
            'colour' => 'primary',
            'group' => null,
            'levels' => ['church', 'region', 'diocese'],
            'kind' => 'custom',
            'sentence' => 'How complete your details are, and what still needs doing.',
        ],
        'profile' => [
            'label' => 'Profile',
            'icon' => 'ri-community-line',
            'colour' => 'purple',
            'group' => 'our-place',
            'levels' => ['church', 'region', 'diocese'],
            'kind' => 'custom',
            'sentence' => 'Who you are, how to reach you and where to find you.',
        ],
        'servicetimes' => [
            'label' => 'Service times',
            'icon' => 'ri-time-line',
            'colour' => 'success',
            'group' => 'our-place',
            'levels' => ['church', 'diocese'],
            'kind' => 'custom',
            'sentence' => 'When you meet, so visitors and the diocese know.',
        ],
        'team' => [
            'label' => 'Leadership & team',
            'icon' => 'ri-team-line',
            'colour' => 'pink',
            'group' => 'our-place',
            'levels' => ['church', 'region', 'diocese'],
            'kind' => 'custom',
            'actions' => ['read', 'manage'],
            'sentence' => 'The people who serve here, and what each one can do in the system.',
        ],

        // Existing settings pages, shown inside the hub (S3). 'permission' is
        // the page's own read permission ("{level}." is added); 'absorbs' is
        // the page's menu row, which SettingsHubSeeder moves under Settings.
        'budgets' => [
            'label' => 'Budgets',
            'icon' => 'ri-money-dollar-circle-line',
            'colour' => 'secondary',
            'group' => 'money',
            'levels' => ['church', 'region', 'diocese'],
            'kind' => 'link',
            'actions' => [],
            'permission' => 'settings.budgetsettings.read',
            'url' => [
                'church' => '/church/settings/budget-settings/',
                'region' => '/region/settings/budget-settings/',
                'diocese' => '/diocese/settings/budget-settings/',
            ],
            'absorbs' => [
                'church' => '/church/settings/budget-settings/index.php',
                'region' => '/region/settings/budget-settings/index.php',
                'diocese' => '/diocese/settings/budget-settings/index.php',
            ],
            'sentence' => 'The money in and money out lines every budget is built from, and the shares worked out from money in.',
        ],
        'attendance' => [
            'label' => 'Gathering types',
            'icon' => 'ri-calendar-check-line',
            'colour' => 'primary',
            'group' => 'ministry',
            'levels' => ['church'],
            'kind' => 'link',
            'actions' => [],
            'permission' => 'settings.attendancesettings.gatheringtypes.read',
            'url' => ['church' => '/church/settings/attendance-settings/gathering-types'],
            'absorbs' => ['church' => '/church/settings/attendance-settings/gathering-types.php'],
            'sentence' => 'The services, ministry meetings and special events your attendance is recorded against.',
        ],
        'demographics' => [
            'label' => 'Recording cadence',
            'icon' => 'ri-line-chart-line',
            'colour' => 'purple',
            'group' => 'ministry',
            'levels' => ['church'],
            'kind' => 'link',
            'actions' => [],
            'permission' => 'settings.demographicssettings.recordingcadence.read',
            'url' => ['church' => '/church/settings/demographics-settings/recording-cadence'],
            'absorbs' => ['church' => '/church/settings/demographics-settings/recording-cadence.php'],
            'sentence' => 'How often you record your demographics: monthly, half-yearly or yearly.',
        ],
    ],

    'fields' => [
        // Added phase by phase with the code that reads them (S4, S5).
    ],

];
