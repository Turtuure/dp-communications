/**
 * Backstage Communications Template editor — Wave D Task D8.
 *
 * Per-kind, per-locale string-override editor. The page renders one form
 * panel per locale (fi_FI / en_GB / sw_TZ); each tab is hydrated on first
 * activation via GET /api/backstage/communications/templates/{kind}/{locale}.
 * Save → PUT same URL with `{overrides: {subject, intro_text, signature, footer}}`.
 *
 * Empty fields are forwarded as empty strings — the backend whitelist accepts
 * them so the admin can deliberately blank a slot. To clear ALL overrides
 * for a locale, save with all fields empty.
 */
(() => {
    const script = document.currentScript;
    const KIND = script?.dataset.kind ?? '';
    const I18N = {
        savedLabel: script?.dataset.savedLabel ?? 'Saved',
    };

    /** Locales already fetched this session (don't refetch on tab-switch). */
    const hydrated = new Set();

    document.addEventListener('DOMContentLoaded', () => {
        wireTabs();
        wireSave();

        // Hydrate the initially-active locale.
        const firstTab = document.querySelector('.comms-template-edit__tabs .tab-button.tab-active');
        const initial = firstTab?.dataset.localeTab ?? 'fi_FI';
        hydrateLocale(initial);
    });

    function wireTabs() {
        const tabs = document.querySelectorAll('.comms-template-edit__tabs .tab-button');
        tabs.forEach(btn => {
            btn.addEventListener('click', () => {
                const loc = btn.dataset.localeTab;
                if (!loc) return;
                tabs.forEach(b => {
                    b.classList.toggle('tab-active', b === btn);
                    b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
                });
                document.querySelectorAll('[data-locale-pane]').forEach(pane => {
                    const isActive = pane.getAttribute('data-locale-pane') === loc;
                    pane.classList.toggle('tab-active', isActive);
                    pane.hidden = !isActive;
                });
                hydrateLocale(loc);
            });
        });
    }

    async function hydrateLocale(locale) {
        if (hydrated.has(locale)) return;
        if (!KIND) return;
        try {
            const resp = await fetch(`/api/backstage/communications/templates/${encodeURIComponent(KIND)}/${encodeURIComponent(locale)}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            if (!resp.ok) {
                showToast(`HTTP ${resp.status}`, 'error');
                return;
            }
            const body = await resp.json();
            const overrides = body?.data?.overrides ?? {};
            ['subject', 'intro_text', 'signature', 'footer'].forEach(field => {
                const el = document.querySelector(`[data-field="${field}"][data-locale="${locale}"]`);
                if (el) el.value = overrides[field] ?? '';
            });
            hydrated.add(locale);
        } catch (err) {
            showToast(String(err), 'error');
        }
    }

    function wireSave() {
        const form = document.getElementById('comms-template-form');
        if (!form) return;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const activeTab = document.querySelector('.comms-template-edit__tabs .tab-button.tab-active');
            const locale = activeTab?.dataset.localeTab ?? 'fi_FI';

            const overrides = {};
            ['subject', 'intro_text', 'signature', 'footer'].forEach(field => {
                const el = document.querySelector(`[data-field="${field}"][data-locale="${locale}"]`);
                if (el) overrides[field] = String(el.value);
            });

            try {
                const resp = await fetch(`/api/backstage/communications/templates/${encodeURIComponent(KIND)}/${encodeURIComponent(locale)}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ overrides }),
                });
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    showToast(`HTTP ${resp.status}: ${err?.message ?? err?.error ?? ''}`, 'error');
                    return;
                }
                const savedEl = document.getElementById('comms-template-last-saved');
                if (savedEl) {
                    savedEl.textContent = `${I18N.savedLabel} ${new Date().toLocaleTimeString()}`;
                }
                showToast(I18N.savedLabel, 'success');
            } catch (err) {
                showToast(String(err), 'error');
            }
        });
    }

    function showToast(message, kind) {
        if (typeof window.daemsToast === 'function') {
            window.daemsToast(message, kind);
            return;
        }
        const el = document.createElement('div');
        el.className = `comms-toast comms-toast--${kind || 'info'}`;
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }
})();
