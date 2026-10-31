/**
 * Graphiques du tableau de bord (Chart.js), exposés à Alpine sous x-data="chart(type, data)".
 * Chaque graphique est recréé quand Livewire remplace son élément (wire:key dépendant des données).
 */
import Chart from 'chart.js/auto';

const PALETTE = ['#047857', '#0284c7', '#d97706', '#7c3aed', '#e11d48', '#64748b'];
const money = (v) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(v) + ' MRU';
const number = (v) => new Intl.NumberFormat('fr-FR').format(v);

Chart.defaults.font.family = getComputedStyle(document.documentElement).fontFamily || 'system-ui, sans-serif';
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;

const grid = { color: '#f1f5f9' };
const noGrid = { display: false };

const builders = {
    // Chiffre d'affaires (aire) et tickets (barres) dans le temps
    trend: (d) => ({
        data: {
            labels: d.map((r) => r.label),
            datasets: [
                {
                    type: 'line', label: "Chiffre d'affaires", data: d.map((r) => r.revenue), yAxisID: 'y',
                    borderColor: PALETTE[0], borderWidth: 2.5, tension: 0.35, fill: true, pointRadius: d.length > 31 ? 0 : 3,
                    pointHoverRadius: 5, pointBackgroundColor: '#fff',
                    backgroundColor: (ctx) => {
                        const { chartArea, ctx: c } = ctx.chart;
                        if (!chartArea) return 'rgba(4,120,87,.1)';
                        const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        g.addColorStop(0, 'rgba(4,120,87,.25)');
                        g.addColorStop(1, 'rgba(4,120,87,0)');
                        return g;
                    },
                },
                {
                    type: 'bar', label: 'Tickets', data: d.map((r) => r.tickets), yAxisID: 'y1',
                    backgroundColor: 'rgba(2,132,199,.18)', hoverBackgroundColor: 'rgba(2,132,199,.4)', borderRadius: 4, maxBarThickness: 18,
                },
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: noGrid, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                y: { grid, beginAtZero: true, ticks: { callback: (v) => number(v) } },
                y1: { position: 'right', grid: noGrid, beginAtZero: true, ticks: { precision: 0 } },
            },
            plugins: { tooltip: { callbacks: { label: (c) => c.dataset.yAxisID === 'y' ? ` ${money(c.raw)}` : ` ${c.raw} ticket(s)` } } },
        },
    }),

    // Affluence par heure
    hours: (d) => {
        const max = Math.max(...d.map((r) => r.tickets), 1);
        return {
            type: 'bar',
            data: {
                labels: d.map((r) => r.label),
                datasets: [{
                    label: 'Tickets', data: d.map((r) => r.tickets), borderRadius: 6, maxBarThickness: 26,
                    backgroundColor: d.map((r) => `rgba(4,120,87,${0.15 + 0.75 * (r.tickets / max)})`),
                }],
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => ` ${c.raw} ticket(s) · ${money(d[c.dataIndex].revenue)}` } },
                },
                scales: { x: { grid: noGrid }, y: { grid, beginAtZero: true, ticks: { precision: 0 } } },
            },
        };
    },

    // Meilleures ventes (barres horizontales)
    ranking: (d) => ({
        type: 'bar',
        data: {
            labels: d.map((r) => r.label.length > 28 ? r.label.slice(0, 27) + '…' : r.label),
            datasets: [{ label: "Chiffre d'affaires", data: d.map((r) => r.value), backgroundColor: PALETTE[0], hoverBackgroundColor: '#065f46', borderRadius: 6, maxBarThickness: 22 }],
        },
        options: {
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { title: (c) => d[c[0].dataIndex].label, label: (c) => [` ${money(c.raw)}`, ` Marge ${money(d[c.dataIndex].margin)}`] } },
            },
            scales: { x: { grid, beginAtZero: true, ticks: { callback: (v) => number(v) } }, y: { grid: noGrid } },
        },
    }),

    // Répartitions (anneau)
    share: (d) => {
        const total = d.reduce((s, r) => s + r.value, 0) || 1;
        return {
            type: 'doughnut',
            data: {
                labels: d.map((r) => r.label),
                datasets: [{ data: d.map((r) => r.value), backgroundColor: PALETTE, borderColor: '#fff', borderWidth: 3, hoverOffset: 6 }],
            },
            options: {
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (c) => ` ${c.label} : ${money(c.raw)} (${Math.round(c.raw / total * 100)} %)` } },
                },
            },
        };
    },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('chart', (kind, data) => ({
        instance: null,
        init() {
            const config = builders[kind](data);
            config.type ??= 'bar';
            config.options = { responsive: true, maintainAspectRatio: false, animation: { duration: 500 }, ...config.options };
            this.instance = new Chart(this.$refs.canvas, config);
        },
        destroy() {
            this.instance?.destroy();
        },
    }));
});
