import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Pops up the "changing the template may drop combinations" warning when the
 * user selects a different template on the product edit form. The initial value
 * is captured on connect so the notice only fires when the choice actually
 * changes.
 */
export default class extends Controller {
    static values = { modal: String };

    connect() {
        this.initialValue = this.element.value;
    }

    warn() {
        if (this.element.value === this.initialValue) {
            return;
        }
        const selector = this.modalValue || '#product-template-notice-modal';
        const modalEl = document.querySelector(selector);
        if (!modalEl) {
            return;
        }
        (Modal.getInstance(modalEl) ?? new Modal(modalEl)).show();
    }
}
