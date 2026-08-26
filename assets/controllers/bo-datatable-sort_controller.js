import { Controller } from '@hotwired/stimulus';

/**
 * Mobile sort fallback for BoDataTable. Below the breakpoint where a sortable column's <th> (and
 * its sort link) is hidden, this <select> is the only way left to change the sort: each <option>
 * carries a full sort URL, and picking one just navigates there - mirrors bo-default-radio's
 * "select -> go to precomputed URL" pattern, kept separate because it targets a different DOM
 * shape (a single <select> with per-option URLs, not a value per radio input) and a different
 * DataTable concern.
 */
export default class extends Controller {
    navigate(event) {
        const url = event.target.value;
        if (url) {
            window.location.assign(url);
        }
    }
}
