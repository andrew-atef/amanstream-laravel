import './bootstrap';
import Chart from 'chart.js/auto';

// ربط الرسم البياني لـ [price_history] — يعمل لكل canvas يحمل data-ph-label/data-ph-price.
function renderPriceHistoryCharts() {
    document.querySelectorAll('canvas[data-ph-chart]').forEach((el) => {
        const chartId = el.dataset.phChart;
        const labels = JSON.parse(el.dataset.phLabels || '[]');
        const prices = JSON.parse(el.dataset.phPrices || '[]');

        if (!labels.length || window.__phChartInit?.[chartId]) {
            return;
        }

        window.__phChartInit = window.__phChartInit || {};
        window.__phChartInit[chartId] = true;

        new Chart(el, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'السعر',
                    data: prices,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.10)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#1d4ed8',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 1.5,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        rtl: true,
                        callbacks: {
                            label: (ctx) =>
                                ' السعر: ' +
                                Number(ctx.parsed.y).toLocaleString('en-US', { maximumFractionDigits: 2 }) +
                                ' ج.م',
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#64748b', font: { size: 11 } },
                    },
                    y: {
                        grid: { color: 'rgba(100, 116, 139, 0.15)' },
                        ticks: {
                            color: '#64748b',
                            font: { size: 11 },
                            callback: (value) => Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 }),
                        },
                    },
                },
            },
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderPriceHistoryCharts);
} else {
    renderPriceHistoryCharts();
}