import './stimulus_bootstrap.js';
import './styles/app.css';
import './form_loader.js';
import { initFlowbite } from 'flowbite';

// Flowbite self-initializes on DOMContentLoaded only — Turbo page visits
// never fire it, so data-* components (modals, dropdowns) on navigated-to
// pages would stay inert without this.
document.addEventListener('turbo:load', () => initFlowbite());

// Don't let Turbo snapshot an open modal: strip Flowbite backdrops and close any
// native <dialog> before caching, so a restored page never comes back mid-modal.
document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('[modal-backdrop]').forEach((el) => el.remove());
    document.querySelectorAll('dialog[open]').forEach((el) => el.close());
    document.querySelectorAll('body > [data-portal-menu]').forEach((el) => el.remove());
});
