import { Controller } from '@hotwired/stimulus';

/**
 * Exclusive "set as default" radio for DataTable rows. Selecting a radio
 * navigates to its tokenized toggle URL, which flips the chosen row to default
 * and lets the server reset the others - the page reloads with a single
 * default checked, matching the legacy back-office behaviour.
 */
export default class extends Controller {
    static values = { url: String };

    select() {
        if (this.urlValue) {
            window.location.assign(this.urlValue);
        }
    }
}
