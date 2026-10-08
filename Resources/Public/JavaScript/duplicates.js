import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { t } from '@anubit/filefix/labels.js';

// Loading overlay for every navigation back to the server (paging, back to file list,
// record editing). Not for the CSV export (download, page stays) and not for new tabs.
const loadingOverlay = document.getElementById('filefix-loading');
function setLoading(visible) {
    if (loadingOverlay) {
        loadingOverlay.classList.toggle('d-none', !visible);
        loadingOverlay.classList.toggle('d-flex', visible);
    }
}
// Settings forms (oversized image report) reload the report as well
document.addEventListener('submit', (e) => {
    if (e.target.closest('form[data-filefix-loading-form]')) { setLoading(true); }
});
// Back/forward cache restores the page with the overlay still visible
window.addEventListener('pageshow', (e) => { if (e.persisted) { setLoading(false); } });

document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href]');
    if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    const href = link.getAttribute('href') || '';
    if (href === '' || href.startsWith('#') || href.indexOf('format=csv') !== -1 || link.hasAttribute('download')) { return; }
    if (link.target && link.target !== '_self') { return; }
    setLoading(true);
});

// Closes all modals and waits until they are gone before this frame reloads/navigates.
// TYPO3 removes a modal after its close animation; a frame reload in between leaves it on screen.
const closeModals = () => new Promise((resolve) => {
    const doc = (window.top && window.top.document) || document;
    const nodes = [...doc.querySelectorAll('typo3-backend-modal')];
    nodes.forEach((node) => { try { node.hideModal(); } catch (e) { /* already closing */ } });
    setTimeout(() => { nodes.forEach((node) => node.remove()); resolve(); }, 350);
});

// TYPO3 renders modals in the top frame, so plain links in them would replace the whole backend:
// edit links are opened in this module's frame instead (returnUrl leads back to the report).
const openEditLinksInModuleFrame = (content) => {
    content.addEventListener('click', async (event) => {
        const link = event.target.closest('a[data-filefix-edit]');
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey) { return; }
        event.preventDefault();
        await closeModals();
        setLoading(true);
        window.location.href = link.href;
    });
};

// Usage count opens a modal with the usages of that copy (content from a <template> per copy).
// TYPO3 renders modals in the top frame, so plain links in it would replace the whole backend:
// edit links are opened in this module's frame instead; their returnUrl leads back to the report.
document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-filefix-usages]');
    if (!button) { return; }
    const template = document.getElementById(button.dataset.filefixUsages);
    if (!template) { return; }
    const content = document.createElement('div');
    content.appendChild(template.content.cloneNode(true));
    openEditLinksInModuleFrame(content);
    Modal.advanced({
        title: button.dataset.filefixTitle || '',
        content: content,
        size: Modal.sizes.medium,
        buttons: [
            { text: t('common.close'), active: true, btnClass: 'btn-default', name: 'close', trigger: () => Modal.dismiss() },
        ],
    });
});

// Deep check: live search of the whole database for usages of one file (AJAX route
// ajax_filefix_deepcheck). Result is built with textContent only (record titles are user data).
const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { node.textContent = text; }
    return node;
};

const renderTable = (title, rows, columns) => {
    const section = el('div', 'mb-3');
    section.appendChild(el('h6', 'mb-1', title + ' (' + rows.length + ')'));
    if (rows.length === 0) {
        section.appendChild(el('p', 'text-body-secondary small mb-0', t('deep.noneFound')));
        return section;
    }
    const table = el('table', 'table table-sm table-hover mb-0 small');
    const head = el('tr');
    columns.forEach(([label]) => head.appendChild(el('th', '', label)));
    table.appendChild(el('thead')).appendChild(head);
    const body = el('tbody');
    rows.forEach((row) => {
        const tr = el('tr');
        columns.forEach(([, render]) => {
            const td = el('td');
            const value = render(row);
            if (value instanceof Node) { td.appendChild(value); } else { td.textContent = value; }
            tr.appendChild(td);
        });
        body.appendChild(tr);
    });
    table.appendChild(body);
    const wrap = el('div', 'table-fit mb-0');
    wrap.appendChild(table);
    section.appendChild(wrap);
    return section;
};

const notes = (row, deletedKey) => {
    const span = el('span');
    const add = (text, cls) => { span.appendChild(el('span', 'badge ' + cls + ' me-1', text)); };
    if (row[deletedKey]) { add(t('deep.deletedRecord'), 'badge-default'); }
    if (row.workspace) { add(t('common.workspace', row.workspace), 'badge-warning'); }
    if (row.language) { add(t('common.lang', row.language), 'badge-default'); }
    if (row.hidden) { add(t('common.hidden'), 'badge-default'); }
    if (row.kind) { add(row.kind, 'badge-info'); }
    return span;
};

const record = (row) => {
    const label = (row.recordTitle ? row.recordTitle + ' ' : '') + (row.recordUid ? '[' + row.recordUid + ']' : '');
    if (!row.editUrl) { return label; }
    const url = new URL(row.editUrl, window.location.origin);
    url.searchParams.set('returnUrl', window.location.href);
    const link = el('a');
    // Icon markup rendered server-side (core:icon) in a <template>: the icon web component
    // is not loaded in this module frame
    const iconTemplate = document.getElementById('filefix-edit-icon');
    if (iconTemplate) { link.appendChild(iconTemplate.content.cloneNode(true)); }
    link.appendChild(document.createTextNode(' ' + label));
    link.href = url.toString();
    link.title = t('common.editRecord');
    link.setAttribute('data-filefix-edit', '1');
    return link;
};

const renderDeepCheck = (result) => {
    const content = el('div');
    openEditLinksInModuleFrame(content);
    const usages = result.activeUsages;
    content.appendChild(el(
        'div',
        'alert ' + (result.safeToDelete ? 'alert-success' : 'alert-danger'),
        result.safeToDelete
            ? t('deep.safe')
            : t('deep.inUse', usages)
    ));
    if (result.otherRecords && result.otherRecords.length) {
        content.appendChild(renderTable(t('deep.otherRecords'), result.otherRecords, [
            [t('deep.sysFileUid'), (r) => String(r.recordUid)], [t('common.path'), (r) => r.path || r.recordTitle],
        ]));
    }
    content.appendChild(renderTable(t('deep.references'), result.references, [
        [t('deep.table'), (r) => r.table], [t('dup.usages.field'), (r) => r.field], [t('dup.usages.record'), record], [t('deep.notes'), (r) => notes(r, 'parentDeleted')],
    ]));
    content.appendChild(renderTable(t('deep.refindex'), result.indexEntries, [
        [t('deep.table'), (r) => r.table], [t('dup.usages.field'), (r) => r.field], [t('dup.usages.record'), record], [t('deep.notes'), (r) => notes(r, 'deleted')],
    ]));
    content.appendChild(renderTable(t('deep.textSearch'), result.textMatches, [
        [t('deep.table'), (r) => r.table], [t('dup.usages.field'), (r) => r.field], [t('dup.usages.record'), record], [t('deep.notes'), (r) => notes(r, 'deleted')],
    ]));
    if (result.textTruncated) {
        content.appendChild(el('p', 'text-warning small', t('deep.textTruncated')));
    }
    const info = el('div', 'text-body-secondary small');
    info.appendChild(el('div', '', t('deep.searchedFor', result.needles.join(' · '))));
    info.appendChild(el('div', '', t('deep.searchedTables', result.searchedTables)));
    const skipped = el('details');
    skipped.appendChild(el('summary', '', t('deep.skippedTables', result.skippedTables.length)));
    skipped.appendChild(el('div', '', result.skippedTables.join(', ')));
    info.appendChild(skipped);
    info.appendChild(el('div', '', t('deep.notCovered')));
    content.appendChild(info);
    return content;
};

// Delete after a passed deep check: second confirmation, then POST. The server runs the
// deep check again and refuses when the file is in use; the result here is not trusted.
const deleteFile = (button, identifier) => {
    const confirm = Modal.confirm(
        t('deep.deleteFile'),
        t('deep.deleteConfirm', identifier),
        SeverityEnum.error,
        [
            { text: t('common.cancel'), active: true, btnClass: 'btn-default', name: 'cancel' },
            { text: t('deep.deleteFile'), btnClass: 'btn-danger', name: 'delete' },
        ]
    );
    confirm.addEventListener('button.clicked', async (event) => {
        const name = event.target.getAttribute('name');
        if (name !== 'delete') { confirm.hideModal(); return; }
        await closeModals();
        const loadingText = loadingOverlay ? loadingOverlay.querySelector('.filefix-loading-text') : null;
        if (loadingText) { loadingText.textContent = t('deep.deleting'); }
        setLoading(true);
        try {
            const response = await fetch(button.dataset.filefixDelete, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ file: button.dataset.filefixFile }),
            });
            const result = await response.json();
            if (response.ok && result.deleted) {
                window.location.reload();
                return;
            }
            setLoading(false);
            Modal.show(t('deep.notDeleted'), result.error || t('common.unknownError'), SeverityEnum.error);
        } catch (error) {
            setLoading(false);
            Modal.show(t('deep.notDeleted'), String(error), SeverityEnum.error);
        }
    });
};

document.addEventListener('click', async (e) => {
    const button = e.target.closest('[data-filefix-deepcheck]');
    if (!button) { return; }
    // Own class: the first <span> in the overlay belongs to the spinner icon markup
    const loadingText = loadingOverlay ? loadingOverlay.querySelector('.filefix-loading-text') : null;
    const previousText = loadingText ? loadingText.textContent : '';
    if (loadingText) { loadingText.textContent = t('deep.searching'); }
    setLoading(true);
    let content;
    let result = null;
    try {
        const response = await fetch(button.dataset.filefixDeepcheck, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        result = await response.json();
        content = response.ok && result.found
            ? renderDeepCheck(result)
            : el('div', 'alert alert-danger', result.error || t('common.error.fileNotFound'));
    } catch (error) {
        content = el('div', 'alert alert-danger', t('deep.failed', error));
    } finally {
        setLoading(false);
        if (loadingText) { loadingText.textContent = previousText; }
    }
    Modal.advanced({
        title: button.dataset.filefixTitle || t('deep.title'),
        content: content,
        size: Modal.sizes.large,
        buttons: [
            { text: t('common.close'), active: true, btnClass: 'btn-default', name: 'close', trigger: () => Modal.dismiss() },
        ].concat(result && result.found && result.safeToDelete && button.dataset.filefixDelete ? [
            { text: t('deep.deleteFile'), btnClass: 'btn-danger', name: 'delete', trigger: () => deleteFile(button, result.identifier) },
        ] : []),
    });
});
