(() => {
    'use strict';
    const container = document.getElementById('analytics-content');
    const status = document.getElementById('analytics-status');
    const period = document.getElementById('analyticsPeriod');
    let charts = [];
    let request = null;
    const colors = ['#087f73', '#5276cc', '#25a18e', '#8972bd', '#ca667a'];
    function renderCharts() {
        const source = document.getElementById('analytics-chart-data');
        if (!source || !window.Chart) return;
        const data = JSON.parse(source.textContent);
        const makeChart = (id, type, labels, datasets) => {
            const canvas = document.getElementById(id);
            if (!canvas) return;
            charts.push(new Chart(canvas, {
                type, data: { labels, datasets },
                options: { responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } },
                    ...(type === 'doughnut' ? {} : { scales: { y: { beginAtZero: true } } }) }
            }));
        };
        const line = (label, values, color) => ({ label, data: values, borderColor: color, backgroundColor: color, tension: 0.25 });
        makeChart('growthChart', 'line', data.labels, [line('New caterers', data.series.caterers, colors[0]), line('Active users created', data.series.users, colors[1])]);
        makeChart('roleChart', 'doughnut', data.roles.map(row => row.label.replaceAll('_', ' ')), [{ data: data.roles.map(row => Number(row.total)), backgroundColor: colors }]);
        makeChart('revenueChart', 'line', data.labels, [line(`Collections (${data.currency})`, data.series.payments, colors[2]), line(`Booking value (${data.currency})`, data.series.bookings, colors[0])]);
    }
    async function refresh() {
        if (document.hidden) return;
        if (request) request.abort();
        const controller = new AbortController();
        request = controller;
        const timer = setTimeout(() => controller.abort(), 20000);
        const selected = period.value;
        status.textContent = 'Refreshing analytics…';
        try {
            const url = new URL(window.location.href);
            url.search = new URLSearchParams({ period: selected, format: 'fragment' });
            const response = await fetch(url, { signal: controller.signal, cache: 'no-store', credentials: 'same-origin' });
            if (response.redirected) throw new Error('Your session has expired. Reload the page to sign in.');
            if (!response.ok) throw new Error('CMS refresh failed. The last successful snapshot remains below.');
            const html = await response.text();
            if (request !== controller) return;
            if (!html.includes('id="analytics-chart-data"')) throw new Error('Analytics could not be loaded. Please reload the page.');
            charts.forEach(chart => chart.destroy()); charts = [];
            container.innerHTML = html;
            renderCharts();
            const pageUrl = new URL(window.location.href);
            pageUrl.search = new URLSearchParams({ period: selected });
            history.replaceState(null, '', pageUrl);
            status.textContent = '';
        } catch (error) {
            if (request === controller) status.textContent = error.name === 'AbortError' ? 'Refresh timed out. Retrying automatically.' : error.message;
        } finally {
            clearTimeout(timer);
            if (request === controller) request = null;
        }
    }
    period.addEventListener('change', refresh);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    setInterval(() => { if (!request) refresh(); }, 30000);
    renderCharts();
})();
