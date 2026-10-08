import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { t } from '@anubit/filefix/labels.js';

// Resize of one image from the oversized report: dry run first (real result measured on the
// server and discarded), modal with before/after, then "Resize now". The server checks the
// file again for the real resize. Values are inserted with textContent only.
const loadingOverlay = document.getElementById('filefix-loading');
const loadingText = loadingOverlay ? loadingOverlay.querySelector('.filefix-loading-text') : null;
const setLoading = (visible, text) => {
    if (!loadingOverlay) { return; }
    if (loadingText && text) { loadingText.textContent = text; }
    loadingOverlay.classList.toggle('d-none', !visible);
    loadingOverlay.classList.toggle('d-flex', visible);
};

// Closes all modals and waits until they are gone before this frame reloads/navigates.
// TYPO3 removes a modal after its close animation; a frame reload in between leaves it on screen.
const closeModals = () => new Promise((resolve) => {
    const doc = (window.top && window.top.document) || document;
    const nodes = [...doc.querySelectorAll('typo3-backend-modal')];
    nodes.forEach((node) => { try { node.hideModal(); } catch (e) { /* already closing */ } });
    setTimeout(() => { nodes.forEach((node) => node.remove()); resolve(); }, 350);
});

const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { node.textContent = text; }
    return node;
};

const formatBytes = (bytes) => {
    const value = Number(bytes) || 0;
    if (value >= 1073741824) { return (value / 1073741824).toFixed(1) + ' GB'; }
    if (value >= 1048576) { return (value / 1048576).toFixed(1) + ' MB'; }
    return (value / 1024).toFixed(1) + ' KB';
};

const request = async (button, dryRun) => {
    const response = await fetch(button.dataset.filefixResize, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: new URLSearchParams({ file: button.dataset.filefixFile, dryRun: dryRun ? '1' : '0' }),
    });
    const result = await response.json();
    if (!response.ok && !result.status) {
        throw new Error(result.error || ('HTTP ' + response.status));
    }
    return result;
};

const renderResult = (result) => {
    const content = el('div');
    const ok = result.status === 'ok';
    content.appendChild(el('div', 'alert ' + (ok ? 'alert-info' : 'alert-warning'), ok
        ? t('over.js.testResize', result.maxWidth, result.maxHeight)
        : result.message));

    if (result.newSize !== undefined) {
        const table = el('table', 'table table-sm mb-3');
        const head = el('tr');
        ['', t('over.col.dimensions'), t('over.js.fileSize')].forEach((label) => head.appendChild(el('th', '', label)));
        table.appendChild(el('thead')).appendChild(head);
        const body = el('tbody');
        [
            [t('over.js.now'), result.width + ' × ' + result.height, formatBytes(result.size)],
            [t('over.js.after'), result.newWidth + ' × ' + result.newHeight, formatBytes(result.newSize)],
        ].forEach((cells) => {
            const tr = el('tr');
            cells.forEach((cell, i) => tr.appendChild(el(i === 0 ? 'th' : 'td', '', cell)));
            body.appendChild(tr);
        });
        table.appendChild(body);
        content.appendChild(table);
        const percent = result.size > 0 ? Math.round(result.saving / result.size * 100) : 0;
        content.appendChild(el('p', 'fw-bold', t('over.js.saving', formatBytes(result.saving), percent)));
    }
    if (ok) {
        content.appendChild(el('p', 'text-danger small mb-0',
            t('over.js.warning')));
    }
    return content;
};

document.addEventListener('click', async (e) => {
    const button = e.target.closest('[data-filefix-resize]');
    if (!button) { return; }
    setLoading(true, t('over.js.testing'));
    let result;
    try {
        result = await request(button, true);
    } catch (error) {
        setLoading(false, t('common.loading'));
        Modal.show(t('over.js.failed'), String(error.message || error), SeverityEnum.error);
        return;
    }
    setLoading(false, t('common.loading'));

    let modal = null;
    const buttons = [{ text: t('common.close'), active: true, btnClass: 'btn-default', name: 'close', trigger: () => modal.hideModal() }];
    if (result.status === 'ok') {
        buttons.push({
            text: t('over.js.resizeNow'),
            btnClass: 'btn-warning',
            name: 'resize',
            trigger: async () => {
                await closeModals();
                setLoading(true, t('over.js.resizing'));
                try {
                    const applied = await request(button, false);
                    if (applied.applied) {
                        window.location.reload();
                        return;
                    }
                    setLoading(false, t('common.loading'));
                    Modal.show(t('over.js.notResized'), applied.message || t('common.unknownError'), SeverityEnum.warning);
                } catch (error) {
                    setLoading(false, t('common.loading'));
                    Modal.show(t('over.js.failed'), String(error.message || error), SeverityEnum.error);
                }
            },
        });
    }
    modal = Modal.advanced({
        title: button.dataset.filefixTitle || t('over.js.title'),
        content: renderResult(result),
        size: Modal.sizes.medium,
        buttons: buttons,
    });
});
