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
            tab.classList.toggle('text-primary-500', active);
            tab.classList.toggle('bg-primary-500/10', active);
            tab.classList.toggle('hover:text-theme-headings', !active);
            tab.classList.toggle('hover:bg-theme-activebg', !active);
        });

        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.tabName !== name;
        });
    }
}
