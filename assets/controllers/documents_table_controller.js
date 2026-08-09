import { Controller } from '@hotwired/stimulus';
import 'simple-datatables'; // UMD bundle vendored from Able Pro — registers window.simpleDatatables

/*
 * Able Pro's own datatable (simple-datatables) on the documents list: search,
 * column sort, paging and a rows-per-page selector, all client-side. The
 * theme's Tailwind demos use this library — the jQuery DataTables files in its
 * plugins folder are left over from the Bootstrap edition — and
 * assets/able-pro/css/style.css already styles the .datatable-* markup it
 * builds, so nothing here styles anything.
 *
 * This controller is only the two things the library does not do for us:
 * a Turbo-safe lifecycle, and telling it what the columns actually hold.
 */

/** Mirrors the `filesize` Twig macro — the cell holds raw bytes so it sorts. */
function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / 1048576).toFixed(1)} MB`;
}

export default class extends Controller {
    /*
     * The controller mounts on a STABLE ANCESTOR, never on the <table> itself:
     * initialising the library moves the table into a .datatable-wrapper it
     * injects, and Stimulus tears a controller down and reconnects it whenever
     * its element moves in the DOM. On the table that is an infinite loop -
     * connect wraps, the wrap reconnects, the reconnect wraps again.
     */
    static targets = ['table'];
    static values = {
        perPage: { type: Number, default: 10 },
        // Column indices, passed from the template so a reordered <thead>
        // cannot silently break sorting.
        sizeColumn: { type: Number, default: 2 },
        dateColumn: { type: Number, default: 3 },
        actionsColumn: { type: Number, default: 4 },
    };

    connect() {
        if (this.table || !this.hasTableTarget) return;

        this.table = new window.simpleDatatables.DataTable(this.tableTarget, {
            perPage: this.perPageValue,
            perPageSelect: [10, 25, 50],
            columns: [
                {
                    // The cell text is the byte count, so ordering is numeric
                    // rather than lexical ("740 B" before "2.4 MB"); render
                    // swaps in the human-readable string for display only.
                    select: this.sizeColumnValue,
                    type: 'number',
                    render: (data, cell) => {
                        cell.childNodes = [{ nodeName: '#text', data: formatBytes(Number(data)) }];
                    },
                },
                {
                    // Without this the dates sort as text: "06 Aug" < "22 Jul".
                    select: this.dateColumnValue,
                    type: 'date',
                    format: 'DD MMM YYYY',
                },
                { select: this.actionsColumnValue, sortable: false, searchable: false },
            ],
            labels: {
                placeholder: 'Search documents…',
                perPage: 'Rows',
                noRows: 'No documents to show',
                noResults: 'No documents match that search',
                info: 'Showing {start}-{end} of {rows} documents',
            },
            // Same markup as the library's default template, with the
            // rows-per-page dropdown moved out of the top bar and down into the
            // bottom one, so both paging controls sit together under the table
            // and the top bar carries only the search. The library finds the
            // select by querying the whole wrapper, so it works from there.
            template: (opts, dom) => `
                <div class="${opts.classes.top}">
                    ${opts.searchable ? `<div class="${opts.classes.search}">
                        <input class="${opts.classes.input}" placeholder="${opts.labels.placeholder}"
                               type="search" name="search" title="${opts.labels.searchTitle}"
                               ${dom.id ? `aria-controls="${dom.id}"` : ''}>
                    </div>` : ''}
                </div>
                <div class="${opts.classes.container}"></div>
                <div class="${opts.classes.bottom}">
                    ${opts.paging ? `<div class="${opts.classes.info}"></div>` : ''}
                    ${opts.paging && opts.perPageSelect ? `<div class="${opts.classes.dropdown}">
                        <label><select class="${opts.classes.selector}" name="per-page"></select> ${opts.labels.perPage}</label>
                    </div>` : ''}
                    <nav class="${opts.classes.pagination}"></nav>
                </div>`,
        });

        // Turbo caches a snapshot of the page BEFORE tearing the body down, so
        // disconnect() alone is too late: the snapshot would keep the wrapper
        // the library injected, and restoring it would initialise a second
        // datatable around the first. Same reason assets/behaviors/able_pro.js cleans up
        // on this event.
        this.beforeCache = () => this.teardown();
        document.addEventListener('turbo:before-cache', this.beforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.beforeCache);
        this.teardown();
    }

    /** Idempotent: turbo:before-cache and disconnect can both reach it. */
    teardown() {
        this.table?.destroy();
        this.table = null;
    }
}
