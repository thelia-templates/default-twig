import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['body', 'save', 'product'];

    static values = {
        urlTemplate: String,
        inputContainerSelector: { type: String, default: '[data-bo-sale-product-attributes-inputs]' },
    };

    connect() {
        this.boundOnShow = this.onShow.bind(this);
        this.element.addEventListener('show.bs.modal', this.boundOnShow);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this.boundOnShow);
    }

    async onShow(event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        this.currentProductId = trigger.getAttribute('data-product-id');
        this.currentProductLabel = trigger.getAttribute('data-product-label') || '';

        const titleEl = this.element.querySelector('[data-bo-sale-product-attributes-target="product"]');
        if (titleEl) titleEl.textContent = this.currentProductLabel;

        const inputContainer = document.querySelector(this.inputContainerSelectorValue);
        const existing = inputContainer?.querySelector(`input[name="product_attributes[${this.currentProductId}]"]`);
        const selectedCsv = existing ? existing.value : '';

        const url = this.urlTemplateValue.replace(/\/0(\?|$)/, `/${this.currentProductId}$1`)
            + (this.urlTemplateValue.includes('?') ? '&' : '?')
            + 'selected=' + encodeURIComponent(selectedCsv);

        this.bodyTarget.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status" aria-hidden="true"></div></div>';

        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            this.bodyTarget.innerHTML = await response.text();
        } catch (error) {
            this.bodyTarget.innerHTML = `<div class="alert alert-danger mb-0">${error.message}</div>`;
        }
    }

    save() {
        const checked = Array.from(this.bodyTarget.querySelectorAll('input[type="checkbox"]:checked')).map(c => c.value);
        const inputContainer = document.querySelector(this.inputContainerSelectorValue);
        if (!inputContainer || !this.currentProductId) return;

        const existing = inputContainer.querySelector(`input[name="product_attributes[${this.currentProductId}]"]`);
        if (existing) existing.remove();

        if (checked.length > 0) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `product_attributes[${this.currentProductId}]`;
            input.value = checked.join(',');
            inputContainer.appendChild(input);
        }

        // Update the trigger button badge so user sees the count
        const trigger = document.querySelector(`[data-product-id="${this.currentProductId}"][data-bs-target="#sale-product-attributes-modal"]`);
        const badge = trigger?.querySelector('[data-bo-sale-product-attributes-target="badge"]');
        if (badge) {
            badge.textContent = checked.length > 0 ? checked.length : '';
            badge.classList.toggle('d-none', checked.length === 0);
        }

        const modal = window.bootstrap?.Modal.getInstance(this.element);
        modal?.hide();
    }
}
