import { Controller } from '@hotwired/stimulus';

/**
 * Show or hide a destination's config block according to the matching
 * "Activate" checkbox. Each config target carries a `data-classname` matching
 * the classname of its destination's checkbox.
 */
export default class extends Controller {
    static targets = ['activate', 'config'];

    connect() {
        this.activateTargets.forEach((checkbox) => this.sync(checkbox));
    }

    toggle(event) {
        this.sync(event.currentTarget);
    }

    sync(checkbox) {
        const classname = checkbox.dataset.classname;
        if (!classname) {
            return;
        }
        this.configTargets
            .filter((config) => config.dataset.classname === classname)
            .forEach((config) => {
                config.hidden = !checkbox.checked;
            });
    }
}
