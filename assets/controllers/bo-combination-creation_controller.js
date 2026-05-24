import { Controller } from '@hotwired/stimulus';

/**
 * Assembles the `combination_attributes` CSV expected by the legacy creation
 * endpoint from one `<select>` per attribute, and prevents submission when no
 * value has been picked.
 */
export default class extends Controller {
    static targets = ['select', 'output', 'submit', 'error'];

    connect() {
        this.sync();
    }

    sync() {
        const selectedAvIds = [];
        for (const select of this.selectTargets) {
            const value = select.value;
            if (value !== '') {
                selectedAvIds.push(value);
            }
        }

        if (this.hasOutputTarget) {
            this.outputTarget.value = selectedAvIds.join(',');
        }

        const hasSelection = selectedAvIds.length > 0;
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = !hasSelection;
        }
        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('d-none');
            this.errorTarget.textContent = '';
        }
    }
}
