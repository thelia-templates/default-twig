import { Controller } from '@hotwired/stimulus';

/**
 * Guards a start/end date pair entered as free text. Parses both fields with the
 * PHP date format supplied by the server (formats are locale-dependent, so neither
 * lexical nor native Date parsing is reliable) and blocks submission when the start
 * date is later than the end date.
 */
export default class extends Controller {
    static targets = ['start', 'end', 'message'];
    static values = { format: String };

    connect() {
        this.validate();
    }

    validate() {
        const start = this.parse(this.startTarget.value);
        const end = this.parse(this.endTarget.value);
        const invalid = start !== null && end !== null && start > end;

        this.endTarget.classList.toggle('is-invalid', invalid);
        this.endTarget.setCustomValidity(invalid ? this.invalidMessage : '');
        if (this.hasMessageTarget) {
            this.messageTarget.hidden = !invalid;
        }
    }

    get invalidMessage() {
        return this.hasMessageTarget ? this.messageTarget.textContent.trim() : 'The end date must be after the start date.';
    }

    /**
     * Parse a value against the configured PHP format into a sortable number
     * (YYYYMMDDHHmmss), or null when the value is empty or does not match.
     */
    parse(value) {
        const raw = value.trim();
        if (raw === '' || !this.formatValue) {
            return null;
        }

        const tokens = { Y: '(\\d{4})', m: '(\\d{1,2})', n: '(\\d{1,2})', d: '(\\d{1,2})', j: '(\\d{1,2})', H: '(\\d{1,2})', G: '(\\d{1,2})', h: '(\\d{1,2})', g: '(\\d{1,2})', i: '(\\d{1,2})', s: '(\\d{1,2})' };
        const order = [];
        let pattern = '';
        for (const char of this.formatValue) {
            if (tokens[char]) {
                pattern += tokens[char];
                order.push(char);
            } else {
                pattern += char.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }
        }

        const match = new RegExp('^' + pattern + '$').exec(raw);
        if (match === null) {
            return null;
        }

        const parts = { Y: 0, m: 1, d: 1, H: 0, i: 0, s: 0 };
        order.forEach((char, index) => {
            const key = char === 'n' ? 'm' : char === 'j' ? 'd' : (char === 'G' || char === 'h' || char === 'g') ? 'H' : char;
            parts[key] = parseInt(match[index + 1], 10);
        });

        return ((((parts.Y * 100 + parts.m) * 100 + parts.d) * 100 + parts.H) * 100 + parts.i) * 100 + parts.s;
    }
}
