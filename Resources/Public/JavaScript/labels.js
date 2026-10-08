// Translated labels, rendered by the controller into <div id="filefix-labels" data-labels="{...}">.
// Placeholders as in the XLF files: %s, %1$s, %2$s …, %% for a literal percent sign.
let labels = null;

const load = () => {
    if (labels === null) {
        const node = document.getElementById('filefix-labels');
        try {
            labels = node ? JSON.parse(node.dataset.labels || '{}') : {};
        } catch (e) {
            labels = {};
        }
    }
    return labels;
};

export const t = (key, ...args) => {
    const label = load()[key] ?? key;
    let position = 0;
    return label.replace(/%(?:(\d+)\$)?([sd%])/g, (match, index, type) => {
        if (type === '%') { return '%'; }
        const value = index !== undefined ? args[Number(index) - 1] : args[position++];
        return value === undefined ? match : String(value);
    });
};
