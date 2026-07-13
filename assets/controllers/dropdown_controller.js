import { Controller } from '@hotwired/stimulus';

/*
 * Simple header dropdown (user menu). Toggles the `hidden` class on the menu
 * target; closes on outside click / Escape. Turbo-safe (connects per render).
 *
 *   <div class="relative" data-controller="dropdown">
 *     <button data-action="dropdown#toggle">...</button>
 *     <div data-dropdown-target="menu" class="hidden ...">...</div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this._onDocClick = (event) => {
            if (!this.element.contains(event.target)) this.close();
        };
        this._onKey = (event) => {
            if (event.key === 'Escape') this.close();
        };
    }

    toggle(event) {
        event.preventDefault();
        this.menuTarget.classList.contains('hidden') ? this.open() : this.close();
    }

    open() {
        this.menuTarget.classList.remove('hidden');
        document.addEventListener('click', this._onDocClick);
        document.addEventListener('keydown', this._onKey);
    }

    close() {
        this.menuTarget.classList.add('hidden');
        document.removeEventListener('click', this._onDocClick);
        document.removeEventListener('keydown', this._onKey);
    }

    disconnect() {
        this.close();
    }
}
