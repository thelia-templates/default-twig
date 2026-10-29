import { Controller } from '@hotwired/stimulus';

/**
 * Previews the price of a combination per reference unit (13,32 € / l) while the merchant
 * types, the way bo-pse-price-with-tax previews the taxed price: the server taxes the price
 * and the core divides, never this script. Asked when a unit field is left and when the
 * price of the combination changes.
 */
export default class extends Controller {
    static targets = ['unitQuantity', 'referenceUnitQuantity', 'unitCode', 'output'];

    static values = {
        calcUrl: String,
        priceSelector: String,
        taxRuleSelector: String,
        currencyId: Number,
    };

    connect() {
        this.onPriceChange = () => this.compute();
        this.priceInput?.addEventListener('change', this.onPriceChange);
        this.compute();
    }

    disconnect() {
        this.priceInput?.removeEventListener('change', this.onPriceChange);
    }

    async compute() {
        const unitQuantity = this.unitQuantityTarget.value.trim();
        const referenceUnitQuantity = this.referenceUnitQuantityTarget.value.trim();
        const unitCode = this.unitCodeTarget.value;
        const price = this.priceInput?.value ?? '';

        if (unitQuantity === '' || referenceUnitQuantity === '' || unitCode === '' || price === '') {
            this.outputTarget.textContent = '-';

            return;
        }

        const url = new URL(this.calcUrlValue, window.location.origin);
        url.searchParams.set('price', price);
        url.searchParams.set('tax_rule_id', this.form?.querySelector(this.taxRuleSelectorValue)?.value ?? '0');
        url.searchParams.set('currency_id', String(this.currencyIdValue));
        url.searchParams.set('unit_quantity', unitQuantity);
        url.searchParams.set('reference_unit_quantity', referenceUnitQuantity);
        url.searchParams.set('unit_code', unitCode);

        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const payload = await response.json();
            this.outputTarget.textContent = payload?.formatted ?? '-';
        } catch {
            this.outputTarget.textContent = '-';
        }
    }

    get form() {
        return this.element.closest('form');
    }

    get priceInput() {
        return this.priceSelectorValue ? this.form?.querySelector(this.priceSelectorValue) ?? null : null;
    }
}
