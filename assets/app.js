import './stimulus_bootstrap.js';
import './styles/app.css';
import './form_loader.js';
import { initAblePro } from './able_pro.js';

// Able Pro's stock scripts init on DOMContentLoaded only — Turbo page visits
// never fire it. initAblePro() binds its delegated listeners once and re-runs
// the per-page bits (sidebar active link) on every visit.
document.addEventListener('turbo:load', () => initAblePro());
