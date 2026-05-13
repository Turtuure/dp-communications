/*
 * Newsletter blocks editor — Wave E Task E5.
 *
 * Two modes (selected via the host <script>'s data-mode attribute):
 *   - list: renders /backstage/communications/newsletters table + create button
 *   - edit: full block-composer UI on /backstage/communications/newsletters-edit
 *
 * MVP: typed form per block kind + up/down reorder controls. Drag-and-drop is
 * deferred to 1.x. Block model is the BlockSerializer JSON shape used by the
 * platform — see modules/communications/backend/src/Domain/Template/Block/BlockSerializer.php.
 */

(function () {
    'use strict';

    const script = document.currentScript;
    const cfg = script ? script.dataset : {};
    const mode = cfg.mode || 'list';

    const API_BASE = '/api/backstage/communications/newsletters';

    function fetchJson(method, url, body) {
        const opts = {
            method,
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        };
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts).then(async (r) => {
            let parsed = null;
            try { parsed = await r.json(); } catch (_e) { /* no body */ }
            return { status: r.status, ok: r.ok, body: parsed };
        });
    }

    function escapeHtml(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    // ─── List mode ──────────────────────────────────────────────────────────

    function bootList() {
        const tbody = document.querySelector('#comms-newsletters-table tbody');
        const createBtn = document.getElementById('comms-newsletters-create');
        if (!tbody) return;

        function renderRows(rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5">${escapeHtml(cfg.emptyText || 'Empty')}</td></tr>`;
                return;
            }
            const html = rows.map((r) => {
                const statusLabel = ({
                    draft:     cfg.statusDraft     || 'Draft',
                    scheduled: cfg.statusScheduled || 'Scheduled',
                    sent:      cfg.statusSent      || 'Sent',
                })[r.status] || r.status;
                const created = r.created_at ? new Date(r.created_at).toLocaleString() : '';
                const sent = r.sent_at ? new Date(r.sent_at).toLocaleString() : '';
                const editHref = (cfg.editHref || '#') + '?id=' + encodeURIComponent(r.id);
                return `<tr data-newsletter-id="${escapeHtml(r.id)}">
                    <td><a href="${escapeHtml(editHref)}">${escapeHtml(r.internal_name)}</a></td>
                    <td><span class="status-badge status-badge--${escapeHtml(r.status)}">${escapeHtml(statusLabel)}</span></td>
                    <td>${escapeHtml(created)}</td>
                    <td>${escapeHtml(sent)}</td>
                    <td>
                        <a href="${escapeHtml(editHref)}" class="btn btn--ghost btn--sm">${escapeHtml(cfg.editLabel || 'Edit')}</a>
                        ${r.status !== 'sent' ? `<button type="button" class="btn btn--danger btn--sm" data-action="delete">${escapeHtml(cfg.deleteLabel || 'Delete')}</button>` : ''}
                    </td>
                </tr>`;
            }).join('');
            tbody.innerHTML = html;
        }

        function load() {
            fetchJson('GET', API_BASE).then((r) => {
                if (r.ok && r.body && Array.isArray(r.body.data)) {
                    renderRows(r.body.data);
                } else {
                    tbody.innerHTML = `<tr><td colspan="5">${escapeHtml(cfg.emptyText || 'Empty')}</td></tr>`;
                }
            });
        }

        tbody.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="delete"]');
            if (!btn) return;
            const tr = btn.closest('tr');
            const id = tr && tr.dataset.newsletterId;
            if (!id) return;
            if (!confirm(cfg.deleteConfirm || 'Delete newsletter?')) return;
            fetchJson('DELETE', API_BASE + '/' + encodeURIComponent(id)).then((r) => {
                if (r.ok) load();
            });
        });

        if (createBtn) {
            createBtn.addEventListener('click', () => {
                const name = prompt(cfg.createPrompt || 'Internal name');
                if (!name || !name.trim()) return;
                fetchJson('POST', API_BASE, { internal_name: name.trim() }).then((r) => {
                    if (r.ok && r.body && r.body.data && r.body.data.id) {
                        window.location.href = (cfg.editHref || '#') + '?id=' + encodeURIComponent(r.body.data.id);
                    } else {
                        alert('Create failed: ' + (r.body && r.body.message ? r.body.message : r.status));
                    }
                });
            });
        }

        load();
    }

    // ─── Edit mode ──────────────────────────────────────────────────────────

    function bootEdit() {
        const root = document.querySelector('.comms-newsletter-edit');
        if (!root) return;
        const newsletterId = root.dataset.newsletterId || '';
        if (!newsletterId) {
            alert(cfg.errorNotFound || 'Not found');
            return;
        }

        // Local state — single source of truth for the in-memory draft.
        const state = {
            internalName:    '',
            currentLocale:   'fi_FI',
            subjectByLocale: { fi_FI: '', en_GB: '', sw_TZ: '' },
            blocksByLocale:  { fi_FI: [], en_GB: [], sw_TZ: [] },
            audience: {
                membershipTypes:     [],
                locales:             [],
                joinedWithin:        null,
                applicationStatuses: [],
            },
            status:    'draft',
            selectedBlockIndex: null,
            // autosave
            autosaveTimer: null,
            saving: false,
        };

        const $name      = document.getElementById('comms-newsletter-internal-name');
        const $subject   = document.getElementById('comms-newsletter-subject');
        const $tabs      = document.querySelectorAll('#comms-locale-tabs .comms-locale-tab');
        const $hintSubj  = document.getElementById('comms-subject-locale-hint');
        const $hintCanv  = document.getElementById('comms-canvas-locale-hint');
        const $list      = document.getElementById('comms-canvas-list');
        const $props     = document.getElementById('comms-properties-pane');
        const $palette   = document.querySelectorAll('.comms-palette-btn');
        const $audMem    = document.getElementById('comms-audience-membership');
        const $audLoc    = document.getElementById('comms-audience-locales');
        const $audJoin   = document.getElementById('comms-audience-joined');
        const $sendBtn   = document.getElementById('comms-newsletter-send');
        const $delBtn    = document.getElementById('comms-newsletter-delete');
        const $autosave  = document.getElementById('comms-autosave-indicator');
        const $nameTitle = document.getElementById('comms-newsletter-name');
        const $meta      = document.getElementById('comms-newsletter-meta');

        function csvToList(s) {
            return String(s || '').split(',').map((p) => p.trim()).filter((p) => p !== '');
        }
        function listToCsv(arr) {
            return Array.isArray(arr) ? arr.join(',') : '';
        }

        function setAutosaveStatus(label, cls) {
            if (!$autosave) return;
            $autosave.textContent = label;
            $autosave.className = 'status-badge ' + (cls || 'status-badge--draft');
        }

        function scheduleAutosave() {
            if (state.autosaveTimer) clearTimeout(state.autosaveTimer);
            state.autosaveTimer = setTimeout(save, 1000);
        }

        function save() {
            if (state.saving) {
                scheduleAutosave();
                return;
            }
            state.saving = true;
            setAutosaveStatus(cfg.autosaveSaving || 'Saving…', 'status-badge--scheduled');
            const payload = {
                internal_name: state.internalName,
                subject_i18n:  state.subjectByLocale,
                blocks_i18n:   state.blocksByLocale,
                audience:      state.audience,
            };
            fetchJson('PATCH', API_BASE + '/' + encodeURIComponent(newsletterId), payload).then((r) => {
                state.saving = false;
                if (r.ok) {
                    setAutosaveStatus(cfg.autosaveSaved || 'Saved', 'status-badge--draft');
                } else {
                    setAutosaveStatus(cfg.autosaveError || 'Save error', 'status-badge--failed');
                }
            }).catch(() => {
                state.saving = false;
                setAutosaveStatus(cfg.autosaveError || 'Save error', 'status-badge--failed');
            });
        }

        function load() {
            fetchJson('GET', API_BASE).then((r) => {
                if (!r.ok || !r.body || !Array.isArray(r.body.data)) {
                    alert(cfg.errorLoad || 'Load failed');
                    return;
                }
                const row = r.body.data.find((d) => d.id === newsletterId);
                if (!row) {
                    alert(cfg.errorNotFound || 'Not found');
                    return;
                }
                state.internalName    = row.internal_name || '';
                state.status         = row.status || 'draft';
                state.subjectByLocale = {
                    fi_FI: (row.subject_i18n && row.subject_i18n.fi_FI) || '',
                    en_GB: (row.subject_i18n && row.subject_i18n.en_GB) || '',
                    sw_TZ: (row.subject_i18n && row.subject_i18n.sw_TZ) || '',
                };
                state.blocksByLocale = {
                    fi_FI: (row.blocks_i18n && Array.isArray(row.blocks_i18n.fi_FI)) ? row.blocks_i18n.fi_FI : [],
                    en_GB: (row.blocks_i18n && Array.isArray(row.blocks_i18n.en_GB)) ? row.blocks_i18n.en_GB : [],
                    sw_TZ: (row.blocks_i18n && Array.isArray(row.blocks_i18n.sw_TZ)) ? row.blocks_i18n.sw_TZ : [],
                };
                if (row.audience) {
                    state.audience = {
                        membershipTypes:     Array.isArray(row.audience.membershipTypes) ? row.audience.membershipTypes : [],
                        locales:             Array.isArray(row.audience.locales) ? row.audience.locales : [],
                        joinedWithin:        row.audience.joinedWithin || null,
                        applicationStatuses: Array.isArray(row.audience.applicationStatuses) ? row.audience.applicationStatuses : [],
                    };
                }
                renderAll();
            });
        }

        function renderAll() {
            if ($nameTitle) $nameTitle.textContent = state.internalName || '(untitled)';
            if ($meta)      $meta.textContent = 'status: ' + state.status;
            if ($name)      $name.value = state.internalName;
            if ($subject)   $subject.value = state.subjectByLocale[state.currentLocale] || '';
            if ($hintSubj)  $hintSubj.textContent = state.currentLocale;
            if ($hintCanv)  $hintCanv.textContent = state.currentLocale;
            if ($audMem)    $audMem.value = listToCsv(state.audience.membershipTypes);
            if ($audLoc)    $audLoc.value = listToCsv(state.audience.locales);
            if ($audJoin)   $audJoin.value = state.audience.joinedWithin || '';
            $tabs.forEach((t) => {
                t.classList.toggle('is-active', t.dataset.locale === state.currentLocale);
            });
            renderCanvas();
            renderProperties();
        }

        function renderCanvas() {
            const blocks = state.blocksByLocale[state.currentLocale] || [];
            if (blocks.length === 0) {
                $list.innerHTML = `<p class="comms-canvas-empty">Empty — add a block from the palette.</p>`;
                return;
            }
            $list.innerHTML = blocks.map((b, i) => {
                const isSel = state.selectedBlockIndex === i ? ' is-selected' : '';
                return `<div class="comms-block-card${isSel}" data-block-index="${i}">
                    <div class="comms-block-card__header">
                        <span class="comms-block-card__kind">${escapeHtml(b.type)}</span>
                        <div class="comms-block-card__controls">
                            <button type="button" data-action="up" title="${escapeHtml(cfg.labelUp || 'Up')}">↑</button>
                            <button type="button" data-action="down" title="${escapeHtml(cfg.labelDown || 'Down')}">↓</button>
                            <button type="button" data-action="remove" title="${escapeHtml(cfg.labelRemove || 'Remove')}">×</button>
                        </div>
                    </div>
                    <div class="comms-block-card__preview">${escapeHtml(blockPreview(b))}</div>
                </div>`;
            }).join('');
        }

        function blockPreview(b) {
            switch (b.type) {
                case 'heading':     return 'H' + (b.level || 1) + ' • ' + (b.text || '');
                case 'paragraph':   return (b.markdown || '').slice(0, 120);
                case 'image':       return (b.alt || '') + ' (' + (b.url || '') + ')';
                case 'button':      return (b.text || '') + ' → ' + (b.url || '');
                case 'divider':     return '---';
                case 'event_card':  return 'Event: ' + (b.eventId || '');
                case 'two_columns': return 'L:' + ((b.left || []).length) + ' / R:' + ((b.right || []).length);
                default:            return '';
            }
        }

        function renderProperties() {
            const idx = state.selectedBlockIndex;
            const blocks = state.blocksByLocale[state.currentLocale] || [];
            if (idx === null || idx < 0 || idx >= blocks.length) {
                $props.innerHTML = `<p class="comms-muted">${escapeHtml(cfg.propertiesEmpty || 'Select a block')}</p>`;
                return;
            }
            const b = blocks[idx];
            let html = '';
            switch (b.type) {
                case 'heading':
                    html = `
                        <label><span>Level</span>
                            <select data-prop="level">
                                ${[1,2,3,4,5,6].map((n) => `<option value="${n}"${b.level === n ? ' selected' : ''}>H${n}</option>`).join('')}
                            </select>
                        </label>
                        <label><span>Text</span><input type="text" data-prop="text" value="${escapeHtml(b.text || '')}"></label>
                    `;
                    break;
                case 'paragraph':
                    html = `<label><span>Markdown</span><textarea data-prop="markdown" rows="6">${escapeHtml(b.markdown || '')}</textarea></label>`;
                    break;
                case 'image':
                    html = `
                        <label><span>URL</span><input type="text" data-prop="url" value="${escapeHtml(b.url || '')}"></label>
                        <label><span>Alt</span><input type="text" data-prop="alt" value="${escapeHtml(b.alt || '')}"></label>
                        <label><span>Caption</span><input type="text" data-prop="caption" value="${escapeHtml(b.caption || '')}"></label>
                    `;
                    break;
                case 'button':
                    html = `
                        <label><span>Text</span><input type="text" data-prop="text" value="${escapeHtml(b.text || '')}"></label>
                        <label><span>URL</span><input type="text" data-prop="url" value="${escapeHtml(b.url || '')}"></label>
                    `;
                    break;
                case 'divider':
                    html = `<p class="comms-muted">No properties.</p>`;
                    break;
                case 'event_card':
                    html = `
                        <label><span>Event ID</span><input type="text" data-prop="eventId" value="${escapeHtml(b.eventId || '')}"></label>
                        <label><span>Title (optional)</span><input type="text" data-prop="title" value="${escapeHtml(b.title || '')}"></label>
                        <label><span>When label</span><input type="text" data-prop="whenLabel" value="${escapeHtml(b.whenLabel || '')}"></label>
                    `;
                    break;
                case 'two_columns':
                    html = `<p class="comms-muted">Two-column nested editor — paste JSON.</p>
                        <label><span>Left JSON</span><textarea data-prop="left" rows="4">${escapeHtml(JSON.stringify(b.left || [], null, 2))}</textarea></label>
                        <label><span>Right JSON</span><textarea data-prop="right" rows="4">${escapeHtml(JSON.stringify(b.right || [], null, 2))}</textarea></label>`;
                    break;
            }
            $props.innerHTML = html;
        }

        function addBlock(kind) {
            const blocks = state.blocksByLocale[state.currentLocale];
            const fresh = freshBlock(kind);
            blocks.push(fresh);
            state.selectedBlockIndex = blocks.length - 1;
            renderCanvas();
            renderProperties();
            scheduleAutosave();
        }

        function freshBlock(kind) {
            switch (kind) {
                case 'heading':     return { type: 'heading', level: 1, text: '' };
                case 'paragraph':   return { type: 'paragraph', markdown: '' };
                case 'image':       return { type: 'image', url: '', alt: '', caption: null };
                case 'button':      return { type: 'button', text: '', url: '' };
                case 'divider':     return { type: 'divider' };
                case 'two_columns': return { type: 'two_columns', left: [], right: [] };
                case 'event_card':  return { type: 'event_card', eventId: '', title: null, whenLabel: null };
                default:            return { type: kind };
            }
        }

        // ─── Event wiring ────────────────────────────────────────────────────

        if ($name) {
            $name.addEventListener('input', () => {
                state.internalName = $name.value;
                if ($nameTitle) $nameTitle.textContent = state.internalName || '(untitled)';
                scheduleAutosave();
            });
        }
        if ($subject) {
            $subject.addEventListener('input', () => {
                state.subjectByLocale[state.currentLocale] = $subject.value;
                scheduleAutosave();
            });
        }
        $tabs.forEach((t) => {
            t.addEventListener('click', () => {
                state.currentLocale = t.dataset.locale;
                state.selectedBlockIndex = null;
                renderAll();
            });
        });
        $palette.forEach((b) => {
            b.addEventListener('click', () => addBlock(b.dataset.blockKind));
        });
        $list.addEventListener('click', (e) => {
            const card = e.target.closest('.comms-block-card');
            if (!card) return;
            const idx = parseInt(card.dataset.blockIndex, 10);
            const blocks = state.blocksByLocale[state.currentLocale];
            const action = e.target.dataset.action;
            if (action === 'up') {
                if (idx > 0) {
                    const t = blocks[idx - 1]; blocks[idx - 1] = blocks[idx]; blocks[idx] = t;
                    state.selectedBlockIndex = idx - 1;
                    scheduleAutosave();
                }
            } else if (action === 'down') {
                if (idx < blocks.length - 1) {
                    const t = blocks[idx + 1]; blocks[idx + 1] = blocks[idx]; blocks[idx] = t;
                    state.selectedBlockIndex = idx + 1;
                    scheduleAutosave();
                }
            } else if (action === 'remove') {
                blocks.splice(idx, 1);
                state.selectedBlockIndex = null;
                scheduleAutosave();
            } else {
                state.selectedBlockIndex = idx;
            }
            renderCanvas();
            renderProperties();
        });
        $props.addEventListener('input', (e) => {
            const el = e.target.closest('[data-prop]');
            if (!el) return;
            const idx = state.selectedBlockIndex;
            if (idx === null) return;
            const blocks = state.blocksByLocale[state.currentLocale];
            const b = blocks[idx];
            if (!b) return;
            const prop = el.dataset.prop;
            let val = el.value;
            if (prop === 'level') val = parseInt(val, 10) || 1;
            if (prop === 'left' || prop === 'right') {
                try { val = JSON.parse(val); } catch (_e) { return; }
            }
            if (prop === 'caption' && val === '') val = null;
            b[prop] = val;
            // Re-render canvas preview text but NOT the properties pane
            // (don't yank focus from the input the user is typing into).
            renderCanvas();
            scheduleAutosave();
        });
        if ($audMem) $audMem.addEventListener('input', () => {
            state.audience.membershipTypes = csvToList($audMem.value);
            scheduleAutosave();
        });
        if ($audLoc) $audLoc.addEventListener('input', () => {
            state.audience.locales = csvToList($audLoc.value);
            scheduleAutosave();
        });
        if ($audJoin) $audJoin.addEventListener('change', () => {
            state.audience.joinedWithin = $audJoin.value || null;
            scheduleAutosave();
        });
        if ($sendBtn) $sendBtn.addEventListener('click', () => {
            if (!confirm(cfg.sendConfirm || 'Send?')) return;
            // Force a flush of any pending autosave, then POST .../send.
            if (state.autosaveTimer) { clearTimeout(state.autosaveTimer); state.autosaveTimer = null; }
            save();
            // Wait a tick to let the PATCH land.
            setTimeout(() => {
                fetchJson('POST', API_BASE + '/' + encodeURIComponent(newsletterId) + '/send', {}).then((r) => {
                    if (r.ok) {
                        alert(cfg.sendSuccess || 'Queued');
                        if (cfg.listHref) window.location.href = cfg.listHref;
                    } else {
                        const m = r.body && r.body.message ? r.body.message : ('HTTP ' + r.status);
                        alert((cfg.sendError || 'Send failed') + ': ' + m);
                    }
                });
            }, 200);
        });
        if ($delBtn) $delBtn.addEventListener('click', () => {
            if (!confirm(cfg.deleteConfirm || 'Delete?')) return;
            fetchJson('DELETE', API_BASE + '/' + encodeURIComponent(newsletterId)).then((r) => {
                if (r.ok && cfg.listHref) window.location.href = cfg.listHref;
            });
        });

        load();
    }

    // ─── Boot ──────────────────────────────────────────────────────────────

    if (mode === 'edit') {
        bootEdit();
    } else {
        bootList();
    }
})();
