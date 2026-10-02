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
    ],

    'fields' => [
        // Added phase by phase with the code that reads them (S4, S5).
    ],

];
