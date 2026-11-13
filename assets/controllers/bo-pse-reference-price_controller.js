import { Controller } from '@hotwired/stimulus';

/**
 * Previews the price of a combination per reference unit (13,32 € / l) while the merchant
 * types, the way bo-pse-price-with-tax previews the taxed price: the server taxes the price
 * and the core divides, never this script. Asked when a unit field is left and when the
 * price of the combination changes. The price divided is the one the customer pays: the sale
 * price while the combination is on promotion, the price otherwise.
 */
export default class extends Controller {
    static targets = ['unitQuantity', 'referenceUnitQuantity', 'unitCode', 'output'];

    static values = {
        calcUrl: String,
        priceSelector: String,
        salePriceSelector: String,
        onSaleSelector: String,
        taxRuleSelector: String,
        currencyId: Number,
    };

    connect() {
        this.onPriceChange = () => this.compute();
        this.watched = [this.priceInput, this.salePriceInput, this.onSaleInput].filter(Boolean);
        this.watched.forEach((input) => input.addEventListener('change', this.onPriceChange));
        this.compute();
    }

    disconnect() {
        (this.watched ?? []).forEach((input) => input.removeEventListener('change', this.onPriceChange));
    }

    async compute() {
        const unitQuantity = this.unitQuantityTarget.value.trim();
        const referenceUnitQuantity = this.referenceUnitQuantityTarget.value.trim();
        const unitCode = this.unitCodeTarget.value;
        const price = this.payablePrice();

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
            // A refused rule answers 422 with the reason: shown as text, in place of the dash.
            this.outputTarget.textContent = payload?.formatted ?? (typeof payload?.error === 'string' && payload.error !== '' ? payload.error : '-');
        } catch {
            this.outputTarget.textContent = '-';
        }
    }

    /** The sale price of a combination on promotion, the price of the row otherwise. */
    payablePrice() {
        const salePrice = this.salePriceInput?.value ?? '';
        if (this.onSaleInput?.checked && salePrice !== '' && Number(salePrice) > 0) {
            return salePrice;
        }

        return this.priceInput?.value ?? '';
    }

    get form() {
        return this.element.closest('form');
    }

    get priceInput() {
        return this.priceSelectorValue ? this.form?.querySelector(this.priceSelectorValue) ?? null : null;
    }

    get salePriceInput() {
        return this.salePriceSelectorValue ? this.form?.querySelector(this.salePriceSelectorValue) ?? null : null;
    }

    get onSaleInput() {
        return this.onSaleSelectorValue ? this.form?.querySelector(this.onSaleSelectorValue) ?? null : null;
    }
}
