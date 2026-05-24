import { Controller } from '@hotwired/stimulus';

/**
 * Live counter for the combination builder dialog. Counts the cartesian product
 * of every checked attribute value, grouped per attribute id, and toggles the
 * submit button depending on whether at least one combination would be generated.
 */
export default class extends Controller {
    static targets = ['checkbox', 'counter', 'submit'];

    connect() {
        this.recount();
    }

    recount() {
        const perAttribute = new Map();
        for (const checkbox of this.checkboxTargets) {
            if (!checkbox.checked) {
                continue;
            }
            const attributeId = checkbox.dataset.attributeId;
            perAttribute.set(attributeId, (perAttribute.get(attributeId) ?? 0) + 1);
        }

        const total = perAttribute.size === 0
            ? 0
            : Array.from(perAttribute.values()).reduce((acc, count) => acc * count, 1);

        if (this.hasCounterTarget) {
            this.counterTarget.textContent = String(total);
        }
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = total === 0;
        }
    }
}
