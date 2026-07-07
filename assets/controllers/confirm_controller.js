import { Controller } from '@hotwired/stimulus';

/*
 * Animated confirm dialog (revoke certificate, future destructive actions).
 *
 * Flowbite's modal toggles `hidden` instantly, which makes a close animation
 * impossible — so this dialog is Stimulus-driven instead:
 *
 *   <div data-controller="confirm">
 *     <button data-action="confirm#open">…</button>
 *     <div data-confirm-target="dialog" class="hidden …"
 *          data-action="click->confirm#backdropClose keydown.esc@window->confirm#close">
 *       <div data-confirm-target="card" class="modal-card …">…
 *         <button data-action="confirm#close">Cancel</button>
 */
export default class extends Controller {
    static targets = ['dialog', 'card'];

    open() {
        this.dialogTarget.classList.remove('hidden', 'modal-backdrop-out');
        this.cardTarget.classList.remove('modal-card-out');
        this.cardTarget.querySelector('button, a, input')?.focus();
    }

    close() {
        if (this.dialogTarget.classList.contains('hidden')) {
            return;
        }
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.dialogTarget.classList.add('hidden');

            return;
        }
        this.cardTarget.classList.add('modal-card-out');
        this.dialogTarget.classList.add('modal-backdrop-out');
        this.cardTarget.addEventListener('animationend', () => {
            this.dialogTarget.classList.add('hidden');
            this.cardTarget.classList.remove('modal-card-out');
            this.dialogTarget.classList.remove('modal-backdrop-out');
        }, { once: true });
    }

    backdropClose(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }
}
