import { Controller } from '@hotwired/stimulus';

/**
 * Copy the server-rendered summary held in the `source` target to the clipboard.
 * Uses the async Clipboard API in secure contexts and falls back to selecting the
 * textarea and document.execCommand('copy') otherwise (e.g. plain HTTP). On success
 * the trigger button shows its "Copied" label for 2 seconds, then restores itself.
 */
export default class extends Controller {
    static targets = ['source'];
    static values = { copiedLabel: String };

    async copy(event) {
        const button = event.currentTarget;
        const text = this.sourceTarget.value;

        if (await this.write(text)) {
            this.flash(button);
        }
    }

    async write(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);

                return true;
            } catch (error) {
                // Clipboard API refused (permissions, focus): fall back to the legacy path.
            }
        }

        return this.legacyCopy();
    }

    legacyCopy() {
        const textarea = this.sourceTarget;
        textarea.select();

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
        window.getSelection()?.removeAllRanges();

        return copied;
    }

    flash(button) {
        if (this.restoreTimeout) {
            window.clearTimeout(this.restoreTimeout);
        }

        const original = button.dataset.originalLabel ?? button.innerHTML;
        button.dataset.originalLabel = original;
        button.textContent = this.copiedLabelValue;

        this.restoreTimeout = window.setTimeout(() => {
            button.innerHTML = original;
            this.restoreTimeout = null;
        }, 2000);
    }
}
