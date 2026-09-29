import { Controller } from '@hotwired/stimulus';

/*
 * Submits the 2FA login form as soon as six digits are in, typed, pasted or
 * filled by the phone. The Verify button stays the no-JS path.
 *
 *   <input data-controller="otp-autosubmit"
 *          data-action="input->otp-autosubmit#check paste->otp-autosubmit#paste">
 */
export default class extends Controller {
    connect() {
        this.submitted = null;
    }

    // maxlength would cut a pasted "123 456" to "123 45", so strip it first.
    paste(event) {
        const digits = (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '');
        if (digits === '') {
            return;
        }
        event.preventDefault();
        this.element.value = digits.slice(0, 6);
        this.check();
    }

    check() {
        const digits = this.element.value.replace(/\D/g, '').slice(0, 6);
        if (digits !== this.element.value) {
            this.element.value = digits;
        }

        // One submit per code: a repeat event or a stray keystroke never sends it twice.
        if (digits.length !== 6 || digits === this.submitted) {
            return;
        }
        this.submitted = digits;

        // Through the Verify button, so the global form loader spins on it.
        const form = this.element.form;
        form?.requestSubmit(form.querySelector('button[type="submit"]'));
    }
}
