<?php
/**
 * Global command-palette search - shared across every page (included from
 * header.php, whose existing search icon already targets #searchModal).
 *
 * Previously this modal only existed - hardcoded, duplicated, and with
 * unmodified YNEX placeholder content (fake "Action"/"Another action"
 * dropdown items, dead Feather-icon glyphs) - on 3 dashboard pages, so the
 * header search icon did nothing anywhere else. Real content/behavior is
 * rendered client-side by assets/js/utils/global-search.js from the same
 * permission-filtered module cache (mwd_current_modules) the sidebar
 * itself reads, so results are automatically scoped to what the signed-in
 * user can already see - no new API call, no separate permission check.
 */
?>
<div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-3 shadow-lg border-0">
            <div class="modal-body p-0">
                <div class="d-flex align-items-center gap-3 border-block-end px-4 py-3">
                    <span class="avatar avatar-sm rounded-circle bg-primary-transparent text-primary flex-shrink-0">
                        <i class="ri-search-line fs-16"></i>
                    </span>
                    <input type="text" class="form-control form-control-lg border-0 shadow-none px-1" id="globalSearchInput"
                        placeholder="Search pages..." autocomplete="off" aria-label="Search pages" />
                    <kbd class="fs-12 flex-shrink-0">Esc</kbd>
                </div>
                <div id="globalSearchResults" class="global-search-results"></div>
                <div class="d-flex justify-content-center gap-3 border-block-start px-3 py-2 fs-11 text-body">
                    <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
                    <span><kbd>&crarr;</kbd> open</span>
                    <span><kbd>esc</kbd> close</span>
                </div>
            </div>
        </div>
    </div>
</div>
