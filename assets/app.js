import './stimulus_bootstrap.js';
import './styles/app.css';
import './form_loader.js';

// Able Pro's own shell JS (component.js/theme.js/script.js) is deliberately NOT
// loaded: it binds everything on DOMContentLoaded, which Turbo fires only once,
// so it dies after the first navigation. All shell interactivity (header
// dropdowns, sidebar collapse, mobile drawer) is driven by Turbo-native
// Stimulus controllers that toggle the exact classes Able Pro's CSS keys off.
// Popper is loaded as a classic global script in base.html.twig for the
// dropdown controller's placement.

// Don't let Turbo snapshot an open menu/modal: close our dropdowns and any
// native <dialog> before caching, so a restored page never comes back mid-modal.
document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('.drp-show').forEach((el) => el.classList.remove('drp-show'));
    document.querySelectorAll('.offcanvas.show').forEach((el) => el.classList.remove('show'));
    document.querySelectorAll('#offcanvasoverlay, #pctooltip').forEach((el) => el.remove());
    document.querySelectorAll('dialog[open]').forEach((el) => el.close());
    document.querySelectorAll('body > [data-portal-menu]').forEach((el) => el.remove());
});
