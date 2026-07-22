import { Controller } from '@hotwired/stimulus';

/*
 * Signing overlay. When the sign form is submitted, cover the form card with
 * the S-loader and a "Signing…" message — signing is a weighty operation that
 * can take a moment (the RFC-3161 timestamp round-trip), so it gets a page-level
 * loader, not just the button spinner. The form always redirects on completion
 * (PRG), so Turbo navigates away and the overlay goes with the old page; this
 * also resets it on turbo:before-cache so a restored snapshot never shows it.
 *
 *   <div data-controller="signing-form">
 *     <form data-action="submit->signing-form#start"> … </form>
 *     <div data-signing-form-target="overlay" class="hidden …"> … </div>
 */
export default class extends Controller {
    static targets = ['overlay'];

    connect() {
        this._reset = () => this.hide();
        document.addEventListener('turbo:before-cache', this._reset);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this._reset);
    }

    start() {
        this.overlayTarget.classList.remove('hidden');
    }

    hide() {
        this.overlayTarget?.classList.add('hidden');
    }
}
