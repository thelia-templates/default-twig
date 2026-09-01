import { Controller } from '@hotwired/stimulus';
import { Dropdown } from 'bootstrap';

/**
 * The row-actions kebab menu needs to escape two things Bootstrap's default (Popper `absolute`
 * strategy) dropdown doesn't:
 *
 * 1. Any clipping ancestor - `.table-responsive` (`overflow-x: auto`) and `.card` (`overflow:
 *    hidden`, to keep its rounded corners). Forcing those ancestors' overflow to `visible` while
 *    open (an earlier version of this controller) works, but also lifts the card's corner
 *    clipping, letting the table's square background spill visibly past them. Popper's `fixed`
 *    strategy positions the menu relative to the viewport instead, so it isn't clipped by either
 *    ancestor in the first place and neither one needs touching.
 * 2. A stacking context: the actions cell is `position: sticky` with its own z-index (see
 *    .datatable-sticky-actions in main.scss), so every row's cell shares that z-index - and a
 *    `position: fixed` menu doesn't escape its ancestor's stacking context (only its clipping),
 *    so the next row's cell, later in the DOM, still paints on top of this row's open menu. This
 *    controller lifts the current row's sticky cell above its siblings for as long as it's open.
 */
export default class extends Controller {
    connect() {
        const toggle = this.element.querySelector('[data-bs-toggle="dropdown"]');
        if (toggle) {
            Dropdown.getOrCreateInstance(toggle, {
                popperConfig: defaultConfig => ({ ...defaultConfig, strategy: 'fixed' }),
            });
        }

        this.boundOnShow = this.onShow.bind(this);
        this.boundOnHidden = this.onHidden.bind(this);
        this.element.addEventListener('show.bs.dropdown', this.boundOnShow);
        this.element.addEventListener('hidden.bs.dropdown', this.boundOnHidden);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.dropdown', this.boundOnShow);
        this.element.removeEventListener('hidden.bs.dropdown', this.boundOnHidden);
    }

    onShow() {
        const stickyCell = this.element.closest('.datatable-sticky-actions');
        if (stickyCell) {
            this.liftedStickyCell = stickyCell;
            this.liftedStickyCellZIndex = stickyCell.style.zIndex;
            stickyCell.style.zIndex = '2';
        }
    }

    onHidden() {
        if (this.liftedStickyCell) {
            this.liftedStickyCell.style.zIndex = this.liftedStickyCellZIndex;
            this.liftedStickyCell = null;
        }
    }
}
