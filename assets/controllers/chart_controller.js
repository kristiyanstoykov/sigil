import { Controller } from '@hotwired/stimulus';

/*
 * Small multi-series line chart (dashboard "Documents · last 6 months").
 * Data comes as JSON via data-chart-data-value:
 *   { labels: ["Feb", …], series: [{ label, color, values: [...] }, …] }
 *
 * The controller renders the SVG at the element's real pixel size (re-rendered
 * on resize, so no viewBox distortion) and drives its own tooltip: a crosshair
 * snapped to the nearest month, shown instantly on mousemove - no native
 * title-attribute delay.
 */
export default class extends Controller {
    static values = { data: Object };
    static targets = ['plot', 'tooltip', 'crosshair'];

    connect() {
        this.render();
        this.onResize = () => this.render();
        window.addEventListener('resize', this.onResize);
    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
    }

    // x position of month i, matching the flex-1 label columns underneath
    x(i) {
        const n = this.dataValue.labels.length;

        return ((i + 0.5) / n) * this.plotTarget.clientWidth;
    }

    y(value) {
        const h = this.plotTarget.clientHeight;
        const pad = 8; // keep markers inside the plot

        return h - pad - (value / this.max) * (h - 2 * pad);
    }

    render() {
        const { series } = this.dataValue;
        const w = this.plotTarget.clientWidth;
        const h = this.plotTarget.clientHeight;
        this.max = Math.max(1, ...series.flatMap((s) => s.values));

        const svgLines = series.map((s) => {
            const pts = s.values.map((v, i) => [this.x(i), this.y(v)]);
            const dots = pts.map(([cx, cy]) =>
                `<circle cx="${cx}" cy="${cy}" r="3.5" fill="${s.color}" stroke="#fff" stroke-width="2"/>`
            ).join('');

            return `<path d="${this.smoothPath(pts)}" fill="none" stroke="${s.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>${dots}`;
        }).join('');

        // three recessive horizontal gridlines
        const grid = [0.25, 0.5, 0.75].map((f) =>
            `<line x1="0" y1="${h * f}" x2="${w}" y2="${h * f}" stroke="#F3F4F6" stroke-width="1"/>`
        ).join('');

        this.plotTarget.innerHTML =
            `<svg width="${w}" height="${h}" aria-hidden="true">${grid}${svgLines}</svg>`;
    }

    /*
     * Catmull-Rom spline converted to cubic beziers: a smooth curve that still
     * passes exactly through every data point (no invented peaks between them).
     */
    smoothPath(pts) {
        if (pts.length < 3) {
            return `M ${pts.map((p) => p.join(' ')).join(' L ')}`;
        }
        const t = 6; // tension divisor; higher = straighter
        let d = `M ${pts[0][0]} ${pts[0][1]}`;
        for (let i = 0; i < pts.length - 1; i++) {
            const p0 = pts[i - 1] ?? pts[i];
            const p1 = pts[i];
            const p2 = pts[i + 1];
            const p3 = pts[i + 2] ?? p2;
            const c1 = [p1[0] + (p2[0] - p0[0]) / t, p1[1] + (p2[1] - p0[1]) / t];
            const c2 = [p2[0] - (p3[0] - p1[0]) / t, p2[1] - (p3[1] - p1[1]) / t];
            d += ` C ${c1[0]} ${c1[1]}, ${c2[0]} ${c2[1]}, ${p2[0]} ${p2[1]}`;
        }

        return d;
    }

    move(event) {
        const rect = this.plotTarget.getBoundingClientRect();
        const n = this.dataValue.labels.length;
        const i = Math.max(0, Math.min(n - 1,
            Math.floor(((event.clientX - rect.left) / rect.width) * n)));
        const px = this.x(i);

        this.crosshairTarget.style.left = `${px}px`;
        this.crosshairTarget.classList.remove('hidden');

        const rows = this.dataValue.series.map((s) =>
            `<div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-sm" style="background:${s.color}"></span>
                <span class="text-gray-500">${s.label}</span>
                <span class="ms-auto font-semibold text-gray-900">${s.values[i]}</span>
            </div>`
        ).join('');
        this.tooltipTarget.innerHTML =
            `<div class="font-semibold text-gray-900 mb-1">${this.dataValue.labels[i]}</div>${rows}`;

        const tw = this.tooltipTarget.offsetWidth;
        const flip = px + 12 + tw > rect.width;
        this.tooltipTarget.style.left = `${flip ? px - 12 - tw : px + 12}px`;
        this.tooltipTarget.style.top = `${Math.max(0, event.clientY - rect.top - 40)}px`;
        this.tooltipTarget.classList.remove('hidden');
    }

    hide() {
        this.tooltipTarget.classList.add('hidden');
        this.crosshairTarget.classList.add('hidden');
    }
}
