import { Controller } from '@hotwired/stimulus';

/*
 * The recipient list on the "deliver" page. Same shape as signer_order_controller,
 * minus the ordering: everyone is served at once, so there is no first and no
 * last, and nothing to drag. The hidden field carries the addresses, one per line.
 */
export default class extends Controller {
    static targets = ['input', 'list', 'hidden', 'empty', 'submit', 'error'];
    static values = {
        lookupUrl: String,
    };

    connect() {
        this.recipients = [];
        this.render();
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

        if (this.recipients.some((r) => r.email.toLowerCase() === email)) {
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

            this.recipients.push({ email: data.email, name: data.name, ok: data.ok, reason: data.reason });
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
        this.recipients.splice(Number(event.currentTarget.closest('[data-recipient-row]').dataset.index), 1);
        this.render();
    }

    render() {
        this.listTarget.innerHTML = this.recipients.map((r, index) => this.row(r, index)).join('');
        this.hiddenTarget.value = this.recipients.map((r) => r.email).join('\n');
        this.emptyTarget.hidden = this.recipients.length > 0;

        // All or nothing: one address that cannot be served blocks the whole
        // delivery, because a half-made one would need a receipt attesting half.
        const blocked = this.recipients.length === 0 || this.recipients.some((r) => !r.ok);
        this.submitTarget.disabled = blocked;
        this.submitTarget.setAttribute('aria-disabled', String(blocked));
        this.submitTarget.classList.toggle('disabled', blocked);
    }

    row(recipient, index) {
        return `
            <li data-recipient-row data-index="${index}"
                class="flex items-center gap-3 p-3 ${recipient.ok ? '' : 'bg-danger-500/[0.04]'}">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-500/10 text-primary-500">
                    <i class="ti ti-user text-sm leading-none" aria-hidden="true"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-theme-headings">${escapeHtml(recipient.name)}</span>
                    <span class="block truncate text-xs ${recipient.ok ? 'text-theme-secondarytextcolor' : 'text-danger-600'}">
                        ${escapeHtml(recipient.ok ? recipient.email : recipient.reason)}
                    </span>
                </span>
                <button type="button" data-action="recipient-picker#remove"
                        aria-label="Remove ${escapeHtml(recipient.name)}"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-theme-border text-danger-600 hover:bg-danger-500/10">
                    <i class="ti ti-x text-sm leading-none" aria-hidden="true"></i>
                </button>
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
