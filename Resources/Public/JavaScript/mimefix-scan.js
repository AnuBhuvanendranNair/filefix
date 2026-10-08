import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import { t } from '@anubit/filefix/labels.js';

// Loading overlay (same as File Cleanup module) for "Scan again" and the fix submit
const loadingOverlay = document.getElementById('filefix-loading');
function showLoading() {
    if (loadingOverlay) {
        loadingOverlay.classList.remove('d-none');
        loadingOverlay.classList.add('d-flex');
    }
}
// Back/forward cache restores the page with the overlay still visible
window.addEventListener('pageshow', (e) => {
    if (e.persisted && loadingOverlay) {
        loadingOverlay.classList.add('d-none');
        loadingOverlay.classList.remove('d-flex');
    }
});
document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href*="mimefix_scan"]');
    if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    showLoading();
});

const selectBtn = document.getElementById('mimefix-select-all');
const label     = document.getElementById('mimefix-select-label');
if (selectBtn && label) {
    const selectAllText = label.textContent.trim();
    selectBtn.addEventListener('click', () => {
        const checkboxes = document.querySelectorAll('.mimefix-checkbox');
        const allChecked = Array.from(checkboxes).every((cb) => cb.checked);
        checkboxes.forEach((cb) => { cb.checked = !allChecked; });
        label.textContent = allChecked ? selectAllText : t('clean.deselectAll');
    });
}

const fixBtn = document.getElementById('mimefix-fix-btn');
const form   = document.getElementById('mimefixFixForm');
if (fixBtn && form) {
    fixBtn.addEventListener('click', () => {
        const checked = document.querySelectorAll('.mimefix-checkbox:checked').length;
        if (checked === 0) {
            Modal.confirm(
                t('mime.js.noFiles'),
                t('mime.js.noFilesText'),
                Severity.notice,
                [{ text: t('common.ok'), btnClass: 'btn-default', name: 'ok' }]
            );
            return;
        }
        Modal.confirm(
            t('mime.js.fixTitle'),
            t('mime.js.fixText', checked),
            Severity.warning,
            [
                { text: t('common.cancel'), btnClass: 'btn-default', name: 'cancel' },
                {
                    text: t('mime.js.fix'),
                    btnClass: 'btn-warning',
                    name: 'ok',
                    trigger: () => { Modal.dismiss(); showLoading(); form.submit(); }
                }
            ]
        );
    });
}
