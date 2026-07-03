/*
 * Global form-submission loader. Turbo intercepts every form POST, so one pair
 * of document-level listeners covers all current and future forms — no per-form
 * wiring. While the request is in flight the submit button shows a spinner and
 * is marked busy (Turbo already disables it to prevent double submits).
 *
 * Opt out per button with data-no-loader.
 */

document.addEventListener('turbo:submit-start', (event) => {
    const submitter = event.detail.formSubmission.submitter;
    if (!submitter || submitter.hasAttribute('data-no-loader') || submitter.querySelector('.sigil-spinner')) {
        return;
    }

    submitter.setAttribute('aria-busy', 'true');

    const spinner = document.createElement('span');
    spinner.className = 'sigil-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    submitter.prepend(spinner);
});

document.addEventListener('turbo:submit-end', (event) => {
    const submitter = event.detail.formSubmission.submitter;
    if (!submitter) {
        return;
    }

    submitter.removeAttribute('aria-busy');
    submitter.querySelector('.sigil-spinner')?.remove();
});
