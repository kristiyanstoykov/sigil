import { Controller } from '@hotwired/stimulus';

/*
 * Minimal tab switcher (e.g. dashboard Inbox/Sent). Buttons carry
 * data-tabs-name-param; panels are panel targets with data-tab-name.
 * Active button styling via the active class pair below.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    show(event) {
        const name = event.params.name;

        this.tabTargets.forEach((tab) => {
            const active = tab.dataset.tabsNameParam === name;
            tab.classList.toggle('bg-surface', active);
            tab.classList.toggle('text-primary', active);
            tab.classList.toggle('shadow-sm', active);
            tab.classList.toggle('text-muted', !active);
        });

        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.tabName !== name;
        });
    }
}
