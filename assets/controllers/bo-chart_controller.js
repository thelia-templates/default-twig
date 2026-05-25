import { Controller } from '@hotwired/stimulus';
import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    BarController,
    BarElement,
    DoughnutController,
    ArcElement,
    Tooltip,
    Filler,
} from 'chart.js';

Chart.register(
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    BarController,
    BarElement,
    DoughnutController,
    ArcElement,
    Tooltip,
    Filler,
);

const BRAND = '#f26041';
const BRAND_SOFT = 'rgba(242, 96, 65, 0.15)';
const GRID = 'rgba(0, 0, 0, 0.06)';

export default class extends Controller {
    static values = {
        type: { type: String, default: 'line' },
        labels: Array,
        data: Array,
        label: String,
        format: { type: String, default: 'number' }, // 'number' | 'currency'
        colors: Array,
    };

    connect() {
        const ctx = this.element.getContext('2d');
        const fmt = (v) => this.format(v);

        this.chart = new Chart(ctx, {
            type: this.typeValue,
            data: this.buildData(),
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: (ctx) => ` ${this.labelValue || ''} ${fmt(ctx.parsed.y ?? ctx.parsed)}` },
                    },
                },
                scales: this.typeValue === 'doughnut'
                    ? {}
                    : {
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: {
                            grid: { color: GRID, drawBorder: false },
                            ticks: { font: { size: 11 }, callback: (v) => fmt(v) },
                            beginAtZero: true,
                        },
                    },
            },
        });
    }

    disconnect() {
        this.chart?.destroy();
    }

    buildData() {
        if (this.typeValue === 'doughnut') {
            return {
                labels: this.labelsValue,
                datasets: [{
                    data: this.dataValue,
                    backgroundColor: this.colorsValue.length ? this.colorsValue : [BRAND, '#5cb85c', '#5bc0de', '#f39922', '#986dff', '#6c757d'],
                    borderWidth: 0,
                }],
            };
        }

        return {
            labels: this.labelsValue,
            datasets: [{
                label: this.labelValue,
                data: this.dataValue,
                borderColor: BRAND,
                backgroundColor: this.typeValue === 'bar' ? BRAND : BRAND_SOFT,
                borderWidth: 2,
                fill: this.typeValue === 'line',
                tension: 0.3,
                pointRadius: 2,
                pointHoverRadius: 4,
            }],
        };
    }

    format(v) {
        const locale = (document.documentElement.lang || 'fr-FR').replace('_', '-');
        if (this.formatValue === 'currency') {
            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: 'EUR',
                maximumFractionDigits: 0,
            }).format(v);
        }
        return new Intl.NumberFormat(locale).format(v);
    }
}
