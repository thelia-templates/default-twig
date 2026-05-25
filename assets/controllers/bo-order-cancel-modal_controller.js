import { Controller } from '@hotwired/stimulus';

/**
 * Wires the order cancel modal opened from the orders list.
 *
 * Reads the following data-* attributes from the modal trigger element
 * (the Cancel action button) on `show.bs.modal`:
 *   - data-order-id          : the order id (injected into the hidden input)
 *   - data-order-ref         : the order reference (injected into the modal body)
 *   - data-order-cancel-url  : the cancel route URL (set on the <form action>)
 */
export default class extends Controller {
    static targets = ['form', 'orderIdInput', 'orderRefLabel'];

    connect() {
        this.boundOnShow = this.onShow.bind(this);
        this.element.addEventListener('show.bs.modal', this.boundOnShow);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this.boundOnShow);
    }

    onShow(event) {
        const trigger = event.relatedTarget;
        if (!trigger) {
            return;
        }

        const orderId = trigger.getAttribute('data-order-id');
        const orderRef = trigger.getAttribute('data-order-ref');
        const cancelUrl = trigger.getAttribute('data-order-cancel-url');

        if (this.hasOrderIdInputTarget && orderId !== null) {
            this.orderIdInputTarget.value = orderId;
        }

        if (this.hasOrderRefLabelTarget && orderRef !== null) {
            this.orderRefLabelTarget.textContent = orderRef;
        }

        if (this.hasFormTarget && cancelUrl !== null) {
            const existing = this.formTarget.getAttribute('action') || '';
            const tokenMatch = existing.match(/[?&]_token=([^&]*)/);
            const token = tokenMatch ? tokenMatch[1] : '';
            this.formTarget.setAttribute(
                'action',
                token ? `${cancelUrl}?_token=${token}` : cancelUrl,
            );
        }
    }
}
