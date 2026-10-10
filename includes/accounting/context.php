<?php
/**
 * Accounting (docs/specs/accounting-spec.md) - one set of pages for every
 * level: the bodies live in includes/accounting/body-*.php and
 * {church,region,diocese}/accounting/*.php are thin wrappers. What the API
 * allows is decided server-side (App\Support\AccountingAccess); this only
 * checks the page may open and tells the scripts where they are.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/**
 * @param string $level church | region | diocese
 * @param string $page  index | accounts | cashbook | receipts | payments | journals | documents | chart | reconciliation | reconcile | close | record | ...
 */
function accountingPageContext(string $level, string $page): array
{
    // A record's page: anyone who reads the books, asks for money or approves (the API checks the record itself).
    $recordReader = $page === 'record' && (hasGlobalAccess() || hasPermission("{$level}.accounting.books.read") || hasPermission("{$level}.accounting.requisitions.create") || hasPermission("{$level}.accounting.approvals.read"));
    $recordReader || requirePermission("{$level}.accounting." . ([
        'accounts', 'account' => 'accounts.read',
        'cashbook' => 'cashbook.read',
        'receipts' => 'receipts.create',
        'payments' => 'payments.read',
        'journals' => 'journals.post',
        'documents' => 'documents.read',
        'chart' => 'chart.manage',
        'reconciliation', 'reconcile' => 'reconciliation.read',
        'close' => 'periods.read',
        'collections' => 'collections.read',
        'approvals' => 'approvals.read',
        'requisitions' => 'requisitions.create',
        'approval-rules' => 'approvalrules.manage',
        'procurement' => 'procurement.read',
        'remittances' => 'remittances.read',
        'payroll' => 'payroll.read',
        'paybill' => 'paybill.read',
        'giving' => 'giving.read',
        'transactions' => 'transactions.read',
        'gateways' => 'gateways.manage',
    ][$page] ?? 'books.read'));
    $role = getCurrentRole() ?? [];
    $user = getAuthUser() ?? [];
    $can = fn (string $p) => hasGlobalAccess() || hasPermission("{$level}.accounting.{$p}");

    return [
        'level' => $level,
        'page' => $page,
        'baseUrl' => SITE_URL . "/{$level}/accounting",
        'budgetsUrl' => SITE_URL . ($level === 'church' ? '/church/budget' : "/{$level}/budgets"),
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'siteUrl' => SITE_URL,
        'userId' => (int) ($user['id'] ?? 0),
        'place' => ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''],
        'can' => [
            'receipt' => $can('receipts.create'),
            'prepare' => $can('payments.prepare'),
            'authorise' => $can('payments.authorise'),
            'pay' => $can('payments.pay'),
            'journal' => $can('journals.post'),
            'accounts' => $can('accounts.manage'),
            'chart' => $level === 'diocese' && $can('chart.manage'),
            'below' => $level !== 'church' && $can('below.read'),
            'reconcile' => $can('reconcile.do'),
            'petty' => $can('pettycash.spend'),
            'close' => $can('periods.close'),
            'reopen' => $level !== 'church' && $can('periods.reopen'),
            'collect' => $level === 'church' && $can('collections.record'),
            'confirm' => $level === 'church' && $can('collections.confirm'),
            'books' => $can('books.read'),
            'request' => $can('requisitions.create'),
            'rules' => $level === 'diocese' && $can('approvalrules.manage'),
            'procure' => $can('procurement.manage'),
            'payroll' => $can('payroll.manage'),
            'paybill' => $level === 'diocese' && $can('paybill.manage'),
            'gateways' => $level === 'diocese' && $can('gateways.manage'),
        ],
    ];
}

function accountingPageStyles(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach (['assets/libs/select2/select2.min.css', 'assets/libs/flatpickr/flatpickr.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'] as $css) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$css}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

function accountingPageScripts(string $page): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach ([
        'assets/libs/@popperjs/core/umd/popper.min.js', 'assets/libs/bootstrap/js/bootstrap.bundle.min.js', 'assets/js/defaultmenu.min.js',
        'assets/libs/node-waves/waves.min.js', 'assets/js/sticky.js', 'assets/libs/simplebar/simplebar.min.js', 'assets/js/simplebar.js',
        'assets/js/custom-switcher.min.js', 'assets/js/custom.js', 'assets/libs/apexcharts/apexcharts.min.js',
    ] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    echo '<script src="' . $v('assets/js/utils/toast.js') . '"></script>' . "\n";
    echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>' . "\n";
    foreach (['assets/libs/select2/select2.min.js', 'assets/libs/flatpickr/flatpickr.min.js', 'assets/data-tables/1.12.1/js/jquery.dataTables.min.js', 'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js', 'assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js'] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    foreach (['assets/js/utils/date-field.js', 'assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/members/ui.js', 'assets/js/pages/members/list-kit.js', 'assets/js/pages/accounting/api.js', 'assets/js/pages/accounting/ui.js', 'assets/js/pages/accounting/windows.js', ...(in_array($page, ['receipts', 'journals', 'documents'], true) ? ['assets/js/pages/accounting/doc-list.js'] : []), "assets/js/pages/accounting/{$page}.js"] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
