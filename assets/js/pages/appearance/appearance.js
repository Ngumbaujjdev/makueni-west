/**
 * Appearance page (appearance.php).
 *
 * Every change previews instantly (MwdAppearance.apply) and autosaves a
 * moment later - no Save button, the same way the Kusoya reference's
 * Appearance page behaves. The mapping of settings onto <html>, the API
 * calls and the cookies all live in assets/js/utils/appearance-sync.js
 * (window.MwdAppearance, loaded on every page from includes/header.php).
 *
 * Dependencies: config/app.js, config/constants.js, utils/toast.js,
 * utils/appearance-sync.js.
 */
(function () {
    'use strict';

    const A = window.MwdAppearance;
    const SAVE_DELAY_MS = 400;

    const DENSITY_HINTS = {
        compact: 'Compact fits more rows and cards on screen',
        comfortable: 'Comfortable is the balanced default',
        spacious: 'Spacious adds more breathing room between cards and rows',
    };

    let state = Object.assign({}, A.DEFAULTS);
    let saveTimer = null;
    let lastSaved = null;

    function setStatus(text, tone) {
        const el = document.getElementById('appearanceStatus');
        if (!el) return;
        el.className = `fs-13 fw-semibold ${tone === 'ok' ? 'text-success' : tone === 'err' ? 'text-danger' : 'text-muted'}`;
        el.innerHTML = text;
    }

    function renderSwatches() {
        const wrap = document.getElementById('accentSwatches');
        if (!wrap) return;
        wrap.innerHTML = Object.entries(A.ACCENTS)
            .map(([key, accent]) => `
                <button type="button" class="accent-swatch" role="radio" data-accent="${key}"
                    style="background-color: rgb(${accent.rgb});" title="${accent.label}" aria-label="${accent.label}">
                    <i class="ri-check-line"></i>
                </button>`)
            .join('') + '<span class="fw-semibold text-muted ms-2" id="accentHex"></span>';
    }

    function refreshSystemLabels() {
        const mode = A.systemMode();
        ['systemModeLabel', 'systemModeNote'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.textContent = mode;
        });
    }

    /** Reflect the current state in the form controls. */
    function populate() {
        const theme = document.getElementById(`theme-${state.theme}`);
        if (theme) theme.checked = true;
        document.getElementById('systemThemeNote')?.classList.toggle('d-none', state.theme !== 'system');

        document.querySelectorAll('.accent-swatch').forEach((btn) => {
            const selected = btn.dataset.accent === state.accent;
            btn.classList.toggle('is-selected', selected);
            btn.setAttribute('aria-checked', selected ? 'true' : 'false');
        });
        const accent = A.ACCENTS[state.accent] || A.ACCENTS.teal;
        const hex = document.getElementById('accentHex');
        if (hex) hex.textContent = `${accent.label} · ${accent.hex}`;

        const density = document.getElementById(`density-${state.density}`);
        if (density) density.checked = true;
        const densityHint = document.getElementById('densityHint');
        if (densityHint) densityHint.textContent = DENSITY_HINTS[state.density] || '';

        const textSize = document.getElementById(`text-size-${state.text_size}`);
        if (textSize) textSize.checked = true;

        A.TOGGLES.forEach((key) => {
            const input = document.getElementById(key);
            if (input) input.checked = !!state[key];
        });

        refreshSystemLabels();
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        setStatus('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
        saveTimer = setTimeout(save, SAVE_DELAY_MS);
    }

    async function save() {
        const payload = Object.assign({}, state);
        try {
            const result = await A.api.save(payload);
            if (!result.ok) throw new Error(result.message || 'Save failed');
            lastSaved = result.data;
            A.persist(result.data);
            setStatus('<i class="ri-check-line me-1"></i>Saved', 'ok');
        } catch (error) {
            console.error('Failed to save appearance settings:', error);
            setStatus('<i class="ri-error-warning-line me-1"></i>Not saved', 'err');
            Toast.error('Could not save your appearance settings. Please try again.');
            // Put the page back to what's actually saved.
            if (lastSaved) {
                state = Object.assign({}, lastSaved);
                A.apply(state);
                populate();
            }
        }
    }

    function change(partial) {
        state = Object.assign({}, state, partial);
        A.apply(state);
        populate();
        scheduleSave();
    }

    async function reset() {
        const btn = document.getElementById('appearanceResetBtn');
        btn.disabled = true;
        clearTimeout(saveTimer);
        try {
            const result = await A.api.reset();
            if (!result.ok) throw new Error('Reset failed');
            state = Object.assign({}, result.data);
            lastSaved = result.data;
            A.apply(state);
            A.persist(state);
            populate();
            setStatus('<i class="ri-check-line me-1"></i>Reset to defaults', 'ok');
        } catch (error) {
            console.error('Failed to reset appearance settings:', error);
            Toast.error('Could not reset your appearance settings.');
        } finally {
            btn.disabled = false;
        }
    }

    function bind() {
        document.querySelectorAll('input[name="app-theme"]').forEach((input) =>
            input.addEventListener('change', () => change({ theme: input.value })));

        document.getElementById('accentSwatches')?.addEventListener('click', (e) => {
            const btn = e.target.closest('.accent-swatch');
            if (btn) change({ accent: btn.dataset.accent });
        });

        document.querySelectorAll('input[name="app-density"]').forEach((input) =>
            input.addEventListener('change', () => change({ density: input.value })));

        document.querySelectorAll('input[name="app-text-size"]').forEach((input) =>
            input.addEventListener('change', () => change({ text_size: input.value })));

        A.TOGGLES.forEach((key) => {
            document.getElementById(key)?.addEventListener('change', (e) => change({ [key]: e.target.checked }));
        });

        document.getElementById('appearanceResetBtn')?.addEventListener('click', reset);

        // Keep "Your device: dark/light" current if the OS setting flips.
        document.addEventListener('mwd:appearance-applied', refreshSystemLabels);
    }

    async function init() {
        renderSwatches();
        bind();
        populate();

        try {
            const result = await A.api.fetch();
            if (!result.ok) throw new Error(result.message || 'Load failed');
            state = Object.assign({}, result.data);
            lastSaved = result.data;
            A.apply(state);
            A.persist(state);
            populate();
        } catch (error) {
            console.error('Failed to load appearance settings:', error);
            Toast.error('Could not load your saved appearance settings.');
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
