import { Controller } from '@hotwired/stimulus';

/**
 * Shows or hides the quantity rules of the combinations tab: the column that opens each
 * row's panel and the default combination's card. They stay out of sight while every
 * combination sells by the piece, from one, one by one. Switching them off puts every field
 * back to those defaults, so that a save writes a sale by the piece and the switch comes back
 * off: the tab reads the switch from the rules it finds in the combinations.
 */
const PIECE_DEFAULTS = {
    min_quantity: '1',
    quantity_step: '1',
    quantity_decimals: '0',
    unit_code: '',
    unit_quantity: '',
    reference_unit_quantity: '',
};

export default class extends Controller {
    static targets = ['switch', 'toggleable'];

    toggle() {
        const shown = this.switchTarget.checked;
        this.toggleableTargets.forEach((element) => { element.hidden = !shown; });

        if (!shown) {
            this.resetRules();
            this.element.querySelectorAll('tr[data-detail-for]').forEach((panel) => { panel.hidden = true; });
            this.element.querySelectorAll('[aria-controls^="pse-quantity-rules-"]').forEach((button) => {
                button.setAttribute('aria-expanded', 'false');
            });
        }
    }

    resetRules() {
        this.element.querySelectorAll('.bo-quantity-rules').forEach((rules) => {
            Object.entries(PIECE_DEFAULTS).forEach(([name, value]) => {
                // Keyed in the grid (name[12]), plain in the default combination's card.
                rules.querySelectorAll(`[name="${name}"], [name^="${name}["]`).forEach((field) => {
                    if (field.value === value) {
                        return;
                    }
                    field.value = value;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
        });
    }
}
