import { Controller } from '@hotwired/stimulus';

/**
 * Toggles a BoDataTable row's detail line (the columns hidden on narrow viewports).
 * Deliberately not a Bootstrap collapse: collapsing a <tr> breaks its layout, so this
 * controller just flips the native `hidden` attribute and keeps aria-expanded in sync.
 */
export default class extends Controller {
    toggle() {
        const detailId = this.element.getAttribute('aria-controls');
        const detailRow = detailId ? document.getElementById(detailId) : null;
        if (!detailRow) {
            return;
        }

        const expanded = this.element.getAttribute('aria-expanded') === 'true';
        detailRow.hidden = expanded;
        this.element.setAttribute('aria-expanded', String(!expanded));
    }
}
