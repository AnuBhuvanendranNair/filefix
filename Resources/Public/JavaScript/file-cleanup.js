import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

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
        const checkboxes = document.querySelectorAll('.' + checkboxClass);
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
                document.getElementById('flush-folder-form').submit();
            }
        });
    });
}

// Delete selected
const deleteBtn = document.getElementById('delete-selected-btn');
if (deleteBtn) {
    deleteBtn.addEventListener('click', () => {
        const checked = document.querySelectorAll('.cleanup-unused:checked').length;
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
                document.getElementById('cleanup-unused-form').submit();
            }
        });
    });
}
