import { Controller } from '@hotwired/stimulus';
import 'apexcharts'; // UMD bundle vendored from Able Pro — registers window.ApexCharts

/* stimulusFetch: 'lazy' */

/*
 * ApexCharts line chart (Able Pro's chart library — no hand-rolled SVG).
 * The template passes labels + series; each series names a CSS color TOKEN
 * (e.g. "--color-info-500") so no hex ever appears in markup. Config only —
 * rendering, tooltips and crosshair come from the library.
 *
 * data value shape:
 *   { labels: ["Feb", …], series: [{label: "Signed", token: "--color-info-500", values: [9, …]}, …] }
 */
export default class extends Controller {
    static values = { data: Object };

    connect() {
        const rootStyle = getComputedStyle(document.documentElement);
        const resolve = (token) => rootStyle.getPropertyValue(token).trim() || '#1E3A8A';
        const { labels, series } = this.dataValue;

        this.chart = new window.ApexCharts(this.element, {
            chart: {
                type: 'line',
                height: 220,
                fontFamily: 'inherit',
                toolbar: { show: false },
                zoom: { enabled: false },
            },
            colors: series.map((s) => resolve(s.token)),
            series: series.map((s) => ({ name: s.label, data: s.values })),
            stroke: { curve: 'smooth', width: 2.5 },
            dataLabels: { enabled: false },
            legend: { show: false },
            grid: { borderColor: resolve('--color-theme-border'), strokeDashArray: 4 },
            xaxis: {
                categories: labels,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { colors: resolve('--color-theme-secondarytextcolor') } },
            },
            yaxis: {
                labels: { style: { colors: resolve('--color-theme-secondarytextcolor') } },
            },
            tooltip: { shared: true, intersect: false },
        });
        this.chart.render();
    }

    disconnect() {
        this.chart?.destroy();
        this.chart = null;
    }
}
