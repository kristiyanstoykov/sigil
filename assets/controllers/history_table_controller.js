import { Controller } from '@hotwired/stimulus';
import 'simple-datatables'; // UMD bundle vendored from Able Pro - registers window.simpleDatatables

/*
 * Able Pro's datatable (simple-datatables) on the signing History tab: search,
 * sort, paging and rows-per-page, all the library's own, in its own top and
 * bottom bars. The theme CSS styles the .datatable-* markup it builds and
 * components/documents-table.css restyles the rows as cards below md, so this
 * controller is only the Turbo-safe lifecycle plus column types - the same
 * rules as documents_table_controller.js, without the filters.
 */
export default class extends Controller {
    /*
     * Mounted on a stable wrapper, never on the <table>: initialising moves the
     * table into a wrapper the library injects, and Stimulus reconnects a
     * controller whose element moves - on the table that loops forever.
     */
    static targets = ['table'];
    static values = {
        perPage: { type: Number, default: 10 },
        // Column indices from the template, so a reordered <thead> cannot
        // silently break sorting.
        dateColumn: { type: Number, default: 3 },
        actionsColumn: { type: Number, default: 4 },
    };

    connect() {
        if (this.table || !this.hasTableTarget) return;

        this.table = new window.simpleDatatables.DataTable(this.tableTarget, {
            perPage: this.perPageValue,
            perPageSelect: [10, 25, 50],
            columns: [
                // Newest first, and sorted as dates rather than as text.
                { select: this.dateColumnValue, type: 'date', format: 'DD MMM YYYY HH:mm', sort: 'desc' },
                { select: this.actionsColumnValue, sortable: false, searchable: false },
            ],
            labels: {
                placeholder: 'Search history…',
                perPage: 'Rows',
                noRows: 'Nothing settled yet',
                noResults: 'No requests match that search',
                info: 'Showing {start}-{end} of {rows} requests',
            },
        });

        // Turbo snapshots the page before tearing it down, so disconnect() is
        // too late: the snapshot would keep the injected wrapper and restoring
        // it would build a second datatable around the first.
        this.beforeCache = () => this.teardown();
        document.addEventListener('turbo:before-cache', this.beforeCache);
    }

    disconnect() {
        if (this.beforeCache) {
            document.removeEventListener('turbo:before-cache', this.beforeCache);
            this.beforeCache = null;
        }
        this.teardown();
    }

    /** Idempotent: turbo:before-cache and disconnect can both reach it. */
    teardown() {
        this.table?.destroy();
        this.table = null;
    }
}
