import { Controller } from '@hotwired/stimulus';
import 'simple-datatables'; // UMD bundle vendored from Able Pro - registers window.simpleDatatables

/*
 * Able Pro's own datatable (simple-datatables) on the documents list: search,
 * column sort, paging and a rows-per-page selector, all client-side. The
 * theme's Tailwind demos use this library - the jQuery DataTables files in its
 * plugins folder are left over from the Bootstrap edition - and
 * assets/able-pro/css/style.css already styles the .datatable-* markup it
 * builds, so nothing here styles anything.
 *
 * This controller is the three things the library does not do for us: a
 * Turbo-safe lifecycle, telling it what the columns actually hold, and driving
 * the Role/Status filters through the same search it already runs, so a filter
 * costs no round trip.
 */

/** Mirrors the `filesize` Twig macro - the cell holds raw bytes so it sorts. */
function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / 1048576).toFixed(1)} MB`;
}

const ACTIVE_ITEM = ['!text-primary-500', 'font-semibold'];

/*
 * The Role and Status filters match the cell's data-filter attribute, not its
 * text. The library searches a cell's innerText, which is the badge's wording -
 * it would break on a reword, and "From others" is two badges (Signer and
 * Recipient) rather than a word of its own.
 */
const byFilter = (terms, cell) => terms.includes(cell.attributes?.['data-filter'] ?? '');

export default class extends Controller {
    /*
     * The controller mounts on a STABLE ANCESTOR, never on the <table> itself:
     * initialising the library moves the table into a .datatable-wrapper it
     * injects, and Stimulus tears a controller down and reconnects it whenever
     * its element moves in the DOM. On the table that is an infinite loop -
     * connect wraps, the wrap reconnects, the reconnect wraps again.
     */
    static targets = ['table', 'search', 'roleLabel', 'statusLabel', 'roleItem', 'statusItem', 'clear', 'empty', 'card'];
    static values = {
        perPage: { type: Number, default: 10 },
        // Column indices, passed from the template so a reordered <thead>
        // cannot silently break sorting or filtering.
        titleColumn: { type: Number, default: 0 },
        roleColumn: { type: Number, default: 1 },
        statusColumn: { type: Number, default: 2 },
        sizeColumn: { type: Number, default: 3 },
        dateColumn: { type: Number, default: 4 },
        actionsColumn: { type: Number, default: 5 },
        // Seeded from ?role= / ?status= so a linked or reloaded page opens
        // filtered; from here on they are ours.
        role: String,
        status: String,
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
                { select: this.roleColumnValue, searchMethod: byFilter },
                { select: this.statusColumnValue, searchMethod: byFilter },
                { select: this.actionsColumnValue, sortable: false, searchable: false },
            ],
            labels: {
                placeholder: 'Search documents…',
                perPage: 'Rows',
                noRows: 'No documents to show',
                noResults: 'No documents match that search',
                info: 'Showing {start}-{end} of {rows} documents',
            },
            // The library's top bar is dropped entirely: the toolbar above the
            // table is ours, so the search box sits on the left of the same row
            // as the filter dropdowns instead of in a bar of its own. Paging
            // controls stay together under the table. The library finds the
            // per-page select by querying the whole wrapper, so it works there.
            template: (opts) => `
                <div class="${opts.classes.container}"></div>
                <div class="${opts.classes.bottom}">
                    ${opts.paging ? `<div class="${opts.classes.info}"></div>` : ''}
                    ${opts.paging && opts.perPageSelect ? `<div class="${opts.classes.dropdown}">
                        <label><select class="${opts.classes.selector}" name="per-page"></select> ${opts.labels.perPage}</label>
                    </div>` : ''}
                    <nav class="${opts.classes.pagination}"></nav>
                </div>`,
        });

        // A match of nothing gets Sigil's empty card rather than the library's
        // one-line message - the two empty states (no documents at all versus
        // none in this view) have to stay distinguishable.
        this.onMultiSearch = (queries, matches) => this.showResultCount(queries.length > 0, matches.length);
        this.table.on('datatable.multisearch', this.onMultiSearch);

        // Our own search box drives the library's search, so it can live in the
        // toolbar rather than in the bar the library would have drawn.
        if (this.hasSearchTarget) {
            this.onSearch = () => this.apply();
            this.searchTarget.addEventListener('input', this.onSearch);
        }

        this.apply();

        // Turbo caches a snapshot of the page BEFORE tearing the body down, so
        // disconnect() alone is too late: the snapshot would keep the wrapper
        // the library injected, and restoring it would initialise a second
        // datatable around the first. Same reason assets/behaviors/able_pro.js cleans up
        // on this event.
        this.beforeCache = () => this.teardown();
        document.addEventListener('turbo:before-cache', this.beforeCache);
    }

    /* ---------------------------- filters ---------------------------- */

    pickRole(event) {
        this.pick(event, 'roleValue');
    }

    pickStatus(event) {
        this.pick(event, 'statusValue');
    }

    clear(event) {
        event.preventDefault();
        this.roleValue = '';
        this.statusValue = '';
        if (this.hasSearchTarget) this.searchTarget.value = '';
        this.apply();
    }

    /*
     * The items are real links to the filtered URL, so a modified click (new
     * tab, middle-click) and a JavaScript-less visit both still work. Only the
     * plain click is ours to handle.
     */
    pick(event, prop) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button > 0) return;
        event.preventDefault();
        this[prop] = event.currentTarget.dataset.value || '';
        event.currentTarget.closest('.dropdown')?.classList.remove('drp-show');
        this.apply();
    }

    /*
     * One multiSearch per change, carrying every active constraint: the library
     * ANDs the queries and ORs the terms inside one. Two calls would not work -
     * search('') throws away every query, filters included.
     *
     * Free text is scoped to the Document column - the title plus the owner's
     * name - so it never collides with the filters' own columns.
     */
    apply() {
        const queries = [];
        const term = this.hasSearchTarget ? this.searchTarget.value.trim() : '';

        if (term) queries.push({ terms: [term], columns: [this.titleColumnValue] });
        if (this.roleValue) queries.push({ terms: [this.roleValue], columns: [this.roleColumnValue] });
        if (this.statusValue) queries.push({ terms: [this.statusValue], columns: [this.statusColumnValue] });

        this.table.multiSearch(queries);
        this.syncToolbar();
        this.syncUrl();
    }

    syncToolbar() {
        this.markActive(this.roleItemTargets, this.roleValue, this.hasRoleLabelTarget ? this.roleLabelTarget : null, 'All');
        this.markActive(this.statusItemTargets, this.statusValue, this.hasStatusLabelTarget ? this.statusLabelTarget : null, 'Any');

        if (this.hasClearTarget) this.clearTarget.hidden = !this.roleValue && !this.statusValue;
    }

    markActive(items, value, label, fallback) {
        items.forEach((item) => {
            const chosen = (item.dataset.value || '') === value;
            item.classList.toggle(ACTIVE_ITEM[0], chosen);
            item.classList.toggle(ACTIVE_ITEM[1], chosen);
            // The item's own text is the label, minus the count beside "All".
            if (chosen && label) label.textContent = value ? item.textContent.trim() : fallback;
        });
    }

    showResultCount(filtering, matches) {
        const nothing = filtering && matches === 0;
        if (this.hasEmptyTarget) this.emptyTarget.hidden = !nothing;
        if (this.hasCardTarget) this.cardTarget.hidden = nothing;
    }

    /* Keeps the view linkable and reload-proof without a navigation. */
    syncUrl() {
        const url = new URL(window.location.href);
        for (const [key, value] of [['role', this.roleValue], ['status', this.statusValue]]) {
            if (value) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        }
        window.history.replaceState(window.history.state, '', url);
    }

    /* --------------------------- lifecycle --------------------------- */

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.beforeCache);
        this.teardown();
    }

    /** Idempotent: turbo:before-cache and disconnect can both reach it. */
    teardown() {
        if (this.hasSearchTarget && this.onSearch) {
            this.searchTarget.removeEventListener('input', this.onSearch);
            this.onSearch = null;
        }
        if (this.onMultiSearch) {
            this.table?.off('datatable.multisearch', this.onMultiSearch);
            this.onMultiSearch = null;
        }
        this.table?.destroy();
        this.table = null;
    }
}
