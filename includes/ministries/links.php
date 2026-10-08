<?php
/**
 * Ministries linked from other pages (docs/specs/people-and-care-spec.md, P4
 * round 2): Demographics and Attendance show "12 in our Youth ministry" and a
 * ministry chip on its gathering. Prints the two scripts it needs - only for
 * roles that can read ministries (the API refuses everyone else anyway).
 */
function ministryLinkScripts(): void
{
    if (! (hasGlobalAccess() || hasPermission('church.ministries.ministries.read') || hasPermission('church.ministries.ministries.manage'))) {
        return;
    }
    foreach (['assets/js/pages/ministries/api.js', 'assets/js/pages/ministries/links.js'] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}" . assetVersion($src) . '"></script>' . "\n";
    }
}
