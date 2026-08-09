/*
 * Global form-submission loader. Turbo intercepts every form POST, so one pair
 * of document-level listeners covers all current and future forms — no per-form
 * wiring. While the request is in flight the submit button shows the S-loader
 * (the Sigil mark drawing itself — same path/animation as the page-level
 * component in templates/components/s_loader.html.twig) and is marked busy
 * (Turbo already disables it to prevent double submits).
 *
 * Opt out per button with data-no-loader.
 */

const SIGIL_PATH = 'M168 38C168 32 162 32 158 38C154 44 156 50 156 50C152 38 132 30 112 36C88 43 70 60 76 76C82 90 104 90 122 84C138 79 152 84 148 100C144 116 122 130 96 134C70 138 52 130 56 114C60 100 80 96 100 102C130 110 162 120 192 118C212 116 226 110 234 102';

document.addEventListener('turbo:submit-start', (event) => {
    const submitter = event.detail.formSubmission.submitter;
    if (!submitter || submitter.hasAttribute('data-no-loader') || submitter.querySelector('.sigil-spinner')) {
        return;
    }

    submitter.setAttribute('aria-busy', 'true');

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 260 180');
    svg.setAttribute('class', 'sigil-spinner');
    svg.setAttribute('aria-hidden', 'true');

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('class', 's-path');
    path.setAttribute('d', SIGIL_PATH);
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'currentColor');
    // Much thicker than the page-level loader (6) — legibility at ~1em.
    path.setAttribute('stroke-width', '16');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');

    svg.appendChild(path);
    submitter.prepend(svg);
});

document.addEventListener('turbo:submit-end', (event) => {
    const submitter = event.detail.formSubmission.submitter;
    if (!submitter) {
        return;
    }

    submitter.removeAttribute('aria-busy');
    submitter.querySelector('.sigil-spinner')?.remove();
});
