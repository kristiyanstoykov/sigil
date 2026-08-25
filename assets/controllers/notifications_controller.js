import { Controller } from '@hotwired/stimulus';

/*
 * The live half of the notification bell (ADR-013).
 *
 * Holds one EventSource on this user's own Mercure topic. The hub's message is
 * a content-free nudge, so nothing here renders a notification: it re-fetches
 * the bell's two regions from the server and swaps them in. That keeps document
 * titles out of the hub entirely, keeps CSRF tokens server-minted, and means
 * this file never has to agree with the Twig about what a row looks like.
 *
 * A missed nudge heals itself. The fetch always returns the current state of
 * the inbox, so any refresh is a full resync, and a reconnect after a dropped
 * connection triggers one.
 *
 *   <li data-controller="notifications"
 *       data-notifications-hub-url-value="…/.well-known/mercure?topic=…"
 *       data-notifications-refresh-url-value="/notifications/bell">
 *     <span data-notifications-target="badge">…</span>
 *     <div data-notifications-target="menu">…</div>
 */
export default class extends Controller {
    static targets = ['badge', 'menu', 'bell'];
    static values = { hubUrl: String, refreshUrl: String };

    connect() {
        if (!this.hasHubUrlValue || !this.hubUrlValue) {
            return;
        }

        this.opened = false;
        this.source = new EventSource(this.hubUrlValue, { withCredentials: true });
        this.source.addEventListener('message', () => {
            this.ring();
            this.refresh();
        });
        this.source.addEventListener('open', () => {
            // Not the first open: we were disconnected and may have missed one.
            if (this.opened) {
                this.refresh();
            }
            this.opened = true;
        });
    }

    disconnect() {
        this.source?.close();
        this.source = null;
        clearTimeout(this.pending);
    }

    // Rung only for a live arrival, never on page load: the point is to catch
    // an eye that was already looking elsewhere. Restarted rather than queued if
    // two land together, so a burst is one swing and not a jangle.
    ring() {
        if (!this.hasBellTarget) {
            return;
        }

        const bell = this.bellTarget;
        bell.classList.remove('bell-ringing');
        void bell.offsetWidth; // Reflow, or removing and re-adding is a no-op.
        bell.classList.add('bell-ringing');
        bell.addEventListener('animationend', () => bell.classList.remove('bell-ringing'), { once: true });
    }

    // Several notifications can land at once (one delivery, four recipients on
    // a shared document, a queue advancing). Coalesce them into one fetch.
    refresh() {
        clearTimeout(this.pending);
        this.pending = setTimeout(() => this.fetchBell(), 250);
    }

    async fetchBell() {
        let html;
        try {
            const response = await fetch(this.refreshUrlValue, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }
            html = await response.text();
        } catch {
            // Offline or the session expired. The next page load is correct.
            return;
        }

        const fragment = new DOMParser().parseFromString(html, 'text/html');
        this.swap(fragment, 'badge', this.hasBadgeTarget ? this.badgeTarget : null);
        this.swap(fragment, 'menu', this.hasMenuTarget ? this.menuTarget : null);
    }

    swap(fragment, region, target) {
        const source = fragment.querySelector(`[data-bell-region="${region}"]`);
        if (source && target) {
            target.innerHTML = source.innerHTML;
        }
    }
}
