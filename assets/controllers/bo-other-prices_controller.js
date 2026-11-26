import { Controller } from '@hotwired/stimulus';

/** Marks the "Other prices" tab whose form holds changes. */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.onChange = (event) => this.mark(event.target, true);
        this.onReset = (event) => this.mark(event.target, false);
        this.element.addEventListener('input', this.onChange);
        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('reset', this.onReset);
    }

    disconnect() {
        this.element.removeEventListener('input', this.onChange);
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('reset', this.onReset);
    }

    mark(field, dirty) {
        if (dirty && field.closest('form')?.method !== 'post') {
            return;
        }
        const panel = this.panelTargets.find((candidate) => candidate.contains(field));
        const tab = panel ? this.tabTargets.find((candidate) => candidate.getAttribute('aria-controls') === panel.id) : null;
        if (!tab) {
            return;
        }
        if (dirty) {
            tab.setAttribute('data-dirty', '');
        } else {
            tab.removeAttribute('data-dirty');
        }
    }
}
