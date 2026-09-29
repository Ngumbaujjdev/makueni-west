<?php
/**
 * Global command-palette search - shared across every page (included from
 * header.php, whose search bar - and, on phones, search icon - target
 * #searchModal).
 *
 * Previously this modal only existed - hardcoded, duplicated, and with
 * unmodified YNEX placeholder content (fake "Action"/"Another action"
 * dropdown items, dead Feather-icon glyphs) - on 3 dashboard pages, so the
 * header search icon did nothing anywhere else. Real content/behavior is
 * rendered client-side by assets/js/utils/global-search.js from the same
 * permission-filtered module cache (mwd_current_modules) the sidebar
 * itself reads, so results are automatically scoped to what the signed-in
 * user can already see - no new API call, no separate permission check.
 *
 * modal-fullscreen-sm-down: on phones the palette takes the whole screen
 * (same as v1-events-backend's command palette) instead of floating a
 * small card over a half-visible page.
 */
?>
<div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
        <div class="modal-content global-search-panel shadow-lg border-0">
            <div class="modal-body p-0 d-flex flex-column">
                <div class="d-flex align-items-center gap-3 border-block-end px-4 py-3">
                    <span class="avatar avatar-sm rounded-circle bg-primary text-white flex-shrink-0">
                        <i class="ri-search-line fs-16"></i>
                    </span>
                    <input type="text" class="form-control form-control-lg border-0 shadow-none px-1" id="globalSearchInput"
                        placeholder="Search pages..." autocomplete="off" aria-label="Search pages" />
                    <button type="button" class="btn btn-sm btn-light flex-shrink-0 fw-semibold" data-bs-dismiss="modal" aria-label="Close search">
                        <span class="d-none d-sm-inline">Esc</span><i class="ri-close-line fs-16 d-sm-none"></i>
                    </button>
                </div>
                <div id="globalSearchResults" class="global-search-results"></div>
                <div class="d-none d-sm-flex justify-content-center gap-3 border-block-start px-3 py-2 fs-11 text-body">
                    <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
                    <span><kbd>&crarr;</kbd> open</span>
                    <span><kbd>esc</kbd> close</span>
                </div>
            </div>
        </div>
    </div>
</div>
