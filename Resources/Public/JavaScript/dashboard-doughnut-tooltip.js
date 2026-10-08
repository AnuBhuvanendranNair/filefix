// Chart.js plugin for the filefix doughnut widgets: tooltip "jpg (4.5 GB)" / "jpg (3829 files)".
// Tooltip callbacks are functions, so they cannot come with the JSON chart config from PHP.
// Registered globally on the core Chart.js instance (same module in v12, v13, v14), but only
// active for charts that set options.plugins.filefixTooltip.unit — core charts are not touched.
import { Chart } from '@typo3/dashboard/contrib/chartjs.js';

// Labels come translated from PHP: filesLabel "%s files" / "%s Dateien", decimalSeparator "." / ","
const formatValue = (value, options) => {
    if (options.unit !== 'bytes') {
        return (options.filesLabel || '%s files').replace('%s', String(value));
    }
    const gigabytes = value / 1073741824;
    const formatted = gigabytes >= 1 ? gigabytes.toFixed(1) + ' GB' : (value / 1048576).toFixed(1) + ' MB';
    return formatted.replace('.', options.decimalSeparator || '.');
};

Chart.register({
    id: 'filefixTooltip',

    beforeInit(chart, args, options) {
        if (!options || !options.unit) { return; }
        const plugins = chart.config.options.plugins || (chart.config.options.plugins = {});
        plugins.tooltip = Object.assign({}, plugins.tooltip, {
            callbacks: {
                title: () => '',
                label: (context) => context.label + ' (' + formatValue(context.raw, options) + ')',
            },
        });
    },
});
