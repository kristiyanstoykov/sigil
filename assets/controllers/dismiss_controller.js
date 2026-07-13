import { Controller } from '@hotwired/stimulus';

/*
 * Dismissible flash toast. Optional auto-hide after `auto` ms.
 *   <div data-controller="dismiss" data-dismiss-auto-value="6000">
 *     <button data-action="dismiss#close">x</button>
 */
export default class extends Controller {
    static values = { auto: Number };

    connect() {
        if (this.autoValue) {
            this._timer = setTimeout(() => this.close(), this.autoValue);
        }
    }

    close() {
        clearTimeout(this._timer);
        this.element.remove();
    }

    disconnect() {
        clearTimeout(this._timer);
    }
}
