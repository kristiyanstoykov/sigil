import { Controller } from '@hotwired/stimulus';

/*
 * Minimal copy-to-clipboard (replaces the old Flowbite data-copy-to-clipboard
 * plugin). Usage:
 *   <div data-controller="clipboard" data-clipboard-text-value="…">
 *     <button data-action="clipboard#copy">Copy</button>
 *     <span data-clipboard-target="feedback" hidden>Copied!</span>
 *   </div>
 */
export default class extends Controller {
    static values = { text: String };
    static targets = ['feedback'];

    async copy() {
        await navigator.clipboard.writeText(this.textValue);
        if (this.hasFeedbackTarget) {
            this.feedbackTarget.hidden = false;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { this.feedbackTarget.hidden = true; }, 1500);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
