import { Controller } from '@hotwired/stimulus';

/**
 * Inline editor: POSTs the field value to the configured URL on blur/Enter and
 * flips the Bootstrap is-valid / is-invalid classes for visual feedback. The
 * original value is restored on failure.
 *
 * Values (data-bo-inline-edit-*-value):
 *   - `url`       endpoint that accepts POST { title, _token } and replies with JSON
 *                 `{ success: bool, title?: string, message?: string }`
 *   - `token`     CSRF token, sent as the `_token` form field
 *   - `original`  the value rendered server-side, used to skip noop submits and revert
 *   - `field`     POST field name (default `title`) so the controller stays reusable
 */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
        original: String,
        field: { type: String, default: 'title' },
    };

    submit = () => {
        const current = this.element.value.trim();
        if (current === this.originalValue) {
            this.#clearFeedback();
            return;
        }
        if (current === '') {
            this.#showInvalid();
            return;
        }

        const body = new FormData();
        body.append(this.fieldValue, current);
        body.append('_token', this.tokenValue);

        fetch(this.urlValue, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body,
        })
            .then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.success) {
                    this.originalValue = typeof data.title === 'string' ? data.title : current;
                    this.element.value = this.originalValue;
                    this.#showValid();
                } else {
                    this.element.value = this.originalValue;
                    this.#showInvalid();
                }
            })
            .catch(() => {
                this.element.value = this.originalValue;
                this.#showInvalid();
            });
    };

    submitFromEnter = (event) => {
        event.preventDefault();
        this.element.blur();
    };

    #showValid() {
        this.element.classList.remove('is-invalid');
        this.element.classList.add('is-valid');
        window.setTimeout(() => this.#clearFeedback(), 1500);
    }

    #showInvalid() {
        this.element.classList.remove('is-valid');
        this.element.classList.add('is-invalid');
    }

    #clearFeedback() {
        this.element.classList.remove('is-valid', 'is-invalid');
    }
}
