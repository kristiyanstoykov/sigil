import { Controller } from '@hotwired/stimulus';

/*
 * Client-side column sort for the documents table. Each row carries
 * data-sort-name / data-sort-size / data-sort-date; clicking a header reorders
 * <tbody> and toggles asc/desc. Instant, no reload - the list is a single
 * user's own documents, so it stays small.
 */
export default class extends Controller {
    static targets = ['body', 'arrow'];
    static values = {
        key: { type: String, default: 'date' },
        dir: { type: String, default: 'desc' },
    };

    connect() {
        this.updateArrows();
    }

    sort(event) {
        const key = event.params.key;
        if (this.keyValue === key) {
            this.dirValue = this.dirValue === 'asc' ? 'desc' : 'asc';
        } else {
            this.keyValue = key;
            this.dirValue = key === 'name' ? 'asc' : 'desc';
        }
        this.apply();
    }

    apply() {
        const key = this.keyValue;
        const factor = this.dirValue === 'asc' ? 1 : -1;
        const rows = Array.from(this.bodyTarget.querySelectorAll('[data-sortable-row]'));
        rows.sort((a, b) => factor * this.compare(a, b, key));
        rows.forEach((row) => this.bodyTarget.appendChild(row));
        this.updateArrows();
    }

    compare(a, b, key) {
        const av = a.dataset[this.datasetKey(key)] || '';
        const bv = b.dataset[this.datasetKey(key)] || '';
        if (key === 'name') return av.localeCompare(bv);
        return Number(av) - Number(bv);
    }

    updateArrows() {
        this.arrowTargets.forEach((el) => {
            const active = el.dataset.arrowKey === this.keyValue;
            el.textContent = active ? (this.dirValue === 'asc' ? '↑' : '↓') : '↕';
            el.classList.toggle('text-gray-800', active);
            el.classList.toggle('text-gray-300', !active);
        });
    }

    datasetKey(key) {
        return 'sort' + key.charAt(0).toUpperCase() + key.slice(1);
    }
}
