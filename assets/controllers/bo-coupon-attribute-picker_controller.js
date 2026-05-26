import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
        targetId: String,
    };

    async refresh(event) {
        const select = event.currentTarget;
        const attributeId = select.value;
        const wrapper = document.getElementById('input-coupon-attribute-avs');
        const target = document.getElementById(this.targetIdValue);

        if (!attributeId || attributeId === '0') {
            wrapper?.setAttribute('hidden', '');
            if (target) {
                target.innerHTML = '';
            }
            return;
        }

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({ attribute_id: attributeId }),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            if (target) {
                target.innerHTML = await response.text();
            }
            wrapper?.removeAttribute('hidden');
        } catch (error) {
            wrapper?.setAttribute('hidden', '');
        }
    }
}
