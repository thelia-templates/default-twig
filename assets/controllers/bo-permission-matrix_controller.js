import { Controller } from '@hotwired/stimulus';

/**
 * Bulk-toggle helper for the profile permission matrix (Resource access
 * rights / Module access rights).
 *
 * Reduces ~200 clicks to ~12 when configuring a profile by exposing
 * - `toggleAll` : flips every checkbox inside the controller scope (tbody),
 * - `toggleLine`: flips every checkbox of the row hosting the clicked button.
 *
 * Mounted on the access matrix `<form>` element with
 * `data-controller="bo-permission-matrix"`. Buttons use
 * `data-action="bo-permission-matrix#toggleAll"` and `#toggleLine`.
 */
export default class extends Controller {
    toggleAll(event) {
        event.preventDefault();
        this.element
            .querySelectorAll('tbody input[type="checkbox"]')
            .forEach((checkbox) => {
                checkbox.checked = !checkbox.checked;
            });
    }

    toggleLine(event) {
        event.preventDefault();
        const row = event.currentTarget.closest('tr');
        if (row === null) {
            return;
        }
        row.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
            checkbox.checked = !checkbox.checked;
        });
    }
}
