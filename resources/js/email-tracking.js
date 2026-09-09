import ApexCharts from 'apexcharts';
import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import 'datatables.net-responsive-dt';
import 'datatables.net-responsive-dt/css/responsive.dataTables.css';
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
        const search = document.getElementById('email-event-search');
        const loadMore = document.getElementById('email-events-load-more');
        const dialog = document.getElementById('email-event-detail-dialog');
        const badge = value => `<span class="ses-event-status ses-event-status--${value.toLowerCase().replace(/[^a-z]+/g, '-')}">${value}</span>`;
        const pageSize = 25;
        let offset = 0;
        let controller;
        let searchTimer;
        const table = new DataTable(eventTable, {
        paging: false, searching: false, info: false, order: [[3, 'desc']], autoWidth: false,
        responsive: { details: { type: 'inline' } },
        columns: [{ data: 0 }, { data: 1 }, { data: 2, render: (value, type) => type === 'display' ? badge(value) : value }, { data: 3 }, {
            data: null, orderable: false, searchable: false, render: (value, type) => type === 'display' ? '<button type="button" class="email-event-detail-button">View detail</button>' : '',
        }],
        columnDefs: [
            { targets: 0, responsivePriority: 1 },
            { targets: 1, responsivePriority: 3 },
            { targets: 2, responsivePriority: 2 },
            { targets: 3, render: (value, type, row) => type === 'sort' ? row[4] : value, responsivePriority: 10 },
            { targets: 4, responsivePriority: 1 },
        ],
        language: { emptyTable: report.eventTopicReady ? 'No SES email events have arrived yet.' : 'SES event publishing is not configured yet.', zeroRecords: 'No matching email events.' },
        });
        const loadEvents = async (replace = false) => {
            controller?.abort();
            controller = new AbortController();
            if (replace) { offset = 0; table.clear().draw(); }
            loadMore.disabled = true;
            const [orderColumn, orderDirection] = table.order()[0] || [3, 'desc'];
            const url = new URL(eventTable.dataset.eventsUrl, window.location.origin);
            url.search = new URLSearchParams({ start: String(offset), length: String(pageSize), event_type: eventType.value,
                'search[value]': search.value, 'order[0][column]': String(orderColumn), 'order[0][dir]': orderDirection });
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
                if (!response.ok) throw new Error('Email events are unavailable. Please try again.');
                const data = await response.json();
                table.rows.add(data.data).draw(false);
                offset += data.data.length;
                loadMore.hidden = !data.hasMore;
            } catch (error) {
                if (error.name !== 'AbortError') loadMore.hidden = true;
            } finally { loadMore.disabled = false; }
        };
        eventType.addEventListener('change', () => loadEvents(true));
        search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadEvents(true), 300); });
        loadMore.addEventListener('click', () => loadEvents());
        eventTable.addEventListener('click', event => {
            const button = event.target.closest('.email-event-detail-button');
            if (!button) return;
            const buttonRow = button.closest('tr');
            const row = table.row(buttonRow.classList.contains('child') ? buttonRow.previousElementSibling : buttonRow).data();
            if (!row) return;
            dialog.querySelector('[data-detail-recipient]').textContent = row[0];
            dialog.querySelector('[data-detail-subject]').textContent = row[1];
            dialog.querySelector('[data-detail-status]').textContent = row[2];
            dialog.querySelector('[data-detail-time]').textContent = row[3];
            dialog.querySelector('[data-detail-message]').textContent = row[5];
            dialog.querySelector('[data-detail-source]').textContent = row[6];
            dialog.querySelector('[data-detail-ses-message-id]').textContent = row[7];
            dialog.querySelector('[data-detail-sns-message-id]').textContent = row[8];
            dialog.showModal();
        });
        dialog.querySelector('[data-close-email-detail]').addEventListener('click', () => dialog.close());
        loadEvents(true);
    }
}
