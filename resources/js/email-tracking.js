import ApexCharts from 'apexcharts';
import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import '../css/email-tracking.css';

const dataNode = document.getElementById('email-tracking-data');

if (dataNode) {
    const report = JSON.parse(dataNode.textContent);
    const colours = ['#3da7c7', '#3b9561', '#c84653', '#d99a2b', '#9d4f62', '#7758b3', '#3976b6'];
    const isDark = () => document.documentElement.dataset.theme === 'dark';
    const theme = () => ({
        mode: isDark() ? 'dark' : 'light',
        palette: 'palette1',
        monochrome: { enabled: false },
    });
    const base = {
        chart: {
            type: 'line',
            height: 390,
            background: 'transparent',
            animations: { enabled: true },
            toolbar: { show: true, tools: { download: true, selection: false, zoom: true, zoomin: true, zoomout: true, pan: true, reset: true } },
        },
        colors: colours,
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        markers: { size: 2, hover: { size: 5 } },
        legend: { position: 'bottom', horizontalAlign: 'left' },
        grid: { borderColor: isDark() ? '#374151' : '#d8dee6' },
        theme: theme(),
        xaxis: {
            type: 'datetime',
            categories: report.days.map(day => `${day}T00:00:00Z`),
            labels: { datetimeUTC: true },
        },
        tooltip: { shared: true, intersect: false, x: { format: 'dd MMM yyyy' } },
        noData: { text: 'No SES data for this period' },
    };
    const volume = new ApexCharts(document.querySelector('#email-volume-chart'), {
        ...base,
        series: report.volume,
        yaxis: { min: 0, forceNiceScale: true, decimalsInFloat: 0, title: { text: 'Events' } },
    });
    const rate = new ApexCharts(document.querySelector('#email-rate-chart'), {
        ...base,
        series: report.rates,
        yaxis: { min: 0, max: 100, tickAmount: 5, labels: { formatter: value => `${value.toFixed(0)}%` }, title: { text: 'Rate' } },
        tooltip: { ...base.tooltip, y: { formatter: value => value === null ? 'No data' : `${value.toFixed(2)}%` } },
    });
    Promise.all([volume.render(), rate.render()]);

    new MutationObserver(() => {
        const options = { theme: theme(), grid: { borderColor: isDark() ? '#374151' : '#d8dee6' } };
        volume.updateOptions(options, false, false);
        rate.updateOptions(options, false, false);
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    const eventTable = document.getElementById('email-events-table');
    if (eventTable) {
        const eventType = document.getElementById('email-event-type');
        const badge = value => `<span class="ses-event-status ses-event-status--${value.toLowerCase().replace(/[^a-z]+/g, '-')}">${value}</span>`;
        const table = new DataTable(eventTable, {
        processing: true, serverSide: true, pageLength: 25, order: [[5, 'desc']],
        ajax: { url: eventTable.dataset.eventsUrl, cache: false, data: data => { data.event_type = eventType.value; } },
        columns: [{ data: 0 }, { data: 1 }, { data: 2, render: (value, type) => type === 'display' ? badge(value) : value }, { data: 3 }, { data: 4 }, { data: 5 }],
        columnDefs: [{ targets: 5, render: (value, type, row) => type === 'sort' ? row[6] : value }],
        language: { emptyTable: report.eventTopicReady ? 'No SES email events have arrived yet.' : 'SES event publishing is not configured yet.', zeroRecords: 'No matching email events.' },
        });
        eventType.addEventListener('change', () => table.ajax.reload());
    }
}
