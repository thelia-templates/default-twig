import { Controller } from '@hotwired/stimulus';

/**
 * Shows or hides the quantity rules of the combinations tab: the column that opens each
 * row's panel and the default combination's card. They stay out of sight while every
 * combination sells by the piece, from one, one by one. Hiding them keeps their fields in
 * the form, so a save writes back the values they hold.
 */
export default class extends Controller {
    static targets = ['switch', 'toggleable'];

    toggle() {
        const shown = this.switchTarget.checked;
        this.toggleableTargets.forEach((element) => { element.hidden = !shown; });

        if (!shown) {
            this.element.querySelectorAll('tr[data-detail-for]').forEach((panel) => { panel.hidden = true; });
            this.element.querySelectorAll('[aria-controls^="pse-quantity-rules-"]').forEach((button) => {
                button.setAttribute('aria-expanded', 'false');
            });
        }
    }
}
