import { Controller } from '@hotwired/stimulus';

/*
 * One-time download with a page loader (ADR-015 §8). The server fetches and
 * decrypts the whole archive before the first byte, which on a slow link can
 * take a while, so the submit is taken over: the overlay says "Preparing", then
 * shows the transfer as it arrives (Content-Length), then hands the file to the
 * browser and refreshes the list. Without JavaScript the form is a plain POST.
 *
 *   <div data-controller="temp-download">
 *     <form data-action="submit->temp-download#take" data-filename="…"> … </form>
 *     <div data-temp-download-target="overlay" class="hidden …"> … </div>
 */
export default class extends Controller {
    static targets = ['overlay', 'title', 'detail', 'bar', 'track', 'close'];

    connect() {
        this._reset = () => this.hide();
        document.addEventListener('turbo:before-cache', this._reset);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this._reset);
    }

    async take(event) {
        event.preventDefault();
        if (this.busy) {
            return;
        }
        this.busy = true;

        const form = event.target;
        this.show('Preparing your archive…', 'Sigil is fetching and decrypting it. Please keep this page open.');

        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const blob = await this.receive(response);
            this.save(blob, form.dataset.filename || 'sigil-export.zip');
            this.refresh();
        } catch (error) {
            this.fail(error.message === '404'
                ? 'This archive is no longer available. It may have been downloaded already or expired.'
                : 'The download did not complete. The archive is still available, so please try again.');
        }
    }

    async receive(response) {
        const total = Number(response.headers.get('Content-Length')) || 0;
        const type = response.headers.get('Content-Type') || 'application/zip';
        if (!response.body) {
            return new Blob([await response.arrayBuffer()], { type });
        }

        this.title('Downloading…');
        this.trackTarget.classList.remove('hidden');
        const reader = response.body.getReader();
        const chunks = [];
        let received = 0;
        for (;;) {
            const { done, value } = await reader.read();
            if (done) {
                break;
            }
            chunks.push(value);
            received += value.length;
            this.progress(received, total);
        }

        return new Blob(chunks, { type });
    }

    save(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }

    progress(received, total) {
        const mb = (bytes) => (bytes / 1048576).toFixed(1);
        if (total > 0) {
            const percent = Math.min(100, Math.round((received / total) * 100));
            this.barTarget.style.width = `${percent}%`;
            this.detail(`${mb(received)} of ${mb(total)} MB (${percent}%)`);
        } else {
            this.detail(`${mb(received)} MB received`);
        }
    }

    show(title, detail) {
        this.title(title);
        this.detail(detail);
        this.barTarget.style.width = '0%';
        this.trackTarget.classList.add('hidden');
        this.closeTarget.classList.add('hidden');
        this.overlayTarget.classList.remove('hidden');
    }

    fail(message) {
        this.title('Download failed');
        this.detail(message);
        this.trackTarget.classList.add('hidden');
        this.closeTarget.classList.remove('hidden');
        this.closeTarget.focus();
    }

    close() {
        this.refresh();
    }

    /** Re-read the page so the list says what was downloaded. */
    refresh() {
        this.busy = false;
        if (window.Turbo) {
            window.Turbo.visit(window.location.href, { action: 'replace' });
        } else {
            window.location.reload();
        }
    }

    hide() {
        this.busy = false;
        this.overlayTarget?.classList.add('hidden');
    }

    title(text) {
        this.titleTarget.textContent = text;
    }

    detail(text) {
        this.detailTarget.textContent = text;
    }
}
