import { Controller } from '@hotwired/stimulus';
import { Tooltip } from 'bootstrap';

/**
 * Live state of one combination (<tbody>): sellable icon, stock unit, low stock line (which takes the restock date).
 * Wordings come from the table (`data-combination-band-texts`), the same the server renders with.
 */
const ICONS = { unlimited: 'bi-infinity', pre: 'bi-clock-history', none: 'bi-cart-x', held: 'bi-cart-fill', sync: 'bi-cart' };

export default class extends Controller {
    static targets = [
        'quantity', 'stockUnit', 'sellable', 'sellableIcon', 'policy', 'preorderLimit', 'threshold',
        'decimals', 'unitCode', 'lowLine', 'lowText', 'restockSlot', 'restockHome', 'restockDate', 'restockLabel', 'restockMoved',
    ];

    static values = {
        reserved: Number,
        carts: Number,
        expiry: String,
        measured: Boolean,
        shopThreshold: Number,
        unlimitedPolicy: Number,
        limitedPolicy: Number,
    };

    connect() {
        this.texts = JSON.parse(this.element.closest('table')?.dataset.combinationBandTexts ?? '{}');
        this.sync = this.sync.bind(this);
        // Values are restored after the reset event.
        this.onReset = () => setTimeout(this.sync);
        this.form = this.element.closest('form');
        this.element.addEventListener('input', this.sync);
        this.element.addEventListener('change', this.sync);
        this.form?.addEventListener('reset', this.onReset);
        this.sync();
    }

    disconnect() {
        this.element.removeEventListener('input', this.sync);
        this.element.removeEventListener('change', this.sync);
        this.form?.removeEventListener('reset', this.onReset);
    }

    openRules() {
        const toggle = this.element.querySelector('.bo-combinations-grid__more');
        if (toggle && toggle.getAttribute('aria-expanded') !== 'true') {
            toggle.click();
        }
        if (this.hasUnitCodeTarget) {
            this.unitCodeTarget.focus();
        }
    }

    sync() {
        if (!this.hasQuantityTarget) {
            return;
        }

        const stock = this.number(this.quantityTarget.value) ?? 0;
        const decimals = this.hasDecimalsTarget ? Number(this.decimalsTarget.value) || 0 : 0;
        const symbol = this.hasUnitCodeTarget ? this.unitCodeTarget.selectedOptions[0]?.dataset.symbol ?? '' : '';
        const measure = symbol !== '' && decimals > 0;
        const write = (quantity, places = decimals) => this.write(quantity, places) + (measure ? ` ${symbol}` : '');

        if (this.hasStockUnitTarget) {
            this.stockUnitTarget.hidden = !measure;
            this.stockUnitTarget.textContent = symbol;
        }

        if (!this.measuredValue) {
            return;
        }

        const policy = this.hasPolicyTarget ? Number(this.policyTarget.value) : 0;
        const limit = this.hasPreorderLimitTarget ? this.number(this.preorderLimitTarget.value) ?? 0 : 0;
        this.drawSellable(stock, policy, limit, decimals, write);
        this.drawLowStock(stock, policy, limit, decimals, write);
    }

    drawSellable(stock, policy, limit, decimals, write) {
        if (!this.hasSellableTarget) {
            return;
        }

        let available = stock - this.reservedValue + (policy === this.limitedPolicyValue ? limit : 0);
        available = Math.max(0, decimals > 0 ? available : Math.floor(available));
        let state = 'sync';
        if (policy === this.unlimitedPolicyValue) {
            state = 'unlimited';
        } else if (available <= 0) {
            state = 'none';
        } else if (policy === this.limitedPolicyValue && stock <= 0) {
            state = 'pre';
        } else if (Math.abs(available - stock) > 1e-9) {
            state = 'held';
        }

        const figures = { '%available%': write(available), '%stock%': write(stock), '%limit%': write(limit) };
        const head = this.fill(this.texts[state], figures);
        const held = this.reservedValue > 0
            ? this.fill(this.texts.heldByCarts, { '%quantity%': write(this.reservedValue), '%carts%': String(this.cartsValue), '%date%': this.expiryValue })
            : this.texts.notHeld ?? '';
        const lead = this.texts[`${state}Body`] ? `${this.fill(this.texts[`${state}Body`], figures)} ` : '';

        this.sellableTarget.dataset.state = state;
        this.sellableTarget.setAttribute('aria-label', head);
        const content = `<strong>${this.escape(head)}</strong> ${this.escape(lead + held)}`;
        this.sellableTarget.setAttribute('data-bs-title', content);
        this.sellableTarget.removeAttribute('title');
        Tooltip.getInstance(this.sellableTarget)?.setContent({ '.tooltip-inner': content });
        if (this.hasSellableIconTarget) {
            this.sellableIconTarget.className = `bi ${ICONS[state]}`;
        }
    }

    drawLowStock(stock, policy, limit, decimals, write) {
        if (!this.hasLowLineTarget) {
            return;
        }

        const own = this.hasThresholdTarget ? this.number(this.thresholdTarget.value) : null;
        const threshold = own ?? this.shopThresholdValue;
        // Same rule as the core alert.
        const low = stock <= threshold;
        this.lowLineTarget.hidden = !low;

        if (low && this.hasLowTextTarget) {
            this.lowTextTarget.textContent = stock <= 0
                ? this.fill(this.texts[`out${policy}`] ?? this.texts.out0, { '%limit%': write(limit) })
                : this.fill(own === null ? this.texts.lowShop : this.texts.low, { '%stock%': write(stock), '%threshold%': write(threshold) });
        }

        this.moveRestockDate(low);
    }

    moveRestockDate(low) {
        if (!this.hasRestockDateTarget || !this.hasRestockSlotTarget || !this.hasRestockHomeTarget) {
            return;
        }

        const field = this.restockDateTarget;
        if (low && field.parentElement !== this.restockSlotTarget) {
            this.restockSlotTarget.append(field);
        } else if (!low && field.parentElement === this.restockSlotTarget) {
            (this.hasRestockLabelTarget ? this.restockLabelTarget : this.restockHomeTarget.firstElementChild).after(field);
        }
        if (this.hasRestockMovedTarget) {
            this.restockMovedTarget.hidden = !low;
        }
        if (this.hasRestockLabelTarget) {
            this.restockLabelTarget.hidden = low;
        }
    }

    number(value) {
        const trimmed = String(value ?? '').trim().replace(',', '.');

        return trimmed === '' || Number.isNaN(Number(trimmed)) ? null : Number(trimmed);
    }

    write(quantity, decimals) {
        return new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: Math.max(0, decimals) }).format(quantity);
    }

    fill(text, figures) {
        return Object.entries(figures).reduce((written, [key, value]) => written.split(key).join(value), text ?? '');
    }

    escape(text) {
        const span = document.createElement('span');
        span.textContent = text;

        return span.innerHTML;
    }
}
