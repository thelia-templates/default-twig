import { Controller } from '@hotwired/stimulus';

/**
 * Loads the products of the categories selected for a sale and renders them as
 * checkboxes, preserving any product already ticked (including the ones
 * server-rendered for the current sale).
 */
export default class extends Controller {
    static targets = ['categories', 'productZone', 'emptyHint'];
    static values = { productsUrl: String };

    async loadProducts() {
        const categoryIds = Array.from(this.categoriesTarget.selectedOptions).map((option) => option.value);
        if (categoryIds.length === 0) {
            return;
        }

        const url = `${this.productsUrlValue}?categories=${encodeURIComponent(categoryIds.join(','))}`;
        let products = [];
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.ok) {
                const data = await response.json();
                products = Array.isArray(data.products) ? data.products : [];
            }
        } catch {
            return;
        }

        if (this.hasEmptyHintTarget) {
            this.emptyHintTarget.remove();
        }

        for (const product of products) {
            if (this.productZoneTarget.querySelector(`[data-product-id="${product.id}"]`)) {
                continue;
            }
            this.productZoneTarget.appendChild(this.buildRow(product));
        }
    }

    buildRow(product) {
        const wrapper = document.createElement('div');
        wrapper.className = 'form-check';
        wrapper.dataset.productId = product.id;

        const input = document.createElement('input');
        input.className = 'form-check-input';
        input.type = 'checkbox';
        input.name = 'products[]';
        input.value = product.id;
        input.id = `sale-product-${product.id}`;
        input.checked = true;

        const label = document.createElement('label');
        label.className = 'form-check-label';
        label.htmlFor = input.id;
        const ref = document.createElement('code');
        ref.textContent = product.ref;
        label.append(ref, ` ${product.title}`);

        wrapper.append(input, label);
        return wrapper;
    }
}
