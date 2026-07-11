import { Controller } from '@hotwired/stimulus';

/*
 * Row action menu. Flowbite/absolute dropdowns get clipped by the table's
 * overflow container, so on open this moves the menu into <body> and positions
 * it with `fixed` under the trigger - escaping every overflow/transform
 * ancestor. Closes on outside click, Esc, scroll, or resize.
 */
export default class extends Controller {
    static targets = ['button', 'menu'];

    connect() {
        // Capture references before the menu is moved out of this element.
        this.menu = this.menuTarget;
        this.button = this.buttonTarget;
        this.menu.dataset.portalMenu = '';
        this._onDoc = (event) => {
            if (!this.menu.contains(event.target) && !this.button.contains(event.target)) this.close();
        };
        this._onKey = (event) => {
            if (event.key === 'Escape') this.close();
        };
        this._reposition = () => this.place();
    }

    disconnect() {
        this.detach();
        this.rehome();
    }

    toggle(event) {
        event.preventDefault();
        event.stopPropagation();
        this.menu.classList.contains('hidden') ? this.open() : this.close();
    }

    open() {
        document.body.appendChild(this.menu);
        this.menu.classList.remove('hidden');
        this.place();
        document.addEventListener('click', this._onDoc);
        document.addEventListener('keydown', this._onKey);
        window.addEventListener('resize', this._reposition);
        window.addEventListener('scroll', this._reposition, true);
    }

    close() {
        this.menu.classList.add('hidden');
        this.rehome();
        this.detach();
    }

    // Return the menu to its trigger's element so it lives in the row when
    // closed (keeps the DOM clean for Turbo snapshots).
    rehome() {
        if (this.menu && this.menu.parentElement === document.body) {
            this.element.appendChild(this.menu);
        }
    }

    detach() {
        document.removeEventListener('click', this._onDoc);
        document.removeEventListener('keydown', this._onKey);
        window.removeEventListener('resize', this._reposition);
        window.removeEventListener('scroll', this._reposition, true);
    }

    place() {
        const rect = this.button.getBoundingClientRect();
        const width = this.menu.offsetWidth || 176;
        const left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
        this.menu.style.position = 'fixed';
        this.menu.style.zIndex = '70'; // above the fixed navbar (z-50)
        this.menu.style.left = `${left}px`;
        this.menu.style.top = `${rect.bottom + 6}px`;
    }
}
