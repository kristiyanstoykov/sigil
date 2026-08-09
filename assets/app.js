import './stimulus_bootstrap.js';
import './styles/app.css';
import './behaviors/form_loader.js';
import { initAblePro } from './behaviors/able_pro.js';
import { initFlashToasts } from './behaviors/flash.js';

// Able Pro's stock scripts init on DOMContentLoaded only — Turbo page visits
// never fire it. initAblePro() binds its delegated listeners once and re-runs
// the per-page bits (sidebar active link) on every visit; the flash toasts on
// the new page get their own countdown the same way.
document.addEventListener('turbo:load', () => {
    initAblePro();
    initFlashToasts();
});
