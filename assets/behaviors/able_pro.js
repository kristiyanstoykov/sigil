/*
 * Able Pro component behaviors (dropdown, modal, collapse, alert, tabs,
 * sidebar), adapted from the theme's component.js/script.js for Turbo:
 * the originals bind per-element on DOMContentLoaded, which fires once —
 * every Turbo visit would leave the new page inert. This module binds
 * DELEGATED listeners on `document` exactly once, so behaviors survive any
 * number of body swaps, and exposes initAblePro() for the tiny amount of
 * per-page work (sidebar active-link highlight).
 *
 * Markup contract is unchanged from Able Pro:
 *   [data-pc-toggle="dropdown|modal|collapse|tab"], [data-pc-dismiss="alert"],
 *   [data-pc-modal-dismiss], [data-pc-target]/href, #mobile-collapse,
 *   #sidebar-hide, .dropdown > .dropdown-menu, .modal > .modal-content
 */

const OVERLAY_CLASSES = 'fixed inset-0 bg-gray-900/20 z-[1028] backdrop-blur-sm';

/* ---- small helpers (verbatim behavior from Able Pro's script.js) ---- */

function slideUp(el, duration = 0) {
    el.style.transitionProperty = 'height, margin, padding';
    el.style.transitionDuration = duration + 'ms';
    el.style.boxSizing = 'border-box';
    el.style.height = el.offsetHeight + 'px';
    el.style.overflow = 'hidden';
    el.style.height = 0;
    el.style.paddingTop = 0;
    el.style.paddingBottom = 0;
    el.style.marginTop = 0;
    el.style.marginBottom = 0;
    window.setTimeout(() => {
        el.removeAttribute('style');
        el.style.display = 'none';
    }, duration);
}

function slideDown(el, duration = 0) {
    el.style.removeProperty('display');
    let display = window.getComputedStyle(el).display;
    if (display === 'none') display = 'block';
    el.style.display = display;
    const height = el.offsetHeight;
    el.style.overflow = 'hidden';
    el.style.height = 0;
    el.style.paddingTop = 0;
    el.style.paddingBottom = 0;
    el.style.marginTop = 0;
    el.style.marginBottom = 0;
    el.style.boxSizing = 'border-box';
    el.style.transitionProperty = 'height, margin, padding';
    el.style.transitionDuration = duration + 'ms';
    el.style.height = height + 'px';
    el.style.removeProperty('padding-top');
    el.style.removeProperty('padding-bottom');
    el.style.removeProperty('margin-top');
    el.style.removeProperty('margin-bottom');
    window.setTimeout(() => {
        el.removeAttribute('style');
    }, duration);
}

/* Collapse an .alert away and remove it. Exported so the flash toasts fade out
   on their timer exactly the way the close button dismisses them. */
export function dismissAlert(alert) {
    if (!alert || alert.dataset.dismissing) return;
    alert.dataset.dismissing = 'true';
    slideUp(alert, 200);
    window.setTimeout(() => alert.remove(), 200);
}

function targetOf(trigger) {
    const selector = trigger.getAttribute('data-pc-target') || trigger.getAttribute('href');
    return selector && selector !== '#' ? document.querySelector(selector) : null;
}

/* ---------------------------- dropdowns ---------------------------- */

function closeDropdowns() {
    document.querySelectorAll('.dropdown.drp-show, .btn-group.drp-show').forEach((el) => {
        el.classList.remove('drp-show');
    });
}

/* ------------------------------ modals ----------------------------- */

function openModal(modal, animation) {
    if (animation) modal.classList.add('anim-' + animation);
    modal.classList.add('show');
    window.setTimeout(() => modal.classList.add('animate'), 100);
    if (!document.getElementById('modaloverlay')) {
        const overlay = document.createElement('div');
        overlay.className = OVERLAY_CLASSES;
        overlay.id = 'modaloverlay';
        document.body.appendChild(overlay);
        document.body.classList.add('modal-open');
    }
}

function closeModal(modal) {
    modal = modal || document.querySelector('.modal.show');
    if (!modal) return;
    modal.classList.remove('animate');
    window.setTimeout(() => {
        modal.classList.remove('show');
        [...modal.classList].forEach((c) => c.startsWith('anim-') && modal.classList.remove(c));
        document.body.classList.remove('modal-open');
        document.getElementById('modaloverlay')?.remove();
    }, 300);
}

/* ----------------------------- sidebar ----------------------------- */

function closeMobileSidebar() {
    document.querySelector('.pc-sidebar')?.classList.remove('mob-sidebar-active');
    document.querySelectorAll('.pc-menu-overlay').forEach((el) => el.remove());
}

/* --------------------- one-time delegated wiring -------------------- */

let bound = false;

function bindOnce() {
    if (bound) return;
    bound = true;

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest(
            '[data-pc-toggle], [data-pc-dismiss], [data-pc-modal-dismiss], #mobile-collapse, #sidebar-hide'
        );

        /* click outside an open dropdown closes it */
        if (!trigger) {
            if (!event.target.closest('.dropdown-menu')) closeDropdowns();
            /* click on the modal backdrop area (inside .modal, outside .modal-content) */
            const openedModal = event.target.closest('.modal.show');
            if (openedModal && !event.target.closest('.modal-content')) closeModal(openedModal);
            return;
        }

        if (trigger.id === 'mobile-collapse') {
            /* The trigger may be an <a href="#">: without this, Turbo follows
               the link and re-renders the body, instantly closing the drawer. */
            event.preventDefault();
            const sidebar = document.querySelector('.pc-sidebar');
            if (!sidebar) return;
            if (sidebar.classList.contains('mob-sidebar-active')) {
                closeMobileSidebar();
            } else {
                sidebar.classList.add('mob-sidebar-active');
                sidebar.insertAdjacentHTML('beforeend', '<div class="pc-menu-overlay"></div>');
                sidebar.querySelector('.pc-menu-overlay').addEventListener('click', closeMobileSidebar);
            }
            return;
        }

        if (trigger.id === 'sidebar-hide') {
            event.preventDefault();
            const collapsed = document.querySelector('.pc-sidebar')?.classList.toggle('pc-sidebar-hide');
            /* Persist across Turbo visits — each navigation renders a fresh body. */
            localStorage.setItem('sigil.sidebar', collapsed ? 'collapsed' : 'open');
            return;
        }

        if (trigger.getAttribute('data-pc-dismiss') === 'alert') {
            dismissAlert(trigger.closest('.alert'));
            return;
        }

        if (trigger.hasAttribute('data-pc-modal-dismiss')) {
            event.preventDefault();
            closeModal(document.querySelector(trigger.getAttribute('data-pc-modal-dismiss')));
            return;
        }

        const kind = trigger.getAttribute('data-pc-toggle');

        if (kind === 'dropdown') {
            event.preventDefault();
            const dropdown = trigger.closest('.dropdown, .btn-group') || trigger.parentNode;
            const wasOpen = dropdown.classList.contains('drp-show');
            closeDropdowns();
            if (!wasOpen) dropdown.classList.add('drp-show');
            return;
        }

        if (kind === 'modal') {
            event.preventDefault();
            const modal = targetOf(trigger);
            if (modal) openModal(modal, trigger.getAttribute('data-pc-animate'));
            return;
        }

        if (kind === 'collapse') {
            event.preventDefault();
            const panel = targetOf(trigger);
            if (!panel) return;
            if (panel.classList.contains('show')) {
                slideUp(panel, 300);
                panel.classList.remove('show');
                trigger.classList.remove('show');
            } else {
                slideDown(panel, 300);
                panel.classList.add('show');
                trigger.classList.add('show');
            }
            return;
        }

        if (kind === 'tab') {
            event.preventDefault();
            const pane = document.getElementById(trigger.getAttribute('data-pc-target'));
            if (!pane) return;
            pane.parentElement.querySelectorAll(':scope > .tab-pane').forEach((p) => {
                p.classList.add('hidden');
                p.classList.remove('block');
            });
            pane.classList.remove('hidden');
            pane.classList.add('block');
            const nav = trigger.closest('.nav-tabs');
            nav?.querySelector('li.active')?.classList.remove('active');
            trigger.closest('li')?.classList.add('active');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDropdowns();
            closeModal();
        }
    });

    /* Never let Turbo cache a page mid-modal / mid-dropdown / mid-drawer. */
    document.addEventListener('turbo:before-cache', () => {
        closeDropdowns();
        closeMobileSidebar();
        document.getElementById('modaloverlay')?.remove();
        document.body.classList.remove('modal-open');
        document.querySelectorAll('.modal.show').forEach((m) => {
            m.classList.remove('show', 'animate');
        });
    });
}

/* ------------------------- per-page-load init ----------------------- */

export function initAblePro() {
    bindOnce();

    /* Re-apply the persisted sidebar collapse state (rail mode). */
    if (localStorage.getItem('sigil.sidebar') === 'collapsed') {
        document.querySelector('.pc-sidebar')?.classList.add('pc-sidebar-hide');
    }

    /* Sidebar active-link highlight (from script.js, sans submenu walking —
       Sigil's nav is flat). */
    const pageUrl = window.location.href.split(/[?#]/)[0];
    document.querySelectorAll('.pc-sidebar .pc-navbar a.pc-link').forEach((link) => {
        link.parentNode.classList.toggle('active', link.href === pageUrl && link.getAttribute('href') !== '');
    });
}
