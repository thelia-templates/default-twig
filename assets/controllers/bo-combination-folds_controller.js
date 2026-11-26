import { Controller } from '@hotwired/stimulus';

/** Opens or closes every fold of the grid from a switch, checked once they are all open. */
export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.onClick = (event) => {
            if (event.target.closest('.bo-combinations-grid__more')) {
                setTimeout(() => this.refresh());
            }
        };
        this.element.addEventListener('click', this.onClick);
        this.refresh();
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
    }

    toggleAll(event) {
        const open = event.currentTarget.checked;
        this.folds().forEach((button) => {
            if ((button.getAttribute('aria-expanded') === 'true') !== open) {
                button.click();
            }
        });
        this.refresh();
    }

    allOpen() {
        const folds = this.folds();

        return folds.length > 0 && folds.every((button) => button.getAttribute('aria-expanded') === 'true');
    }

    refresh() {
        const open = this.allOpen();
        this.toggleTargets.forEach((toggle) => {
            toggle.checked = open;
        });
    }

    folds() {
        return Array.from(this.element.querySelectorAll('.bo-combinations-grid__more'));
    }
}
