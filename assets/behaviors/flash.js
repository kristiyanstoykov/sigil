/*
 * Flash toasts: dismiss themselves after a few seconds.
 *
 * Scoped deliberately to the toast stack in templates/base.html.twig (the
 * [data-flash-stack] container) and NOT to .alert in general - the app renders
 * plenty of inline alerts that must stay put: the dashboard's 2FA nudge, the
 * certificate hints, a login error above the form. Those are page content; only
 * the floating stack is transient.
 *
 * The timer pauses while the pointer is over a toast, so a message never
 * vanishes mid-read, and restarts on leave.
 */

import { dismissAlert } from './able_pro.js';

const VISIBLE_MS = 5000;

function schedule(toast) {
    window.clearTimeout(Number(toast.dataset.flashTimer));
    toast.dataset.flashTimer = String(window.setTimeout(() => dismissAlert(toast), VISIBLE_MS));
}

export function initFlashToasts() {
    document.querySelectorAll('[data-flash-stack] .alert').forEach((toast) => {
        if (toast.dataset.flashTimer) return; // already counting down

        schedule(toast);
        toast.addEventListener('mouseenter', () => window.clearTimeout(Number(toast.dataset.flashTimer)));
        toast.addEventListener('mouseleave', () => schedule(toast));
    });
}

/* A flash is consumed server-side, so it belongs to exactly one response. Drop
   any that are still on screen before Turbo snapshots the page, or restoring
   that snapshot would re-show a message the user already dealt with. */
document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('[data-flash-stack] .alert').forEach((toast) => toast.remove());
});
