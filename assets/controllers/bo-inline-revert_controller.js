import { Controller } from '@hotwired/stimulus';

/**
 * Per-row revert for inline-edited values. Remembers the field value on connect,
 * enables the revert button once the field changes, and restores the original
 * value on click - mirroring the legacy "cancel changes" affordance.
 */
export default class extends Controller {
    static targets = ['field', 'button'];

    connect() {
        this.originalValue = this.fieldTarget.value;
        this.disableButton();
    }

    dirty() {
        this.buttonTarget.disabled = this.fieldTarget.value === this.originalValue;
    }

    revert() {
        this.fieldTarget.value = this.originalValue;
        this.disableButton();
    }

    disableButton() {
        this.buttonTarget.disabled = true;
    }
}
