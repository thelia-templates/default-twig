import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Turns the "bulk actions" dropdown into an action trigger: direct actions
 * submit their hidden form (after a confirm for the destructive one), and "edit"
 * opens the bulk edit modal. The select resets itself so the same action can be
 * replayed on a new selection.
 */
export default class extends Controller {
    static values = {
        modal: { type: String, default: '#product-bulk-edit-modal' },
        confirmDelete: { type: String, default: '' },
    };

    run(event) {
        const action = event.currentTarget.value;
        event.currentTarget.value = '';

        if (action === '') {
            return;
        }

        if (action === 'edit') {
            const modalEl = document.querySelector(this.modalValue);
            if (modalEl) {
                (Modal.getInstance(modalEl) ?? new Modal(modalEl)).show();
            }

            return;
        }

        if (action === 'delete') {
            const message = this.confirmDeleteValue || 'Delete the selected products?';
            if (!window.confirm(message)) {
                return;
            }
        }

        const form = document.querySelector(`form[data-bulk-form="${action}"]`);
        form?.requestSubmit();
    }
}
