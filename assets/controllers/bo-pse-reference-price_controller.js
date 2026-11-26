import { Controller } from '@hotwired/stimulus';

/**
 * Fills the line under a combination sold with a unit (price per unit of measure, content, order) while
 * the merchant types: the server taxes the price and the core divides, never this script. The price
 * divided is the one the customer pays: the sale price while the combination is on promotion.
 */
export default class extends Controller {
    static targets = ['unitQuantity', 'referenceUnitQuantity', 'unitCode', 'minQuantity', 'quantityStep', 'quantityDecimals', 'line', 'lineText'];

    static values = {
        calcUrl: String,
        priceSelector: String,
        salePriceSelector: String,
        onSaleSelector: String,
        taxRuleSelector: String,
        currencyId: Number,
        productId: { type: Number, default: 0 },
    };

    connect() {
        // A refused rule is asked again rather than dropped with the line.
        this.onPriceChange = () => this.compute({ rulesUnchanged: !this.refused });
        this.watched = [this.priceInput, this.salePriceInput, this.onSaleInput].filter(Boolean);
        this.watched.forEach((input) => input.addEventListener('change', this.onPriceChange));
        // The server rendered the line of the saved values: a reset gives it back, without a request.
        this.initialLine = this.hasLineTarget ? { hidden: this.lineTarget.hidden, html: this.hasLineTextTarget ? this.lineTextTarget.innerHTML : '' } : null;
        // Values are restored after the reset event.
        this.onReset = () => setTimeout(() => this.restoreInitialLine());
        this.onSubmit = (event) => this.holdIfRefused(event);
        this.resetForm = this.form;
        this.resetForm?.addEventListener('reset', this.onReset);
        this.resetForm?.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        (this.watched ?? []).forEach((input) => input.removeEventListener('change', this.onPriceChange));
        this.resetForm?.removeEventListener('reset', this.onReset);
        this.resetForm?.removeEventListener('submit', this.onSubmit);
        this.forgetPendingAnswer();
    }

    /** An answer asked for before the last change no longer counts: it could arrive after the right one. */
    forgetPendingAnswer() {
        this.ticket = (this.ticket ?? 0) + 1;
        this.pending?.abort();
        this.pending = null;

        return this.ticket;
    }

    /** The server would skip the whole row and the page would lose what was typed in it: the first refused row is shown instead. */
    holdIfRefused(event) {
        if (!this.refused) {
            return;
        }
        event.preventDefault();
        if (!event.refusedRowShown) {
            event.refusedRowShown = true;
            this.element.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    /** Without a unit or a price there is no line, but a rule just typed is still checked: the server would refuse the row. */
    async compute(options = {}) {
        // A project copy of the fold without one of these fields keeps the line the server wrote.
        if (!this.hasUnitQuantityTarget || !this.hasReferenceUnitQuantityTarget || !this.hasUnitCodeTarget) {
            return;
        }
        const unitQuantity = this.unitQuantityTarget.value.trim();
        const referenceUnitQuantity = this.referenceUnitQuantityTarget.value.trim();
        const unitCode = this.unitCodeTarget.value;
        const price = this.payablePrice();
        const ticket = this.forgetPendingAnswer();

        if ((unitQuantity === '' || referenceUnitQuantity === '' || unitCode === '' || price === '') && options.rulesUnchanged === true) {
            this.showLine(null);

            return;
        }

        const url = new URL(this.calcUrlValue, window.location.origin);
        url.searchParams.set('price', price);
        url.searchParams.set('tax_rule_id', this.form?.querySelector(this.taxRuleSelectorValue)?.value ?? '0');
        url.searchParams.set('currency_id', String(this.currencyIdValue));
        // A tax whose amount is a feature of the product needs it, as for the price with taxes of the row.
        if (this.productIdValue > 0) {
            url.searchParams.set('product_id', String(this.productIdValue));
        }
        url.searchParams.set('unit_quantity', unitQuantity);
        url.searchParams.set('reference_unit_quantity', referenceUnitQuantity);
        url.searchParams.set('unit_code', unitCode);
        if (this.hasLineTarget) {
            [['min_quantity', 'minQuantity'], ['quantity_step', 'quantityStep'], ['quantity_decimals', 'quantityDecimals']].forEach(([name, target]) => {
                const field = this[`has${target[0].toUpperCase()}${target.slice(1)}Target`] ? this[`${target}Target`] : null;
                if (field) {
                    url.searchParams.set(name, field.value.trim());
                }
            });
            url.searchParams.set('on_sale', this.onSale() ? '1' : '0');
        }

        this.pending = new AbortController();
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: this.pending.signal });
            const payload = await response.json();
            if (ticket !== this.ticket) {
                return;
            }
            // A refused rule answers 422 with the reason, shown as text in the line.
            const error = typeof payload?.error === 'string' && payload.error !== '' ? payload.error : null;
            this.showLine(payload?.line ?? error, error !== null);
        } catch {
            if (ticket === this.ticket) {
                this.showLine(null);
            }
        }
    }

    restoreInitialLine() {
        if (this.initialLine === null) {
            return;
        }
        this.forgetPendingAnswer();
        this.showLine(null);
        this.lineTarget.hidden = this.initialLine.hidden;
        if (this.hasLineTextTarget) {
            this.lineTextTarget.innerHTML = this.initialLine.html;
        }
    }

    /** The parts of the line ({ label, value }), or the reason a rule is refused, as text. */
    showLine(line, refused = false) {
        this.refused = refused;
        if (!this.hasLineTarget) {
            return;
        }
        this.lineTarget.hidden = line === null || line === '' || (Array.isArray(line) && line.length === 0);
        const box = this.lineTarget.querySelector('.bo-combinations-grid__extra-box');
        box?.classList.toggle('bo-combinations-grid__extra-box--info', !refused);
        box?.classList.toggle('bo-combinations-grid__extra-box--danger', refused);
        const icon = box?.querySelector('.bi');
        if (icon) {
            icon.className = `bi ${refused ? 'bi-exclamation-triangle' : 'bi-rulers'}`;
        }
        if (!this.hasLineTextTarget) {
            return;
        }
        if (!Array.isArray(line)) {
            this.lineTextTarget.textContent = line ?? '';

            return;
        }
        this.lineTextTarget.replaceChildren(...line.map(({ label, value }) => {
            const part = document.createElement('span');
            part.className = 'bo-combinations-grid__fact';
            const strong = document.createElement('strong');
            strong.textContent = value;
            part.append(`${label} `, strong);

            return part;
        }));
    }

    onSale() {
        const salePrice = this.salePriceInput?.value ?? '';

        return Boolean(this.onSaleInput?.checked) && salePrice !== '' && Number(salePrice) > 0;
    }

    /** The sale price of a combination on promotion, the price of the row otherwise. */
    payablePrice() {
        if (this.onSale()) {
            return this.salePriceInput.value;
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
