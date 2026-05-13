/**
 * Backstage Communications Settings — 4-tab editor + SMTP-test runner.
 *
 * Page lives at /backstage/communications/settings (module backstage page).
 * Endpoints:
 *   GET  /api/backstage/communications/settings           → hydrate
 *   PUT  /api/backstage/communications/settings           → save
 *   POST /api/backstage/communications/settings/smtp-test → test
 *
 * The DSN field NEVER receives the actual ciphertext — the backend returns
 * the sentinel "***" or null. When the form is submitted with an unchanged
 * DSN field, we drop it from the payload so the use case keeps the saved
 * value untouched. Plain-text re-entry replaces it.
 */
(() => {
    const script = document.currentScript;
    const I18N = {
        savedLabel:          script?.dataset.savedLabel          ?? 'Saved',
        testSuccessTpl:      script?.dataset.testSuccessTemplate ?? 'SMTP test succeeded at :time',
        testRunningLabel:    script?.dataset.testRunningLabel    ?? 'Sending…',
        dsnConfiguredLabel:  script?.dataset.dsnConfiguredLabel  ?? 'Configured — leave blank to keep current',
        dsnEmptyLabel:       script?.dataset.dsnEmptyLabel       ?? 'Not configured',
    };

    document.addEventListener('DOMContentLoaded', () => {
        wireTabs();
        wireForm();
        wireSmtpTest();
        hydrate();
    });

    function wireTabs() {
        const buttons = document.querySelectorAll('.comms-settings__tabs .tab-button');
        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.dataset.tab;
                if (!tab) return;
                buttons.forEach(b => {
                    b.classList.toggle('tab-active', b === btn);
                    b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
                });
                document.querySelectorAll('.tab-pane').forEach(pane => {
                    const isActive = pane.getAttribute('data-tab-pane') === tab;
                    pane.classList.toggle('tab-active', isActive);
                    pane.hidden = !isActive;
                });
            });
        });
    }

    async function hydrate() {
        try {
            const resp = await fetch('/api/backstage/communications/settings', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            if (!resp.ok) {
                showToast(`HTTP ${resp.status}`, 'error');
                return;
            }
            const body = await resp.json();
            const s = body?.data ?? {};
            populate(s);
        } catch (err) {
            showToast(String(err), 'error');
        }
    }

    function populate(s) {
        // SMTP — the DSN field stays empty on load. The "configured" hint
        // text tells the user whether a value is stored. Re-typing replaces
        // it; leaving blank preserves the saved value.
        setValue('comms-smtp-dsn', '');
        const stateEl = document.getElementById('comms-dsn-state');
        if (stateEl) {
            stateEl.textContent = s.dsn_configured ? I18N.dsnConfiguredLabel : I18N.dsnEmptyLabel;
        }
        setValue('comms-mail-from',     s.mail_from_address ?? '');
        setValue('comms-mail-display',  s.mail_display_name ?? '');
        setValue('comms-mail-reply-to', s.mail_reply_to     ?? '');

        // Cron
        setValue('comms-cron-pre',  s.reminder_pre_due_days ?? '');
        setValue('comms-cron-post', Array.isArray(s.reminder_post_due_days) ? s.reminder_post_due_days.join(',') : '');
        setValue('comms-cron-lapse', s.lapse_warning_days_before ?? '');

        // Brand
        setValue('comms-brand-logo',   s.brand_logo_url        ?? '');
        setValue('comms-brand-color',  s.brand_primary_color   ?? '');
        setValue('comms-brand-footer', s.brand_footer_address  ?? '');

        // SMTP test timestamp
        const statusEl = document.getElementById('comms-smtp-test-status');
        if (statusEl && s.smtp_test_succeeded_at) {
            const t = new Date(s.smtp_test_succeeded_at).toLocaleString();
            statusEl.textContent = I18N.testSuccessTpl.replace(':time', t);
            statusEl.classList.remove('is-error');
            statusEl.classList.add('is-success');
        }
    }

    function wireForm() {
        const form = document.getElementById('comms-settings-form');
        if (!form) return;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = buildPayload();
            try {
                const resp = await fetch('/api/backstage/communications/settings', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    showToast(`HTTP ${resp.status}: ${err?.error ?? ''}`, 'error');
                    return;
                }
                const savedEl = document.getElementById('comms-settings-last-saved');
                if (savedEl) {
                    savedEl.textContent = `${I18N.savedLabel} ${new Date().toLocaleTimeString()}`;
                }
                showToast(I18N.savedLabel, 'success');
                // Re-hydrate so the DSN-configured hint reflects the new
                // state (e.g. if user just typed a fresh DSN).
                hydrate();
            } catch (err) {
                showToast(String(err), 'error');
            }
        });
    }

    function buildPayload() {
        const payload = {};
        const dsn = getValue('comms-smtp-dsn');
        // Empty = don't change. Any non-empty value is a fresh DSN.
        if (dsn !== '') payload.smtp_dsn = dsn;

        payload.mail_from_address       = getValue('comms-mail-from')     || null;
        payload.mail_display_name       = getValue('comms-mail-display')  || null;
        payload.mail_reply_to           = getValue('comms-mail-reply-to') || null;

        const preDue   = getValue('comms-cron-pre');
        const lapse    = getValue('comms-cron-lapse');
        if (preDue !== '')   payload.reminder_pre_due_days     = Number(preDue);
        if (lapse !== '')    payload.lapse_warning_days_before = Number(lapse);

        const postDueRaw = getValue('comms-cron-post');
        if (postDueRaw !== '') {
            payload.reminder_post_due_days = postDueRaw
                .split(',')
                .map(p => p.trim())
                .filter(p => p !== '')
                .map(p => Number(p))
                .filter(n => Number.isFinite(n));
        }

        payload.brand_logo_url       = getValue('comms-brand-logo')   || null;
        payload.brand_primary_color  = getValue('comms-brand-color')  || null;
        payload.brand_footer_address = getValue('comms-brand-footer') || null;
        return payload;
    }

    function wireSmtpTest() {
        const btn = document.getElementById('comms-smtp-test-btn');
        const statusEl = document.getElementById('comms-smtp-test-status');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            if (statusEl) {
                statusEl.textContent = I18N.testRunningLabel;
                statusEl.classList.remove('is-success', 'is-error');
            }
            try {
                const resp = await fetch('/api/backstage/communications/settings/smtp-test', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: '{}',
                });
                const body = await resp.json().catch(() => ({}));
                if (!resp.ok) {
                    if (statusEl) {
                        statusEl.textContent = `✗ ${body?.message ?? `HTTP ${resp.status}`}`;
                        statusEl.classList.add('is-error');
                    }
                    return;
                }
                if (statusEl) {
                    const when = body?.data?.sent_at ? new Date(body.data.sent_at).toLocaleString() : '';
                    statusEl.textContent = `✓ ${I18N.testSuccessTpl.replace(':time', when)}`;
                    statusEl.classList.add('is-success');
                }
            } catch (err) {
                if (statusEl) {
                    statusEl.textContent = `✗ ${String(err)}`;
                    statusEl.classList.add('is-error');
                }
            } finally {
                btn.disabled = false;
            }
        });
    }

    function setValue(id, v) {
        const el = document.getElementById(id);
        if (el) el.value = v == null ? '' : String(v);
    }

    function getValue(id) {
        const el = document.getElementById(id);
        return el ? el.value.trim() : '';
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
