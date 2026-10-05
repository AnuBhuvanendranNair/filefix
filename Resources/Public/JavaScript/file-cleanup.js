import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

// Loading overlay for every navigation that goes back to the server (paging, filters,
// delete). Without it the module looks frozen while the unused-files queries run.
const loadingOverlay = document.getElementById('filefix-loading');
function showLoading() {
    if (loadingOverlay) {
        loadingOverlay.classList.remove('d-none');
        loadingOverlay.classList.add('d-flex');
    }
}
function hideLoading() {
    if (loadingOverlay) {
        loadingOverlay.classList.add('d-none');
        loadingOverlay.classList.remove('d-flex');
    }
}
// Back/forward cache restores the page with the overlay still visible
window.addEventListener('pageshow', (e) => { if (e.persisted) { hideLoading(); } });

document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href]');
    if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    const href = link.getAttribute('href') || '';
    if (link.hasAttribute('download') || link.dataset.filefixView || href === '' || href.startsWith('#') || href.startsWith('javascript:')) { return; }
    if (link.target && link.target !== '_self') { return; }
    showLoading();
});

document.addEventListener('submit', (e) => {
    // ZIP download returns an attachment, the page stays — no overlay
    const action = (e.submitter && e.submitter.getAttribute('formaction')) || '';
    if (action.indexOf('download_zip') !== -1) { return; }
    showLoading();
});

// Filter/perPage selects navigate on change (no inline handler: backend CSP blocks those).
// A GET form submit would replace the action's query string and drop the route token,
// TYPO3 then redirects to login -> the whole backend opens inside the module iframe.
// So the form fields are merged into the action URL instead.
document.querySelectorAll('select[data-filefix-autosubmit]').forEach((select) => {
    select.addEventListener('change', () => {
        const url = new URL(select.form.action, window.location.href);
        new FormData(select.form).forEach((value, key) => url.searchParams.set(key, value));
        showLoading();
        window.location.assign(url.toString());
    });
});

// List/thumbnail toggle without reload: both views are rendered, only one is visible.
// Checkboxes of the hidden view are disabled so file_uids[] is submitted once; the
// selection is carried over by file uid when switching.
function setViewMode(mode) {
    const panels = document.querySelectorAll('[data-filefix-view-panel]');
    const checked = new Set(
        Array.from(document.querySelectorAll('.cleanup-unused:checked:not(:disabled)')).map((cb) => cb.value)
    );
    panels.forEach((panel) => {
        const active = panel.dataset.filefixViewPanel === mode;
        panel.classList.toggle('d-none', !active);
        panel.querySelectorAll('.cleanup-unused').forEach((cb) => {
            cb.disabled = !active;
            if (active) { cb.checked = checked.has(cb.value); }
        });
    });
    document.querySelectorAll('[data-filefix-view]').forEach((btn) => {
        const active = btn.dataset.filefixView === mode;
        btn.classList.toggle('btn-primary', active);
        btn.classList.toggle('btn-default', !active);
    });
    // Keep the mode for the next server round trip (filters, paging, delete redirect)
    document.querySelectorAll('input[name="viewMode"], input[name="redirect_view_mode"]').forEach((input) => {
        input.value = mode;
    });
    document.querySelectorAll('a[href*="viewMode="]:not([data-filefix-view])').forEach((link) => {
        const url = new URL(link.href);
        url.searchParams.set('viewMode', mode);
        link.href = url.toString();
    });
    const current = new URL(window.location.href);
    current.searchParams.set('viewMode', mode);
    window.history.replaceState(window.history.state, '', current.toString());
}

document.querySelectorAll('[data-filefix-view]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        setViewMode(btn.dataset.filefixView);
    });
});

// Auto-navigate to the last folder the user selected in any file module.
// The file-storage tree writes the current folder to sessionStorage under
// 't3-module-state-media' whenever a node is selected. We read it back on
// initial load (no ?id= in URL) so the module opens on the right folder
// instead of always defaulting to storage root.
if (window.location.search.indexOf('id=') === -1) {
    try {
        const raw = sessionStorage.getItem('t3-module-state-media');
        if (raw) {
            const state = JSON.parse(raw);
            let sel = state.selection || state.identifier || '';
            if (sel) {
                sel = decodeURIComponent(sel);
                const url = new URL(window.location.href);
                url.searchParams.set('id', sel);
                showLoading();
                window.location.replace(url.toString());
            }
        }
    } catch (e) {}
}

function wireSelectAll(btnId, labelId, checkboxClass) {
    const btn   = document.getElementById(btnId);
    const label = document.getElementById(labelId);
    if (!btn || !label) { return; }
    const selectAllText = label.textContent.trim();
    btn.addEventListener('click', () => {
        const checkboxes = document.querySelectorAll('.' + checkboxClass + ':not(:disabled)');
        const allChecked = Array.from(checkboxes).every((cb) => cb.checked);
        checkboxes.forEach((cb) => { cb.checked = !allChecked; });
        label.textContent = allChecked ? selectAllText : 'Deselect all';
    });
}

wireSelectAll('cleanup-select-all-unused', 'cleanup-label-unused', 'cleanup-unused');

// Delete all filtered (flush folder) — requires typing the exact file count to confirm,
// since this bulk-deletes physical files and cannot be undone. Modal content must be a real
// DOM node (not a string) or TYPO3's modal escapes it as text instead of rendering the input.
const flushBtn = document.getElementById('flush-folder-btn');
if (flushBtn) {
    flushBtn.addEventListener('click', () => {
        const count = flushBtn.dataset.count || '0';

        const contentEl = document.createElement('div');
        contentEl.innerHTML =
            '<p>Permanently delete all <strong>' + count + '</strong> filtered file(s) from disk and remove FAL records. This cannot be undone.</p>' +
            '<p class="mb-1">Type <strong>' + count + '</strong> below to confirm:</p>' +
            '<input type="text" class="form-control" id="flush-confirm-input" autocomplete="off" />' +
            '<div class="invalid-feedback" id="flush-confirm-error">Does not match — expected ' + count + '.</div>';

        const modal = Modal.advanced({
            title: 'Delete all filtered files',
            content: contentEl,
            severity: SeverityEnum.error,
            buttons: [
                { text: 'Cancel', active: true, btnClass: 'btn-default', name: 'cancel' },
                { text: 'Delete all', btnClass: 'btn-danger', name: 'ok' },
            ],
        });

        modal.addEventListener('typo3-modal-shown', () => {
            const input = modal.querySelector('#flush-confirm-input');
            if (!input) { return; }
            input.focus();
            input.addEventListener('input', () => input.classList.remove('is-invalid'));
        });

        modal.addEventListener('button.clicked', (e) => {
            const name = e.target.getAttribute('name');
            if (name === 'cancel') {
                modal.hideModal();
                return;
            }
            if (name === 'ok') {
                const input = modal.querySelector('#flush-confirm-input');
                if (!input || input.value.trim() !== count) {
                    if (input) { input.classList.add('is-invalid'); }
                    return;
                }
                modal.hideModal();
                showLoading();
                document.getElementById('flush-folder-form').submit();
            }
        });
    });
}

// Delete selected
const deleteBtn = document.getElementById('delete-selected-btn');
if (deleteBtn) {
    deleteBtn.addEventListener('click', () => {
        const checked = document.querySelectorAll('.cleanup-unused:checked:not(:disabled)').length;
        if (checked === 0) {
            Modal.show('No selection', 'No files selected. Check at least one row.', SeverityEnum.info);
            return;
        }
        const modal = Modal.confirm(
            'Delete selected files',
            'Permanently delete ' + checked + ' selected file(s) from disk and remove FAL records. This cannot be undone.',
            SeverityEnum.error,
            [
                { text: 'Cancel', active: true, btnClass: 'btn-default', name: 'cancel' },
                { text: 'Delete ' + checked + ' file(s)', btnClass: 'btn-danger', name: 'ok' },
            ]
        );
        modal.addEventListener('button.clicked', (e) => {
            modal.hideModal();
            if (e.target.getAttribute('name') === 'ok') {
                showLoading();
                document.getElementById('cleanup-unused-form').submit();
            }
        });
    });
}
