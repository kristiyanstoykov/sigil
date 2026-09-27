/*
 * A revisit renders twice: Turbo's cached preview first, then the fresh page.
 * Both carried the .docs-rise entrance, so the page visibly arrived twice.
 * The preview keeps the rise; the render that replaces it skips it.
 */
const html = document.documentElement;
let previewShown = false;

document.addEventListener('turbo:before-render', (event) => {
    // Turbo marks <html> for the render about to happen before this event fires.
    const isPreview = html.hasAttribute('data-turbo-preview');
    if (!isPreview && previewShown) event.detail.newBody.classList.add('no-rise');
    previewShown = isPreview;
});

// The cached snapshot is a clone of this body; it must rise when previewed.
document.addEventListener('turbo:before-cache', () => document.body.classList.remove('no-rise'));
