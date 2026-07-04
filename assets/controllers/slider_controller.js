import { Controller } from '@hotwired/stimulus';

/*
 * Horizontal card slider (dashboard certificate carousel): prev/next arrows
 * and dot indicators over a translateX track. Slides are track children.
 */
export default class extends Controller {
    static targets = ['track', 'dot'];

    index = 0;

    prev() { this.goTo(this.index - 1); }
    next() { this.goTo(this.index + 1); }

    set(event) { this.goTo(event.params.index); }

    goTo(i) {
        const count = this.trackTarget.children.length;
        this.index = Math.max(0, Math.min(count - 1, i));
        this.trackTarget.style.transform = `translateX(-${this.index * 100}%)`;

        this.dotTargets.forEach((dot, d) => {
            const active = d === this.index;
            dot.classList.toggle('bg-primary', active);
            dot.classList.toggle('w-[22px]', active);
            dot.classList.toggle('bg-line', !active);
            dot.classList.toggle('w-[7px]', !active);
        });
    }
}
