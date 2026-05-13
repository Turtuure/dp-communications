/**
 * Backstage Communications Composer — Wave D Task D7.
 *
 * Vanilla JS controller for /backstage/communications (composer landing).
 *
 * Behaviour:
 *   1. Hide/show kind-specific fields based on the kind dropdown.
 *   2. Locale tabs flip an `activeLocale` state used in the preview payload.
 *   3. Debounced (300 ms) auto-preview on every form change.
 *   4. "Lähetä testi itselle" → preview-only call (kind=group_message snapshot
 *      not yet wired to a true test-mailer; we surface the rendered preview
 *      as a status toast so the operator can eyeball it).
 *   5. "Lähetä jonoon" → confirm → POST /api/backstage/communications/send.
 */
(() => {
    const script = document.currentScript;
    const I18N = {
        sendConfirm:         script?.dataset.sendConfirm          ?? 'Send to queue?',
        previewEmptyText:    script?.dataset.previewEmptyText     ?? 'Preview…',
        audienceCountTpl:    script?.dataset.audienceCountTemplate ?? 'Recipients: :count',
        emptyAudienceText:   script?.dataset.emptyAudience         ?? 'No recipients match.',
        outboxUrl:           script?.dataset.outboxUrl             ?? '/backstage/communications/outbox',
    };

    /** Kinds whose audience is derived from the linked record (so the
     *  membership-filter sidebar must stay hidden). */
    const RECORD_KINDS = new Set([
        'meeting_invitation',
        'payment_reminder',
        'membership_approved',
    ]);

    let activeLocale = 'fi_FI';
    let previewTimer = null;
    let sendingInFlight = false;

    document.addEventListener('DOMContentLoaded', () => {
        wireKindDropdown();
        wireLocaleTabs();
        wireFormChanges();
        wirePreviewButton();
        wireTestButton();
        wireSendButton();

        // Initial paint — pick up the default kind and show its fields.
        applyKindVisibility();
        requestPreview();
    });

    function wireKindDropdown() {
        const sel = document.getElementById('comms-kind');
        if (!sel) return;
        sel.addEventListener('change', () => {
            applyKindVisibility();
            requestPreview();
        });
    }

    function wireLocaleTabs() {
        const tabs = document.querySelectorAll('#comms-locale-tabs .tab-button');
        tabs.forEach(btn => {
            btn.addEventListener('click', () => {
                const loc = btn.dataset.locale;
                if (!loc) return;
                activeLocale = loc;
                tabs.forEach(b => {
                    b.classList.toggle('tab-active', b === btn);
                    b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
                });
                requestPreview();
            });
        });
    }

    function wireFormChanges() {
        const form = document.getElementById('comms-composer-form');
        if (form) {
            form.addEventListener('input', debouncePreview);
            form.addEventListener('change', debouncePreview);
        }
        const aud = document.getElementById('comms-audience');
        if (aud) {
            aud.addEventListener('input', debouncePreview);
            aud.addEventListener('change', debouncePreview);
        }
    }

    function wirePreviewButton() {
        const btn = document.getElementById('comms-preview-btn');
        if (btn) {
            btn.addEventListener('click', () => requestPreview());
        }
    }

    function wireTestButton() {
        const btn = document.getElementById('comms-test-btn');
        if (!btn) return;
        btn.addEventListener('click', () => {
            // Forces a fresh preview render so the operator can eyeball it.
            // True self-send is C8's SMTP-test button on the settings page.
            requestPreview(true);
        });
    }

    function wireSendButton() {
        const btn = document.getElementById('comms-send-btn');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            if (sendingInFlight) return;
            if (!window.confirm(I18N.sendConfirm)) return;
            sendingInFlight = true;
            btn.disabled = true;
            try {
                const payload = buildPayload();
                const resp = await fetch('/api/backstage/communications/send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                const body = await resp.json().catch(() => ({}));
                if (!resp.ok) {
                    showToast(body?.message ?? `HTTP ${resp.status}`, 'error');
                    return;
                }
                const count = body?.data?.enqueued_count ?? 0;
                showToast(`+${count}`, 'success');
                // After a successful enqueue we forward the operator to the
                // outbox so they can watch the rows flip from queued → sent.
                window.location.href = I18N.outboxUrl;
            } catch (err) {
                showToast(String(err), 'error');
            } finally {
                sendingInFlight = false;
                btn.disabled = false;
            }
        });
    }

    function applyKindVisibility() {
        const kind = getKind();
        document.querySelectorAll('[data-kind-fields]').forEach(el => {
            const want = el.getAttribute('data-kind-fields') === kind;
            el.hidden = !want;
        });

        // The audience sidebar is for ad-hoc kinds only.
        const aud = document.getElementById('comms-audience');
        if (aud) {
            aud.hidden = RECORD_KINDS.has(kind);
        }
    }

    function debouncePreview() {
        if (previewTimer !== null) clearTimeout(previewTimer);
        previewTimer = setTimeout(() => requestPreview(), 300);
    }

    async function requestPreview(_force = false) {
        const statusEl = document.getElementById('comms-composer-status');
        if (statusEl) statusEl.textContent = '';

        try {
            const payload = buildPayload();
            const resp = await fetch('/api/backstage/communications/preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const body = await resp.json().catch(() => ({}));
            if (!resp.ok) {
                renderPreview('', body?.message ?? `HTTP ${resp.status}`);
                updateAudienceBadge(0);
                if (statusEl) statusEl.textContent = body?.message ?? `HTTP ${resp.status}`;
                return;
            }
            const data = body?.data ?? {};
            renderPreview(data.html_preview ?? '', data.text_preview ?? '');
            updateAudienceBadge(data.audience_count ?? 0);
        } catch (err) {
            renderPreview('', String(err));
            updateAudienceBadge(0);
        }
    }

    function renderPreview(html, text) {
        const iframe = document.getElementById('comms-preview-html');
        if (iframe && 'srcdoc' in iframe) {
            iframe.srcdoc = html || `<p style="font-family:sans-serif;color:#888">${escapeHtml(I18N.previewEmptyText)}</p>`;
        }
        const pre = document.getElementById('comms-preview-text');
        if (pre) pre.textContent = text || '';
    }

    function updateAudienceBadge(count) {
        const el = document.getElementById('comms-audience-count');
        if (el) el.textContent = String(count);
    }

    function buildPayload() {
        const kind = getKind();
        const payload = {
            kind,
            locale: activeLocale,
            payload: {
                subject:    getValue('comms-subject') || null,
                intro_text: getValue('comms-intro-text') || null,
                signature:  getValue('comms-signature') || null,
            },
            audience_filter: buildAudienceFilter(kind),
        };

        if (kind === 'group_message') {
            payload.payload.body = getValue('comms-body') || null;
        }
        if (kind === 'meeting_invitation') {
            payload.payload.meeting_id = getValue('comms-meeting-id') || null;
        }
        if (kind === 'payment_reminder') {
            payload.payload.invoice_id = getValue('comms-invoice-id') || null;
        }
        if (kind === 'membership_approved') {
            payload.payload.application_id = getValue('comms-application-id') || null;
        }

        return payload;
    }

    function buildAudienceFilter(kind) {
        if (RECORD_KINDS.has(kind)) {
            // Recipient is the user linked to the record; no filter applies.
            return { membership_types: [], locales: [], joined_within: '' };
        }
        const membership = collectChecked('membership_types[]');
        const locs       = collectChecked('locales[]');
        const joined     = (document.querySelector('input[name="joined_within"]:checked')?.value) ?? '';
        return {
            membership_types: membership,
            locales:          locs,
            joined_within:    joined,
        };
    }

    function collectChecked(name) {
        return Array.from(document.querySelectorAll(`input[name="${name}"]:checked`))
            .map(el => el.value)
            .filter(v => v !== '');
    }

    function getKind() {
        const sel = document.getElementById('comms-kind');
        return (sel && sel.value) ? String(sel.value) : 'meeting_invitation';
    }

    function getValue(id) {
        const el = document.getElementById(id);
        return el ? String(el.value).trim() : '';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[ch] || ch));
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
