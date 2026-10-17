import { Controller } from '@hotwired/stimulus';

/*
 * Keeps the "Preparing your export" banner honest. When the bell's live nudge
 * arrives (sigil:notification) the page is re-read, so the archives appear
 * without a manual refresh. A slow poll covers a hub that is down.
 *
 *   <div data-controller="export-status"
 *        data-action="sigil:notification@window->export-status#reload"> … </div>
 */
export default class extends Controller {
    static values = { pollSeconds: { type: Number, default: 20 } };

    connect() {
        this.timer = setInterval(() => this.reload(), this.pollSecondsValue * 1000);
    }

    disconnect() {
        clearInterval(this.timer);
        clearTimeout(this.pending);
    }

    reload() {
        // Coalesce a burst of nudges into one visit.
        clearTimeout(this.pending);
        this.pending = setTimeout(() => {
            if (window.Turbo) {
                window.Turbo.visit(window.location.href, { action: 'replace' });
            } else {
                window.location.reload();
            }
        }, 300);
    }
}
