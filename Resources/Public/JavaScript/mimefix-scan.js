import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

const selectBtn = document.getElementById('mimefix-select-all');
const label     = document.getElementById('mimefix-select-label');
if (selectBtn && label) {
    const selectAllText = label.textContent.trim();
    selectBtn.addEventListener('click', () => {
        const checkboxes = document.querySelectorAll('.mimefix-checkbox');
        const allChecked = Array.from(checkboxes).every((cb) => cb.checked);
        checkboxes.forEach((cb) => { cb.checked = !allChecked; });
        label.textContent = allChecked ? selectAllText : 'Deselect all';
    });
}

const fixBtn = document.getElementById('mimefix-fix-btn');
const form   = document.getElementById('mimefixFixForm');
if (fixBtn && form) {
    fixBtn.addEventListener('click', () => {
        const checked = document.querySelectorAll('.mimefix-checkbox:checked').length;
        if (checked === 0) {
            Modal.confirm(
                'No files selected',
                'Select at least one file before fixing.',
                Severity.notice,
                [{ text: 'OK', btnClass: 'btn-default', name: 'ok' }]
            );
            return;
        }
        Modal.confirm(
            'Fix selected files',
            'Process ' + checked + ' file(s): convert content and/or update sys_file records. Continue?',
            Severity.warning,
            [
                { text: 'Cancel', btnClass: 'btn-default', name: 'cancel' },
                {
                    text: 'Fix',
                    btnClass: 'btn-warning',
                    name: 'ok',
                    trigger: () => { Modal.dismiss(); form.submit(); }
                }
            ]
        );
    });
}
