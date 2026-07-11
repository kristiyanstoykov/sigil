import { Controller } from '@hotwired/stimulus';

/*
 * Documents upload modal - drives a native <dialog> (top layer, frosted
 * ::backdrop from CSS). No Flowbite modal instance, so open/close never
 * desync. Also handles the dropzone (filename display + drag-and-drop) and
 * auto-opens when the page is reached via ?upload=1 (the sidebar CTA).
 */
export default class extends Controller {
    static targets = ['dialog', 'input', 'prompt', 'selected', 'filename', 'filesize', 'error'];
    static values = { open: Boolean, max: Number };

    connect() {
        if (this.openValue && this.hasDialogTarget) {
            this.open();
            // Clean ?upload=1 from the URL so a refresh/back doesn't reopen it.
            if (window.history.replaceState) {
                window.history.replaceState({}, '', window.location.pathname);
            }
        }
    }

    open() {
        if (this.hasDialogTarget && !this.dialogTarget.open) {
            this.dialogTarget.showModal();
        }
    }

    close() {
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }

    // Click on the dialog element itself (the backdrop area) closes it.
    backdropClose(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    // Esc fires the dialog's native `cancel` event; let it close cleanly.
    onCancel() {
        this.close();
    }

    selected() {
        const file = this.inputTarget.files && this.inputTarget.files[0];
        if (!file) {
            this.reset();
            return;
        }
        this.filenameTarget.textContent = file.name;
        if (this.hasFilesizeTarget) this.filesizeTarget.textContent = this.human(file.size);
        this.promptTarget.classList.add('hidden');
        this.selectedTarget.classList.remove('hidden');
        this.selectedTarget.classList.add('flex');

        // Instant size check so a too-large file is caught before uploading.
        this.tooBig = this.hasMaxValue && this.maxValue > 0 && file.size > this.maxValue;
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = this.tooBig
                ? `That file is ${this.human(file.size)} - the maximum is ${this.human(this.maxValue)}.`
                : '';
            this.errorTarget.classList.toggle('hidden', !this.tooBig);
        }
    }

    // Block submission of a file the client already knows is too large.
    validate(event) {
        if (this.tooBig) event.preventDefault();
    }

    reset() {
        this.inputTarget.value = '';
        this.tooBig = false;
        this.promptTarget.classList.remove('hidden');
        this.selectedTarget.classList.add('hidden');
        this.selectedTarget.classList.remove('flex');
        if (this.hasErrorTarget) this.errorTarget.classList.add('hidden');
    }

    dragover(event) {
        event.preventDefault();
        this.zone(true);
    }

    dragleave() {
        this.zone(false);
    }

    drop(event) {
        event.preventDefault();
        this.zone(false);
        const files = event.dataTransfer && event.dataTransfer.files;
        if (files && files.length) {
            this.inputTarget.files = files;
            this.selected();
        }
    }

    zone(active) {
        const dz = this.element.querySelector('[data-dropzone]');
        if (!dz) return;
        dz.classList.toggle('border-primary-500', active);
        dz.classList.toggle('bg-primary-50', active);
    }

    human(bytes) {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1048576) return `${Math.round(bytes / 1024)} KB`;
        return `${(bytes / 1048576).toFixed(1)} MB`;
    }
}
