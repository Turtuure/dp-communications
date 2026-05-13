/**
 * Backstage Communications Outbox — table renderer + retry handler.
 *
 * Lists rows from /api/backstage/communications/outbox (proxied to platform
 * /api/v1/backstage/communications/outbox). Filter dropdowns submit via the
 * GET form on the page; this script reads the table's data-* filter values
 * and fetches with the same params via XHR for live re-render after retry.
 */
(() => {
    const script = document.currentScript;
    const I18N = {
        empty:        script?.dataset.emptyText     ?? 'No messages.',
        retryLabel:   script?.dataset.retryLabel    ?? 'Retry',
        retryConfirm: script?.dataset.retryConfirm  ?? 'Retry this message?',
        statuses: {
            queued:     script?.dataset.statusQueued     ?? 'Queued',
            sending:    script?.dataset.statusSending    ?? 'Sending',
            sent:       script?.dataset.statusSent       ?? 'Sent',
            failed:     script?.dataset.statusFailed     ?? 'Failed',
            bounced:    script?.dataset.statusBounced    ?? 'Bounced',
            suppressed: script?.dataset.statusSuppressed ?? 'Suppressed',
        },
        kinds: {
            meeting_invitation:  script?.dataset.kindMeetingInvitation  ?? 'Meeting invitation',
            payment_reminder:    script?.dataset.kindPaymentReminder    ?? 'Payment reminder',
            membership_approved: script?.dataset.kindMembershipApproved ?? 'Membership approved',
            group_message:       script?.dataset.kindGroupMessage       ?? 'Group message',
            newsletter:          script?.dataset.kindNewsletter         ?? 'Newsletter',
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        const table = document.querySelector('.comms-outbox__list');
        if (table) loadOutbox(table);
    });

    async function loadOutbox(table) {
        const qs = new URLSearchParams();
        if (table.dataset.status)    qs.set('status',    table.dataset.status);
        if (table.dataset.kind)      qs.set('kind',      table.dataset.kind);
        if (table.dataset.from)      qs.set('from',      table.dataset.from);
        if (table.dataset.to)        qs.set('to',        table.dataset.to);
        if (table.dataset.recipient) qs.set('recipient', table.dataset.recipient);

        const tbody = table.querySelector('tbody');
        try {
            const resp = await fetch(`/api/backstage/communications/outbox?${qs}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            if (!resp.ok) {
                tbody.innerHTML = `<tr><td colspan="6">HTTP ${resp.status}</td></tr>`;
                return;
            }
            const body = await resp.json();
            const rows = Array.isArray(body.data) ? body.data : [];
            if (rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6">${escapeHtml(I18N.empty)}</td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(renderRow).join('');
            tbody.querySelectorAll('[data-action="retry"]').forEach(btn => {
                btn.addEventListener('click', () => onRetry(btn));
            });
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="6">${escapeHtml(String(err))}</td></tr>`;
        }
    }

    function renderRow(r) {
        const statusLabel = I18N.statuses[r.status] ?? r.status;
        const kindLabel = I18N.kinds[r.kind] ?? r.kind;
        const time = r.sent_at ?? r.queued_at;
        const timeStr = time ? new Date(time).toLocaleString() : '';
        const canRetry = r.status === 'failed';
        const actions = canRetry
            ? `<button type="button" class="btn btn--sm" data-action="retry" data-id="${escapeHtml(r.id)}">${escapeHtml(I18N.retryLabel)}</button>`
            : '';
        return `
            <tr data-id="${escapeHtml(r.id)}" data-status="${escapeHtml(r.status)}">
                <td>${escapeHtml(r.recipient_email)}</td>
                <td>${escapeHtml(kindLabel)}</td>
                <td><span class="status-pill status-${escapeHtml(r.status)}">${escapeHtml(statusLabel)}</span></td>
                <td>${Number(r.attempt_count ?? 0)}</td>
                <td>${escapeHtml(timeStr)}</td>
                <td>${actions}</td>
            </tr>
        `;
    }

    async function onRetry(btn) {
        const id = btn.dataset.id;
        if (!id) return;
        if (!window.confirm(I18N.retryConfirm)) return;
        btn.disabled = true;
        try {
            const resp = await fetch(`/api/backstage/communications/outbox/${encodeURIComponent(id)}/retry`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: '{}',
            });
            if (!resp.ok) {
                const err = await resp.json().catch(() => ({}));
                showToast(`HTTP ${resp.status}: ${err.error ?? ''}`, 'error');
                btn.disabled = false;
                return;
            }
            showToast(I18N.retryLabel + ' OK', 'success');
            const table = document.querySelector('.comms-outbox__list');
            if (table) loadOutbox(table);
        } catch (err) {
            showToast(String(err), 'error');
            btn.disabled = false;
        }
    }

    function showToast(message, kind) {
        // Prefer the global backstage toast helper if present, else fall back
        // to a single inline div appended to body.
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

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
})();
