import { Controller } from '@hotwired/stimulus';

/*
 * Collapses the app sidebar to an icon rail. All styling reacts to the
 * data-collapsed attribute via Tailwind (group-)data-collapsed variants;
 * this controller only flips the attribute and persists the choice.
 */
export default class extends Controller {
    static targets = ['aside'];

    connect() {
        if (localStorage.getItem('sigil.sidebar') === 'collapsed') {
            this.asideTarget.setAttribute('data-collapsed', '');
        }
    }

    toggle() {
        const collapsed = this.asideTarget.toggleAttribute('data-collapsed');
        localStorage.setItem('sigil.sidebar', collapsed ? 'collapsed' : 'open');
    }
}
