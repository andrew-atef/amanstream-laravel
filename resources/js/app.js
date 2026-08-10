import './bootstrap';

// منيو الموبايل: تبديل فتح/إغلاق وتحديث الأيقونة وحالة aria.
const menuToggle = document.querySelector('[data-mobile-menu-toggle]');
const mobileNav = document.getElementById('mobile-nav');

if (menuToggle && mobileNav) {
    const openIcon = menuToggle.querySelector('[data-mobile-menu-icon="open"]');
    const closeIcon = menuToggle.querySelector('[data-mobile-menu-icon="close"]');

    menuToggle.addEventListener('click', () => {
        const isOpen = ! mobileNav.classList.contains('hidden');

        mobileNav.classList.toggle('hidden', isOpen);
        openIcon.classList.toggle('hidden', ! isOpen);
        closeIcon.classList.toggle('hidden', isOpen);
        menuToggle.setAttribute('aria-expanded', String(! isOpen));
    });
}

// ربط الرسم البياني لـ [price_history] — يُحمَّل chart.js عند الطلب فقط، حتى لا
// نُحمّل ~70KB على صفحات لا تحتوي على رسم بياني.
const renderedCharts = new WeakSet();

async function renderPriceHistoryCharts() {
    const canvases = Array.from(document.querySelectorAll('canvas[data-ph-chart]'));
    const pending = canvases.filter((el) => {
        const labels = JSON.parse(el.dataset.phLabels || '[]');
        const prices = JSON.parse(el.dataset.phPrices || '[]');

        return labels.length && prices.length && ! renderedCharts.has(el);
    });

    if (! pending.length) {
        return;
    }

    const { default: Chart } = await import('chart.js/auto');

    pending.forEach((el) => {
        const labels = JSON.parse(el.dataset.phLabels || '[]');
        const prices = JSON.parse(el.dataset.phPrices || '[]');

        if (renderedCharts.has(el)) {
            return;
        }

        renderedCharts.add(el);

        new Chart(el, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'السعر',
                    data: prices,
                    borderColor: '#155dfc',
                    backgroundColor: 'rgba(21, 93, 252, 0.10)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#1247c9',
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