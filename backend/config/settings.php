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

    // One line per role for the Add someone window (S6a) - most roles have no
    // description in the roles table. A role's own description wins.
    'role_blurbs' => [
        'Church Administrator' => 'Runs the church office and can manage its settings and team.',
        'Associate Pastor' => 'Shares pastoral work with the Senior Pastor.',
        'Youth Pastor' => 'Leads the youth ministry.',
        'Church Secretary' => 'Keeps the church\'s records, attendance and minutes.',
        'Church Treasurer' => 'Looks after the church\'s money and budgets.',
        'Elder' => 'A church elder, supporting the pastors.',
        'Deacon' => 'Serves the church in practical ministry.',
        'Church Committee Member' => 'Sits on the church committee.',
        'Youth Leader' => 'Leads youth activities.',
        "Women's Ministry Leader" => 'Leads the women\'s ministry.',
        "Men's Ministry Leader" => 'Leads the men\'s ministry.',
        "Children's Ministry Leader" => 'Leads the children\'s ministry and Sunday school.',
        'Music Director' => 'Leads the music ministry.',
        'Worship Leader' => 'Leads worship in services.',
        'Choir Director' => 'Leads the choir.',
        'Sunday School Teacher' => 'Teaches Sunday school.',
        'Usher Coordinator' => 'Coordinates the ushers.',
        'Prayer Group Leader' => 'Leads a prayer group.',
        'Regional Secretary' => 'Keeps the region\'s records and minutes.',
        'Regional Treasurer' => 'Looks after the region\'s money and its payment details.',
        'Regional Coordinator' => 'Coordinates the region\'s programmes.',
        'Regional Committee Member' => 'Sits on the regional committee.',
        'Diocese Administrator' => 'Runs the diocese office and can manage its settings and team.',
        'Diocese Secretary' => 'Keeps the diocese\'s records and minutes.',
        'Diocese Treasurer' => 'Looks after the diocese\'s money and its payment details.',
        'Diocese Finance Officer' => 'Handles the diocese\'s finance work.',
        'Diocese Council Member' => 'Sits on the diocese council.',
    ],

    'groups' => [
        'our-place' => 'Our place',
        'money' => 'Money',
        'messages' => 'Messages',
        'ministry' => 'Ministry',
        'people' => 'People & care', // docs/specs/people-and-care-spec.md - sections arrive with each phase
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

        'security' => [
            'label' => 'Security',
            'icon' => 'ri-shield-keyhole-line',
            'colour' => 'danger',
            'group' => 'system',
            'levels' => ['diocese'],
            'kind' => 'form',
            'actions' => [],
            'global_only' => true,
            'sentence' => 'How sign-in is protected: PIN tries, password rules and how long people stay signed in.',
        ],
        'documents' => [
            'label' => 'Documents & PDF',
            'icon' => 'ri-file-pdf-2-line',
            'colour' => 'secondary',
            'group' => 'system',
            'levels' => ['diocese'],
            'kind' => 'form',
            'actions' => [],
            'global_only' => true,
            'sentence' => 'What every report PDF says at the top and bottom, and how long report files are kept.',
        ],
        'maintenance' => [
            'label' => 'Maintenance',
            'icon' => 'ri-tools-line',
            'colour' => 'purple',
            'group' => 'system',
            'levels' => ['diocese'],
            'kind' => 'form',
            'actions' => [],
            'global_only' => true,
            'tools' => ['clear-cache', 'prune-reports', 'forget-failed'],
            'sentence' => 'A notice everyone sees at the top of every page, and housekeeping tools.',
        ],
        'audit' => [
            'label' => 'Audit log',
            'icon' => 'ri-history-line',
            'colour' => 'pink',
            'group' => 'system',
            'levels' => ['diocese'],
            'kind' => 'custom',
            'actions' => [],
            'global_only' => true,
            'sentence' => 'Every settings change at every church, region and the diocese: who, when and what.',
        ],
        // The access-control pages (moved here from "Diocese Settings > System
        // Administration"). Anyone who can open one of them sees this section.
        'access' => [
            'label' => 'Access control',
            'icon' => 'ri-shield-user-line',
            'colour' => 'primary',
            'group' => 'system',
            'levels' => ['diocese'],
            'kind' => 'custom',
            'actions' => [],
            'absorbs' => ['diocese' => '/diocese/settings/admin'],
            'links' => [
                'users' => ['label' => 'Users', 'icon' => 'ri-user-settings-line', 'colour' => 'primary', 'url' => 'diocese/settings/admin/users', 'permission' => 'diocesesettings.systemadministration.usermanagement.read', 'sentence' => 'Everyone with a login, their roles and where they serve.'],
                'roles' => ['label' => 'Roles', 'icon' => 'ri-shield-star-line', 'colour' => 'purple', 'url' => 'diocese/settings/admin/role-management', 'permission' => 'diocesesettings.systemadministration.rolemanagement.read', 'sentence' => 'What each role can see and do.'],
                'permissions' => ['label' => 'Permissions', 'icon' => 'ri-key-2-line', 'colour' => 'success', 'url' => 'diocese/settings/admin/permissions', 'permission' => 'diocesesettings.systemadministration.permissions.read', 'sentence' => 'Every permission and the roles that hold it.'],
                'modules' => ['label' => 'Modules', 'icon' => 'ri-apps-2-line', 'colour' => 'pink', 'url' => 'diocese/settings/admin/modules', 'permission' => 'diocesesettings.systemadministration.modules.read', 'sentence' => 'The pages on the menu, and which are switched on.'],
                'groups' => ['label' => 'Module groups', 'icon' => 'ri-folders-line', 'colour' => 'secondary', 'url' => 'diocese/settings/admin/module-groups', 'permission' => 'diocesesettings.systemadministration.modulegroups.read', 'sentence' => 'How the menu is grouped at each level.'],
            ],
            'sentence' => 'Who can sign in and what each role can see and do.',
        ],

        // S6b - how a church's or region's own messages go out: through the
        // diocese's Email and SMS (view only, with its own name, reply-to and
        // signature) or its own account. Read by App\Services\Messaging\PlaceMessenger.
        'communication' => [
            'label' => 'Communication',
            'icon' => 'ri-chat-settings-line',
            'colour' => 'purple',
            'group' => 'messages',
            'levels' => ['church', 'region', 'diocese'],
            'kind' => 'form',
            'test' => 'place',
            'extra' => 'communication',
            'sentence' => 'How your emails and SMS go out - through the diocese, or your own account - and what they look like.',
        ],

        // People & care P3 - pastoral care. Read by CareController.
        'pastoral' => [
            'label' => 'Pastoral care',
            'icon' => 'ri-heart-pulse-line',
            'colour' => 'danger',
            'group' => 'people',
            'levels' => ['church'],
            'kind' => 'form',
            'sentence' => 'Which kinds of care you record, and when someone counts as not visited.',
        ],

        // People & care P2 - visitors and their follow-up. Read by VisitorsController.
        'visitors' => [
            'label' => 'Visitors',
            'icon' => 'ri-user-heart-line',
            'colour' => 'pink',
            'group' => 'people',
            'levels' => ['church'],
            'kind' => 'form',
            'sentence' => 'How soon to follow up, the welcome SMS, and how long visitors\' details are kept.',
        ],

        // S5 - how to pay a region or the diocese. Read by Contributions ("How to
        // send it", for the places below). Churches get theirs with the first
        // page that shows a church's payment details.
        'finance' => [
            'label' => 'Payment details',
            'icon' => 'ri-bank-card-line',
            'colour' => 'success',
            'group' => 'money',
            'levels' => ['region', 'diocese'],
            'kind' => 'form',
            'grants' => ['update' => ['region' => ['Regional Treasurer'], 'diocese' => ['Diocese Treasurer']]],
            'sentence' => 'Where the churches below send their share: M-Pesa and bank details, shown on their Contributions page.',
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
        'reports' => [
            'label' => 'Monthly reports',
            'icon' => 'ri-file-chart-line',
            'colour' => 'pink',
            'group' => 'ministry',
            'levels' => ['diocese'],
            'kind' => 'form',
            'sentence' => 'When each church and region sends its monthly report.',
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
        'sms.driver' => ['section' => 'sms', 'card' => 'Gateway', 'label' => 'Send SMS with', 'type' => 'select', 'options' => ['log' => "Don't send - write to the log", 'textsms' => 'TextSMS (set up on the server)', 'africastalking' => "Africa's Talking"], 'default' => env('SMS_PROVIDER') === 'textsms' ? 'textsms' : 'log', 'levels' => ['diocese'], 'used_by' => 'Every SMS', 'help' => 'TextSMS uses the account in the server\'s settings (.env) - nothing to fill in here.'],
        'sms.username' => ['section' => 'sms', 'card' => 'Gateway', 'label' => "Africa's Talking username", 'rules' => ['nullable', 'string', 'max:100'], 'default' => null, 'levels' => ['diocese'], 'help' => "'sandbox' for testing"],
        'sms.api_key' => ['section' => 'sms', 'card' => 'Gateway', 'label' => 'API key', 'type' => 'secret', 'secret' => true, 'rules' => ['nullable', 'string', 'max:200'], 'default' => null, 'levels' => ['diocese']],
        'sms.sandbox' => ['section' => 'sms', 'card' => 'Gateway', 'label' => 'Use the sandbox (no real messages)', 'type' => 'switch', 'default' => false, 'levels' => ['diocese']],
        'sms.sender_id' => ['section' => 'sms', 'card' => 'Who SMS comes from', 'label' => 'Sender ID', 'rules' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ]*$/'], 'default' => null, 'levels' => ['diocese'], 'help' => 'Up to 11 letters or digits, approved by your provider. Leave empty for their default.', 'used_by' => 'Every SMS - churches send under it'],

        // ---- Members (People & care P1) - read by PeopleController (the form's
        // required fields) and the Insights page's "Send birthday SMS".
        'pastoral.not_contacted_days' => ['section' => 'pastoral', 'card' => 'Who needs care', 'label' => 'A member is "not contacted" after (days)', 'type' => 'number', 'rules' => ['required', 'integer', 'between:14,365'], 'default' => 60, 'levels' => ['church'], 'help' => 'Members with no care recorded for this long show in Needs care.', 'used_by' => 'Pastoral care > Needs care'],
        'pastoral.hospital_days' => ['section' => 'pastoral', 'card' => 'Who needs care', 'label' => 'Visit someone in hospital every (days)', 'type' => 'number', 'rules' => ['required', 'integer', 'between:1,30'], 'default' => 7, 'levels' => ['church'], 'help' => 'After this long without a visit, they show in red.', 'used_by' => 'Pastoral care > Hospital'],
        'pastoral.type.home_visit' => ['section' => 'pastoral', 'card' => 'Kinds of care we record', 'label' => 'Home visits', 'type' => 'switch', 'default' => true, 'levels' => ['church'], 'span' => 6, 'used_by' => 'Pastoral care > Record care'],
        'pastoral.type.hospital' => ['section' => 'pastoral', 'card' => 'Kinds of care we record', 'label' => 'Hospital', 'type' => 'switch', 'default' => true, 'levels' => ['church'], 'span' => 6, 'used_by' => 'Pastoral care > Record care'],
        'pastoral.type.counselling' => ['section' => 'pastoral', 'card' => 'Kinds of care we record', 'label' => 'Counselling', 'type' => 'switch', 'default' => true, 'levels' => ['church'], 'span' => 6, 'used_by' => 'Pastoral care > Record care'],
        'pastoral.type.prayer' => ['section' => 'pastoral', 'card' => 'Kinds of care we record', 'label' => 'Prayer', 'type' => 'switch', 'default' => true, 'levels' => ['church'], 'span' => 6, 'used_by' => 'Pastoral care > Record care'],
        'pastoral.type.phone_call' => ['section' => 'pastoral', 'card' => 'Kinds of care we record', 'label' => 'Phone calls', 'type' => 'switch', 'default' => true, 'levels' => ['church'], 'span' => 6, 'used_by' => 'Pastoral care > Record care'],
        'pastoral.type.bereavement' => ['section' => 'pastoral', 'card' => 'Kinds of care we record', 'label' => 'Bereavement', 'type' => 'switch', 'default' => true, 'levels' => ['church'], 'span' => 6, 'used_by' => 'Pastoral care > Record care'],
        'pastoral.type.concern' => ['section' => 'pastoral', 'card' => 'Kinds of care we record', 'label' => 'Concerns', 'type' => 'switch', 'default' => true, 'levels' => ['church'], 'span' => 6, 'used_by' => 'Pastoral care > Record care'],
        'visitors.followup_days' => ['section' => 'visitors', 'card' => 'Follow-up', 'label' => 'Follow up within (days)', 'type' => 'number', 'rules' => ['required', 'integer', 'between:1,30'], 'default' => 3, 'levels' => ['church'], 'help' => 'After a visit, someone is due to call or visit within this many days.', 'used_by' => 'Visitors > My follow-ups, the calendar'],
        'visitors.welcome_sms' => ['section' => 'visitors', 'card' => 'Welcome SMS', 'label' => 'Send the welcome SMS by default', 'type' => 'switch', 'default' => false, 'levels' => ['church'], 'help' => 'Only to first-timers with a phone who haven\'t asked not to be texted. It can be switched off each Sunday.', 'used_by' => 'Visitors > Record visitors'],
        'visitors.welcome_template' => ['section' => 'visitors', 'card' => 'Welcome SMS', 'label' => 'Welcome message', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:300'], 'default' => 'Thank you for worshipping with us at {church}, {first_name}! You are welcome back any time.', 'levels' => ['church'], 'span' => 12, 'help' => '{first_name} and {church} are filled in for each person.', 'used_by' => 'Visitors > Record visitors'],
        'people.retention_visitor_months' => ['section' => 'visitors', 'card' => 'Keeping visitors\' details', 'label' => 'Remove a visitor\'s details after', 'type' => 'select', 'options' => ['0' => 'Never', '6' => '6 months without a visit', '12' => '12 months without a visit', '24' => '2 years without a visit'], 'rules' => ['required'], 'default' => '0', 'levels' => ['church', 'diocese'], 'lockable' => true, 'help' => 'Names, phone numbers and areas are cleared; the counts stay. Members are never touched.', 'used_by' => 'A nightly clean-up'],

        // Communication (S6b). comms.mode flows down and can be locked; everything
        // else is the place's own ('inherits' => false) - a church never sends
        // with another place's account.
        'comms.mode' => ['section' => 'communication', 'card' => 'How messages are sent', 'label' => 'Send our email and SMS through', 'type' => 'select', 'options' => ['diocese' => "The diocese's email and SMS", 'own' => 'Our own email server and SMS account'], 'rules' => ['required'], 'default' => 'diocese', 'levels' => ['church', 'region', 'diocese'], 'lockable' => true, 'span' => 12, 'help' => 'Your own email or SMS is used once it\'s filled in below; until then that one still goes through the diocese\'s. The diocese, or a region, can lock this.', 'used_by' => 'Every email and SMS sent for this place'],
        'comms.display_name' => ['section' => 'communication', 'card' => 'How our messages look', 'label' => 'Name messages come from', 'rules' => ['nullable', 'string', 'max:80'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'help' => 'Leave empty to use your place\'s name.'],
        'comms.reply_to' => ['section' => 'communication', 'card' => 'How our messages look', 'label' => 'Replies go to', 'type' => 'email', 'rules' => ['nullable', 'email', 'max:255'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'help' => 'An email you read, e.g. the church office.'],
        'comms.sms_signature' => ['section' => 'communication', 'card' => 'How our messages look', 'label' => 'SMS signature', 'rules' => ['nullable', 'string', 'max:30'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'span' => 12, 'help' => 'Added to the end of each SMS, e.g. "CCI Sultan Hamud". Up to 30 characters.'],
        'comms.mail.host' => ['section' => 'communication', 'card' => 'Our own email server (SMTP)', 'label' => 'Server', 'rules' => ['nullable', 'string', 'max:255', 'public_mail_host'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own'], 'help' => 'e.g. mail.yourchurch.or.ke or smtp.gmail.com'],
        'comms.mail.port' => ['section' => 'communication', 'card' => 'Our own email server (SMTP)', 'label' => 'Port', 'type' => 'select', 'options' => ['587' => '587 (STARTTLS - most common)', '465' => '465 (SSL)', '2525' => '2525', '25' => '25'], 'default' => '587', 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own']],
        'comms.mail.scheme' => ['section' => 'communication', 'card' => 'Our own email server (SMTP)', 'label' => 'Security', 'type' => 'select', 'options' => ['' => 'Automatic (STARTTLS)', 'smtps' => 'SSL (port 465)'], 'default' => '', 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own']],
        'comms.mail.username' => ['section' => 'communication', 'card' => 'Our own email server (SMTP)', 'label' => 'Username', 'rules' => ['nullable', 'string', 'max:255'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own']],
        'comms.mail.password' => ['section' => 'communication', 'card' => 'Our own email server (SMTP)', 'label' => 'Password', 'type' => 'secret', 'secret' => true, 'rules' => ['nullable', 'string', 'max:255'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own']],
        'comms.mail.from_address' => ['section' => 'communication', 'card' => 'Our own email server (SMTP)', 'label' => 'Send from (email)', 'type' => 'email', 'rules' => ['nullable', 'email', 'max:255'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own']],
        'comms.sms.username' => ['section' => 'communication', 'card' => "Our own SMS (Africa's Talking)", 'label' => "Africa's Talking username", 'rules' => ['nullable', 'string', 'max:100'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own']],
        'comms.sms.api_key' => ['section' => 'communication', 'card' => "Our own SMS (Africa's Talking)", 'label' => 'API key', 'type' => 'secret', 'secret' => true, 'rules' => ['nullable', 'string', 'max:200'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own']],
        'comms.sms.sender_id' => ['section' => 'communication', 'card' => "Our own SMS (Africa's Talking)", 'label' => 'Sender ID', 'rules' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ]*$/'], 'default' => null, 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own'], 'help' => 'Up to 11 letters or digits, approved by Africa\'s Talking. Leave empty for their default.'],
        'comms.sms.sandbox' => ['section' => 'communication', 'card' => "Our own SMS (Africa's Talking)", 'label' => 'Use the sandbox (no real messages)', 'type' => 'switch', 'default' => false, 'levels' => ['church', 'region'], 'inherits' => false, 'show_if' => ['comms.mode' => 'own']],

        // Payment details (S5) - each place's own: never inherited ('inherits' => false),
        // so a region without details never shows the diocese's paybill as its own.
        'finance.mpesa_type' => ['section' => 'finance', 'card' => 'M-Pesa', 'label' => 'How to pay by M-Pesa', 'type' => 'select', 'options' => ['' => 'Not by M-Pesa', 'paybill' => 'Paybill', 'till' => 'Till (Buy Goods)'], 'default' => '', 'levels' => ['region', 'diocese'], 'inherits' => false, 'used_by' => 'Contributions - "How to send it" for the churches below'],
        'finance.mpesa_number' => ['section' => 'finance', 'card' => 'M-Pesa', 'label' => 'Paybill or till number', 'rules' => ['nullable', 'regex:/^\d{5,7}$/'], 'default' => null, 'levels' => ['region', 'diocese'], 'inherits' => false, 'help' => '5 to 7 digits.'],
        'finance.mpesa_account' => ['section' => 'finance', 'card' => 'M-Pesa', 'label' => 'Account number to quote', 'rules' => ['nullable', 'string', 'max:40'], 'default' => null, 'levels' => ['region', 'diocese'], 'inherits' => false, 'span' => 12, 'help' => 'Paybill only. Write {code} where the sending church\'s code goes, e.g. "{code}-SHARE".'],
        'finance.bank_name' => ['section' => 'finance', 'card' => 'Bank', 'label' => 'Bank', 'rules' => ['nullable', 'string', 'max:80'], 'default' => null, 'levels' => ['region', 'diocese'], 'inherits' => false, 'help' => 'e.g. KCB, Equity, Co-operative Bank'],
        'finance.bank_branch' => ['section' => 'finance', 'card' => 'Bank', 'label' => 'Branch', 'rules' => ['nullable', 'string', 'max:80'], 'default' => null, 'levels' => ['region', 'diocese'], 'inherits' => false],
        'finance.bank_account_name' => ['section' => 'finance', 'card' => 'Bank', 'label' => 'Account name', 'rules' => ['nullable', 'string', 'max:120'], 'default' => null, 'levels' => ['region', 'diocese'], 'inherits' => false],
        'finance.bank_account_number' => ['section' => 'finance', 'card' => 'Bank', 'label' => 'Account number', 'rules' => ['nullable', 'regex:/^[0-9][0-9 -]{4,29}$/'], 'default' => null, 'levels' => ['region', 'diocese'], 'inherits' => false],
        'finance.payment_note' => ['section' => 'finance', 'card' => 'For whoever sends money', 'label' => 'Anything else they should know', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:300'], 'default' => null, 'levels' => ['region', 'diocese'], 'inherits' => false, 'span' => 12, 'help' => 'e.g. "Send the share by the 5th of the next month, and record it in Money in and out."'],

        // Security (S4b) - read by User::verifyPin, the password rules in AuthController and Sanctum.
        'security.pin_attempts' => ['section' => 'security', 'card' => 'Employee code + PIN', 'label' => 'Wrong PINs before it locks', 'type' => 'number', 'rules' => ['required', 'integer', 'between:1,10'], 'default' => 3, 'levels' => ['diocese'], 'used_by' => 'Sign-in with employee code and PIN'],
        'security.pin_lock_minutes' => ['section' => 'security', 'card' => 'Employee code + PIN', 'label' => 'Minutes it stays locked', 'type' => 'number', 'rules' => ['required', 'integer', 'between:1,1440'], 'default' => 15, 'levels' => ['diocese'], 'help' => 'Password sign-in keeps working while the PIN is locked.'],
        'security.password_min' => ['section' => 'security', 'card' => 'Passwords', 'label' => 'Shortest password allowed', 'type' => 'number', 'rules' => ['required', 'integer', 'between:8,64'], 'default' => 8, 'levels' => ['diocese'], 'help' => 'Characters. Applies the next time someone sets a password.', 'used_by' => 'Changing a password (first sign-in, profile, users)'],
        'security.password_months' => ['section' => 'security', 'card' => 'Passwords', 'label' => 'Ask for a new password every', 'type' => 'select', 'options' => ['0' => 'Never', '3' => '3 months', '6' => '6 months', '12' => '12 months'], 'rules' => ['required'], 'default' => '6', 'levels' => ['diocese'], 'used_by' => 'Changing a password'],
        'security.session_hours' => ['section' => 'security', 'card' => 'Staying signed in', 'label' => 'Sign people out after', 'type' => 'select', 'options' => ['0' => 'Never - until they sign out', '8' => '8 hours', '24' => '1 day', '168' => '7 days', '720' => '30 days'], 'rules' => ['required'], 'default' => '0', 'levels' => ['diocese'], 'config' => 'sanctum.expiration', 'config_scale' => 60, 'help' => 'Counted from when they signed in. Applies to sign-ins from now on as well as existing ones.', 'used_by' => 'Every signed-in page'],

        // Documents & PDF (S4b) - read by DioceseReportPdf and ReportGenerator.
        'documents.org_name' => ['section' => 'documents', 'card' => 'Top of every page', 'label' => 'Name', 'rules' => ['required', 'string', 'max:80'], 'default' => 'Makueni West Diocese', 'levels' => ['diocese'], 'used_by' => 'Every report PDF (header and footer)'],
        'documents.org_subtitle' => ['section' => 'documents', 'card' => 'Top of every page', 'label' => 'Line under the name', 'rules' => ['nullable', 'string', 'max:80'], 'default' => 'Christian Church International', 'levels' => ['diocese']],
        'documents.footer_note' => ['section' => 'documents', 'card' => 'Bottom of every page', 'label' => 'Footer note', 'rules' => ['nullable', 'string', 'max:120'], 'default' => 'Computer-generated from the diocese system - no signature needed.', 'levels' => ['diocese'], 'span' => 12, 'help' => 'Shown under the verification code and who generated it.'],
        'documents.keep_days' => ['section' => 'documents', 'card' => 'Report files', 'label' => 'Keep report files for', 'type' => 'select', 'options' => ['1' => '1 day', '7' => '7 days', '30' => '30 days', '90' => '90 days'], 'rules' => ['required'], 'default' => '7', 'levels' => ['diocese'], 'help' => 'After this the file is removed, but a printed copy can still be verified.', 'used_by' => 'Reports (download link)'],

        // Monthly reports (L4) - read by MonthlyReports::dueOn() and the reminders.
        'reports.monthly_due_day' => ['section' => 'reports', 'card' => 'When reports are due', 'label' => 'Due on this day of the next month', 'type' => 'number', 'rules' => ['required', 'integer', 'between:1,28'], 'default' => 5, 'levels' => ['diocese'], 'help' => 'e.g. 5 means April\'s report is due on 5 May. Reminders go 3 days before, on the day, and 3 days after.', 'used_by' => 'Monthly reports, the calendar and the reminders'],

        // Maintenance (S4b) - the notice banner on every page (GET /settings/notice).
        'maintenance.notice' => ['section' => 'maintenance', 'card' => 'Notice for everyone', 'label' => 'Message', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:300'], 'default' => null, 'levels' => ['diocese'], 'span' => 12, 'help' => 'e.g. "The system will be down for updates on Saturday from 6 to 8 am." Leave empty for no notice.', 'used_by' => 'A banner at the top of every page'],
        'maintenance.notice_tone' => ['section' => 'maintenance', 'card' => 'Notice for everyone', 'label' => 'Colour', 'type' => 'select', 'options' => ['primary' => 'Blue - for information', 'warning' => 'Gold - plan around it', 'danger' => 'Red - urgent'], 'rules' => ['required'], 'default' => 'warning', 'levels' => ['diocese']],
    ],

];
