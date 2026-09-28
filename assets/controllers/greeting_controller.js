import { Controller } from '@hotwired/stimulus';

/*
 * The dashboard greeting and today's date, in the reader's own time zone.
 * The server renders them in its clock as the no-JavaScript fallback; every
 * other time on the page stays server time, because those are the records.
 */
const PARTS = { weekday: 'long', day: 'numeric', month: 'long' };

export default class extends Controller {
    static targets = ['salutation', 'date'];

    connect() {
        const now = new Date();
        const hour = now.getHours();

        if (this.hasSalutationTarget) {
            this.salutationTarget.textContent = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
        }

        if (this.hasDateTarget) {
            // Same shape as the server's 'l, j F': "Sunday, 27 September".
            const p = Object.fromEntries(
                new Intl.DateTimeFormat('en-GB', PARTS).formatToParts(now).map(({ type, value }) => [type, value]),
            );
            this.dateTarget.textContent = `${p.weekday}, ${p.day} ${p.month}`;
        }
    }
}
