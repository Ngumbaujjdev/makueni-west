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

        // The diocese's system settings (S4) - global admins only.
        'health' => [
            'label' => 'System health',
            'icon' => 'ri-pulse-line',
            'colour' => 'success',
            'group' => 'system',
            'levels' => ['diocese'],
            'kind' => 'custom',
            'actions' => [],
            'global_only' => true,
            'sentence' => 'Whether email, SMS, background jobs and the scheduler are working right now.',
        ],
        'email' => [
            'label' => 'Email',
            'icon' => 'ri-mail-send-line',
            'colour' => 'primary',
            'group' => 'messages',
            'levels' => ['diocese'],
            'kind' => 'form',
            'actions' => [],
            'global_only' => true,
            'test' => 'email',
            'sentence' => 'The mail server every email goes out through - saved here, it takes over from the server file.',
        ],
        'sms' => [
            'label' => 'SMS',
            'icon' => 'ri-message-3-line',
            'colour' => 'pink',
            'group' => 'messages',
            'levels' => ['diocese'],
            'kind' => 'form',
            'actions' => [],
            'global_only' => true,
            'test' => 'sms',
            'sentence' => "The SMS gateway (Africa's Talking). Until it's set up, messages are only written to the log.",
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
        // 'config' = the Laravel config key it takes over at runtime
        // (Settings::applyToConfig); secrets are stored encrypted.

        // ---- Email (S4) - read by Settings::applyToConfig -> every Mailable
        'mail.mailer' => ['section' => 'email', 'card' => 'How email is sent', 'label' => 'Send email with', 'type' => 'select', 'options' => ['smtp' => 'A mail server (SMTP)', 'log' => "Don't send - write to the log"], 'default' => env('MAIL_MAILER', 'log') === 'smtp' ? 'smtp' : 'log', 'levels' => ['diocese'], 'config' => 'mail.default', 'used_by' => 'Every email (password resets, reports, support)'],
        'mail.host' => ['section' => 'email', 'card' => 'How email is sent', 'label' => 'Server', 'rules' => ['nullable', 'string', 'max:255'], 'default' => env('MAIL_HOST'), 'levels' => ['diocese'], 'config' => 'mail.mailers.smtp.host', 'help' => 'e.g. smtp.office365.com'],
        'mail.port' => ['section' => 'email', 'card' => 'How email is sent', 'label' => 'Port', 'type' => 'number', 'rules' => ['nullable', 'integer', 'between:1,65535'], 'default' => env('MAIL_PORT') ? (int) env('MAIL_PORT') : 587, 'levels' => ['diocese'], 'config' => 'mail.mailers.smtp.port', 'span' => 6],
        'mail.scheme' => ['section' => 'email', 'card' => 'How email is sent', 'label' => 'Security', 'type' => 'select', 'options' => ['' => 'Automatic (STARTTLS)', 'smtps' => 'SSL (port 465)'], 'default' => env('MAIL_SCHEME') ?: '', 'levels' => ['diocese'], 'config' => 'mail.mailers.smtp.scheme', 'span' => 6],
        'mail.username' => ['section' => 'email', 'card' => 'Signing in to the server', 'label' => 'Username', 'rules' => ['nullable', 'string', 'max:255'], 'default' => env('MAIL_USERNAME'), 'levels' => ['diocese'], 'config' => 'mail.mailers.smtp.username'],
        'mail.password' => ['section' => 'email', 'card' => 'Signing in to the server', 'label' => 'Password', 'type' => 'secret', 'secret' => true, 'rules' => ['nullable', 'string', 'max:255'], 'default' => env('MAIL_PASSWORD'), 'levels' => ['diocese'], 'config' => 'mail.mailers.smtp.password'],
        'mail.from_address' => ['section' => 'email', 'card' => 'Who email comes from', 'label' => 'From address', 'type' => 'email', 'rules' => ['nullable', 'email', 'max:255'], 'default' => env('MAIL_FROM_ADDRESS'), 'levels' => ['diocese'], 'config' => 'mail.from.address'],
        'mail.from_name' => ['section' => 'email', 'card' => 'Who email comes from', 'label' => 'From name', 'rules' => ['nullable', 'string', 'max:120'], 'default' => env('MAIL_FROM_NAME', 'Makueni West Diocese'), 'levels' => ['diocese'], 'config' => 'mail.from.name'],

        // ---- SMS (S4) - read by App\Services\Sms\Sms
        'sms.driver' => ['section' => 'sms', 'card' => 'Gateway', 'label' => 'Send SMS with', 'type' => 'select', 'options' => ['log' => "Don't send - write to the log", 'africastalking' => "Africa's Talking"], 'default' => 'log', 'levels' => ['diocese'], 'used_by' => 'Every SMS'],
        'sms.username' => ['section' => 'sms', 'card' => 'Gateway', 'label' => "Africa's Talking username", 'rules' => ['nullable', 'string', 'max:100'], 'default' => null, 'levels' => ['diocese'], 'help' => "'sandbox' for testing"],
        'sms.api_key' => ['section' => 'sms', 'card' => 'Gateway', 'label' => 'API key', 'type' => 'secret', 'secret' => true, 'rules' => ['nullable', 'string', 'max:200'], 'default' => null, 'levels' => ['diocese']],
        'sms.sandbox' => ['section' => 'sms', 'card' => 'Gateway', 'label' => 'Use the sandbox (no real messages)', 'type' => 'switch', 'default' => false, 'levels' => ['diocese']],
        'sms.sender_id' => ['section' => 'sms', 'card' => 'Who SMS comes from', 'label' => 'Sender ID', 'rules' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ]*$/'], 'default' => null, 'levels' => ['diocese'], 'help' => 'Up to 11 letters or digits, approved by your provider. Leave empty for their default.', 'used_by' => 'Every SMS - churches send under it'],
    ],

];
