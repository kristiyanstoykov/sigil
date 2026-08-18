import { Controller } from '@hotwired/stimulus';
import 'dragula'; // UMD bundle vendored from Able Pro — registers window.dragula

/*
 * The signer list on the "request a signature" page: add people by email, put
 * them in order, send. Top is first — the hidden field carries the emails in
 * exactly the order the rows are in, so the DOM is the source of truth.
 *
 * Reordering is available two ways on purpose: dragging is faster, and the
 * up/down buttons are what makes the order reachable by keyboard and screen
 * reader. A drag-only control would not be.
 */
export default class extends Controller {
    static targets = ['input', 'list', 'hidden', 'empty', 'submit', 'error'];
    static values = {
        lookupUrl: String,
        // Seeded rows (the owner, when they chose to sign too). Ordinary rows
        // from here on - movable, removable, re-checked server-side on send.
        preset: Array,
    };

    connect() {
        this.signers = this.hasPresetValue ? [...this.presetValue] : [];
        this.drake = window.dragula([this.listTarget], {
            moves: (el, container, handle) => handle.closest('[data-drag-handle]') !== null,
        });
        this.drake.on('drop', () => this.readOrderFromDom());
        this.render();
    }

    disconnect() {
        this.drake?.destroy();
        this.drake = null;
    }

    /** Enter in the email field adds instead of submitting the form. */
    keydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.add();
        }
    }

    async add() {
        const email = this.inputTarget.value.trim().toLowerCase();
        if (!email) return;

        if (this.signers.some((s) => s.email.toLowerCase() === email)) {
            this.showError('That person is already on the list.');
            return;
        }

        this.inputTarget.disabled = true;
        try {
            const response = await fetch(this.lookupUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ email }),
            });
            const data = await response.json();

            if (!data.email) {
                this.showError(data.reason || 'That address cannot be added.');
                return;
            }

            this.signers.push({ email: data.email, name: data.name, ok: data.ok, reason: data.reason });
            this.inputTarget.value = '';
            this.showError('');
            this.render();
        } catch {
            this.showError('Could not check that address. Please try again.');
        } finally {
            this.inputTarget.disabled = false;
            this.inputTarget.focus();
        }
    }

    remove(event) {
        this.signers.splice(this.indexOfRow(event.currentTarget), 1);
        this.render();
    }

    up(event) {
        this.swap(this.indexOfRow(event.currentTarget), -1);
    }

    down(event) {
        this.swap(this.indexOfRow(event.currentTarget), +1);
    }

    swap(index, delta) {
        const target = index + delta;
        if (index < 0 || target < 0 || target >= this.signers.length) return;

        [this.signers[index], this.signers[target]] = [this.signers[target], this.signers[index]];
        this.render();

        // Keep focus on the row that moved. At either end the button just used
        // is now disabled and cannot take focus, so fall back to its opposite.
        const row = this.listTarget.children[target];
        const moved = row?.querySelector(delta < 0 ? '[data-up]' : '[data-down]');
        (moved?.disabled ? row?.querySelector(delta < 0 ? '[data-down]' : '[data-up]') : moved)?.focus();
    }

    indexOfRow(element) {
        return Number(element.closest('[data-signer-row]').dataset.index);
    }

    /** Dragula moves the DOM nodes; the model follows them, not the other way round. */
    readOrderFromDom() {
        const order = [...this.listTarget.children].map((row) => row.dataset.email);
        this.signers.sort((a, b) => order.indexOf(a.email) - order.indexOf(b.email));
        this.render();
    }

    render() {
        this.listTarget.innerHTML = this.signers.map((signer, index) => this.row(signer, index)).join('');
        this.hiddenTarget.value = this.signers.map((s) => s.email).join('\n');
        this.emptyTarget.hidden = this.signers.length > 0;

        // Send is blocked unless every signer on the list can actually sign.
        const blocked = this.signers.length === 0 || this.signers.some((s) => !s.ok);
        this.submitTarget.disabled = blocked;
        this.submitTarget.setAttribute('aria-disabled', String(blocked));
        // Able Pro's own dimmed-button class, so a blocked send also LOOKS blocked.
        this.submitTarget.classList.toggle('disabled', blocked);
    }

    row(signer, index) {
        const last = index === this.signers.length - 1;

        return `
            <li data-signer-row data-index="${index}" data-email="${escapeHtml(signer.email)}"
                class="flex items-center gap-3 p-3 ${signer.ok ? '' : 'bg-danger-500/[0.04]'}">
                <button type="button" data-drag-handle aria-label="Drag to reorder"
                        class="shrink-0 cursor-grab text-theme-secondarytextcolor hover:text-theme-headings">
                    <i class="ti ti-grip-vertical text-base leading-none" aria-hidden="true"></i>
                </button>
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-500/10 text-xs font-bold text-primary-500">
                    ${index + 1}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-theme-headings">${escapeHtml(signer.name)}</span>
                    <span class="block truncate text-xs ${signer.ok ? 'text-theme-secondarytextcolor' : 'text-danger-600'}">
                        ${escapeHtml(signer.ok ? signer.email : signer.reason)}
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-1">
                    <button type="button" data-up data-action="signer-order#up" ${index === 0 ? 'disabled' : ''}
                            aria-label="Move ${escapeHtml(signer.name)} earlier"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-theme-border text-theme-secondarytextcolor hover:bg-theme-activebg disabled:opacity-40">
                        <i class="ti ti-chevron-up text-sm leading-none" aria-hidden="true"></i>
                    </button>
                    <button type="button" data-down data-action="signer-order#down" ${last ? 'disabled' : ''}
                            aria-label="Move ${escapeHtml(signer.name)} later"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-theme-border text-theme-secondarytextcolor hover:bg-theme-activebg disabled:opacity-40">
                        <i class="ti ti-chevron-down text-sm leading-none" aria-hidden="true"></i>
                    </button>
                    <button type="button" data-action="signer-order#remove"
                            aria-label="Remove ${escapeHtml(signer.name)}"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-theme-border text-danger-600 hover:bg-danger-500/10">
                        <i class="ti ti-x text-sm leading-none" aria-hidden="true"></i>
                    </button>
                </span>
            </li>`;
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = !message;
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[char]);
}
