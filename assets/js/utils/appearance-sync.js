/**
 * Shared Appearance engine (window.MwdAppearance) - loaded on every page
 * from includes/header.php.
 *
 * - Knows how a saved Appearance state maps onto <html>: app-* classes
 *   (density/text size/accessibility), data-theme-mode (+ data-theme-pref
 *   for "system") and an inline --primary-rgb for the accent. CLASS_MAP,
 *   DEFAULTS and ACCENTS mirror backend App\Support\Appearance::OPTIONS /
 *   ACCENT_RGB, so a change can be previewed instantly without a round-trip.
 * - Writes the cookies includes/session-manager.php reads to print the same
 *   state server-side on the next page load (no flash of the wrong theme).
 * - On a browser that has no theme cookie yet (a new device, or right after
 *   logout cleared them), fetches the user's saved settings once and applies
 *   them - so choosing Dark on a laptop also takes effect on a phone.
 *
 * The Appearance page itself (assets/js/pages/appearance/appearance.js)
 * uses this for previewing and saving.
 */
(function () {
    'use strict';

    const CLASS_MAP = {
        density: { compact: 'app-density-compact', comfortable: null, spacious: 'app-density-spacious' },
        text_size: { small: 'app-text-sm', medium: null, large: 'app-text-lg' },
        reduce_motion: 'app-reduce-motion',
        high_contrast: 'app-high-contrast',
        focus_outlines: 'app-focus-outlines',
        underline_links: 'app-underline-links',
        big_targets: 'app-big-targets',
    };

    const TOGGLES = ['reduce_motion', 'high_contrast', 'focus_outlines', 'underline_links', 'big_targets'];

    const DEFAULTS = {
        theme: 'light',
        accent: 'teal',
        density: 'comfortable',
        text_size: 'medium',
        reduce_motion: false,
        high_contrast: false,
        focus_outlines: false,
        underline_links: false,
        big_targets: false,
    };

    // Must match backend Appearance::ACCENT_RGB.
    const ACCENTS = {
        teal: { label: 'Diocese teal', rgb: '44, 164, 191', hex: '#2CA4BF' },
        navy: { label: 'Navy', rgb: '30, 64, 138', hex: '#1E408A' },
        green: { label: 'Green', rgb: '22, 128, 84', hex: '#168054' },
        purple: { label: 'Purple', rgb: '124, 58, 237', hex: '#7C3AED' },
        orange: { label: 'Orange', rgb: '217, 104, 30', hex: '#D9681E' },
        red: { label: 'Red', rgb: '206, 54, 45', hex: '#CE362D' },
    };

    const ALL_CLASSES = [
        'app-density-compact', 'app-density-spacious', 'app-text-sm', 'app-text-lg',
        'app-reduce-motion', 'app-high-contrast', 'app-focus-outlines', 'app-underline-links', 'app-big-targets',
    ];

    const COOKIE = {
        classes: 'mwd_appearance_classes',
        theme: 'mwd_appearance_theme',
        accentRgb: 'mwd_appearance_accent_rgb',
    };

    const darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function withDefaults(state) {
        return Object.assign({}, DEFAULTS, state || {});
    }

    function computeClasses(state) {
        const s = withDefaults(state);
        const classes = [];
        if (CLASS_MAP.density[s.density]) classes.push(CLASS_MAP.density[s.density]);
        if (CLASS_MAP.text_size[s.text_size]) classes.push(CLASS_MAP.text_size[s.text_size]);
        TOGGLES.forEach((key) => {
            if (s[key]) classes.push(CLASS_MAP[key]);
        });
        return classes;
    }

    function systemMode() {
        return darkQuery && darkQuery.matches ? 'dark' : 'light';
    }

    function resolvedMode(theme) {
        return theme === 'system' ? systemMode() : theme === 'dark' ? 'dark' : 'light';
    }

    /** Apply a (possibly partial) state to <html> immediately. */
    function apply(state) {
        const s = withDefaults(state);
        const html = document.documentElement;

        // Only touch the app-* appearance classes - other classes on <html>
        // (if any) are left alone.
        ALL_CLASSES.forEach((c) => html.classList.remove(c));
        computeClasses(s).forEach((c) => html.classList.add(c));

        const mode = resolvedMode(s.theme);
        html.setAttribute('data-theme-pref', s.theme);
        html.setAttribute('data-theme-mode', mode);
        html.setAttribute('data-header-styles', mode);

        const accent = ACCENTS[s.accent] || ACCENTS.teal;
        if (s.accent === DEFAULTS.accent) {
            html.style.removeProperty('--primary-rgb');
        } else {
            html.style.setProperty('--primary-rgb', accent.rgb);
        }

        document.dispatchEvent(new CustomEvent('mwd:appearance-applied', { detail: s }));
    }

    function setCookie(name, value) {
        document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=31536000; SameSite=Lax`;
    }

    function clearCookie(name) {
        document.cookie = `${name}=; path=/; max-age=0; SameSite=Lax`;
    }

    /** Write the cookies the server reads on the next page load. */
    function persist(state) {
        const s = withDefaults(state);
        setCookie(COOKIE.classes, computeClasses(s).join(' '));
        setCookie(COOKIE.theme, s.theme);
        if (s.accent === DEFAULTS.accent) {
            clearCookie(COOKIE.accentRgb);
        } else {
            setCookie(COOKIE.accentRgb, (ACCENTS[s.accent] || ACCENTS.teal).rgb);
        }
    }

    function hasThemeCookie() {
        return document.cookie.split(';').some((c) => c.trim().startsWith(COOKIE.theme + '='));
    }

    function getHeaders() {
        return {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Authorization: `Bearer ${localStorage.getItem(window.Constants ? Constants.STORAGE_KEYS.AUTH_TOKEN : 'mwd_auth_token')}`,
        };
    }

    async function request(method, body) {
        const response = await fetch(`${AppConfig.API_BASE_URL}/appearance`, {
            method,
            headers: getHeaders(),
            body: body ? JSON.stringify(body) : undefined,
        });
        const json = await response.json().catch(() => ({}));
        return { ok: response.ok, data: withDefaults(json.data), message: json.message };
    }

    const api = {
        fetch: () => request('GET'),
        save: (payload) => request('PUT', payload),
        reset: () => request('DELETE'),
    };

    async function syncFromServerIfNeeded() {
        if (hasThemeCookie()) return;
        if (!window.AppConfig) return;
        const token = localStorage.getItem(window.Constants ? Constants.STORAGE_KEYS.AUTH_TOKEN : 'mwd_auth_token');
        if (!token) return;

        try {
            const result = await api.fetch();
            if (!result.ok) return;
            apply(result.data);
            persist(result.data);
        } catch (error) {
            // Non-fatal: the page simply keeps the default look.
            console.warn('Appearance sync skipped:', error);
        }
    }

    // A "system" theme follows the OS live, on every page.
    if (darkQuery && darkQuery.addEventListener) {
        darkQuery.addEventListener('change', () => {
            const html = document.documentElement;
            if (html.getAttribute('data-theme-pref') !== 'system') return;
            const mode = systemMode();
            html.setAttribute('data-theme-mode', mode);
            html.setAttribute('data-header-styles', mode);
            document.dispatchEvent(new CustomEvent('mwd:appearance-applied', { detail: null }));
        });
    }

    window.MwdAppearance = {
        DEFAULTS,
        ACCENTS,
        TOGGLES,
        computeClasses,
        systemMode,
        apply,
        persist,
        api,
    };

    document.addEventListener('DOMContentLoaded', syncFromServerIfNeeded);
})();
