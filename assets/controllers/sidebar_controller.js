import { Controller } from '@hotwired/stimulus';

/*
 * Collapses the app sidebar to an icon rail (matches the first design). All
 * styling reacts to the data-collapsed attribute via Tailwind
 * (group-)data-[collapsed] variants - no custom CSS. This controller only flips
 * the attribute and persists the choice. Mobile drawer uses data-mobile-open.
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

    toggleMobile() {
        this.asideTarget.hasAttribute('data-mobile-open') ? this.closeMobile() : this.openMobile();
    }

    openMobile() {
        this.asideTarget.setAttribute('data-mobile-open', '');
        if (!this._overlay) {
            this._overlay = document.createElement('div');
            this._overlay.className = 'fixed inset-0 top-16 z-30 bg-gray-900/40 md:hidden';
            this._overlay.addEventListener('click', () => this.closeMobile());
            document.body.appendChild(this._overlay);
        }
    }

    closeMobile() {
        this.asideTarget.removeAttribute('data-mobile-open');
        this._overlay?.remove();
        this._overlay = null;
    }

    disconnect() {
        this._overlay?.remove();
        this._overlay = null;
    }
}
