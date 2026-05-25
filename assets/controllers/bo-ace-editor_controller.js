import { Controller } from '@hotwired/stimulus';

/**
 * Upgrades a textarea into an ACE editor for the message HTML/text bodies.
 *
 * Mount on a wrapper that contains the textarea and (optionally) a layout/template
 * select. The controller loads ACE from the configured CDN once per page, swaps
 * the textarea with an editor, and keeps the textarea value in sync so the form
 * submits unchanged.
 *
 * Values (data-bo-ace-editor-*-value):
 *   - `cdn` URL of the ACE bundle (defaults to ace 1.32.6)
 *   - `mode` ACE syntax mode (e.g. `html`, `plain_text`)
 *   - `theme` ACE theme (defaults to `monokai`)
 *   - `height` editor pixel height (defaults to the textarea computed height)
 *
 * Targets:
 *   - `textarea` the textarea to upgrade (required)
 *   - `lock` a sibling `<select>` that flips the editor to read-only when a
 *     layout/template file is picked
 */
export default class extends Controller {
    static values = {
        cdn: { type: String, default: 'https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.min.js' },
        mode: { type: String, default: 'html' },
        theme: { type: String, default: 'monokai' },
        height: { type: Number, default: 0 },
    };

    static targets = ['textarea', 'lock'];

    async connect() {
        if (!this.hasTextareaTarget) {
            return;
        }
        await this.#loadAce();
        this.#mount();
        this.#syncLock();
    }

    disconnect() {
        this.editor?.destroy();
        this.editor = null;
    }

    lock() {
        this.#syncLock();
    }

    #syncLock() {
        if (!this.editor || !this.hasLockTarget) {
            return;
        }
        const locked = this.lockTarget.value !== '';
        this.editor.setReadOnly(locked);
        this.host?.classList.toggle('bo-ace-editor--locked', locked);
    }

    #mount() {
        const textarea = this.textareaTarget;
        this.host = document.createElement('div');
        this.host.className = 'bo-ace-editor';
        const height = this.heightValue > 0
            ? this.heightValue
            : Math.max(textarea.clientHeight, 240);
        this.host.style.minHeight = `${height}px`;
        textarea.style.display = 'none';
        textarea.insertAdjacentElement('beforebegin', this.host);

        // eslint-disable-next-line no-undef
        this.editor = ace.edit(this.host, {
            mode: `ace/mode/${this.modeValue}`,
            theme: `ace/theme/${this.themeValue}`,
            value: textarea.value,
            useWorker: false,
            wrap: true,
        });
        this.editor.session.on('change', () => {
            textarea.value = this.editor.getValue();
        });
    }

    #loadAce() {
        if (window.ace) {
            return Promise.resolve();
        }
        if (!this.constructor._acePromise) {
            this.constructor._acePromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = this.cdnValue;
                script.async = true;
                script.onload = () => resolve();
                script.onerror = () => reject(new Error(`Failed to load ACE from ${this.cdnValue}`));
                document.head.appendChild(script);
            });
        }
        return this.constructor._acePromise;
    }
}
