import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static values = { target: String };

    connect() {
        const modalElement = this.targetValue ? document.querySelector(this.targetValue) : null;
        if (!modalElement) {
            return;
        }

        (Modal.getInstance(modalElement) ?? new Modal(modalElement)).show();

        modalElement.addEventListener('shown.bs.modal', () => {
            const email = modalElement.querySelector('input[type="email"]');
            email?.focus();
        }, { once: true });
    }
}
