import { Controller } from '@hotwired/stimulus';

/**
 * Drives the translation editor: scope navigation, editing modes, bulk copy
 * and unsaved-changes guard.
 *
 * Values:
 *   - `baseUrl` GET endpoint hit when the scope filter changes.
 *   - `userModeCookie` cookie name persisting the chosen mode (defaults to
 *     `translation_userMode`).
 *
 * Targets:
 *   - `customRow` / `globalRow` the sub-inputs hidden in user mode (the custom
 *     ★ and global » rows).
 *   - `defaultInput` the primary translation input which becomes read-only in
 *     user mode (so contributors stay on the safe override path).
 *   - `userModeButton` / `developerModeButton` the toggle buttons.
 *   - `developerOnly` blocks shown only in developer mode (typically the
 *     Crowdin recommendation).
 *
 * Actions:
 *   - `selectUserMode` / `selectDeveloperMode` on the mode buttons.
 *   - `copyAll` on the "copy all translations" link - fills every empty source
 *     translation with the original text.
 *   - `copyOne` on a row's ` button - copies that row's source text into its
 *     translation input.
 *   - `trackChange` on translation inputs to flip an internal dirty flag.
 *   - `confirmUnsaved` on form submit; prompts the user when dirty.
 */
export default class extends Controller {
    static values = {
        baseUrl: String,
        userModeCookie: { type: String, default: 'translation_userMode' },
    };

    static targets = ['customRow', 'globalRow', 'defaultInput', 'userModeButton', 'developerModeButton', 'developerOnly'];

    connect() {
        this.dirty = false;
        this.#applyMode(this.#readMode());
    }

    changeItem() {
        const form = this.#form();
        if (!form) {
            return;
        }
        const itemNameInput = form.querySelector('input[name="item_name"], select[name="item_name"]');
        if (itemNameInput) {
            itemNameInput.value = '';
        }
        const partInput = form.querySelector('input[name="module_part"], select[name="module_part"]');
        if (partInput) {
            partInput.value = '';
        }
        this.#submitNavigation(form);
    }

    submitForm() {
        const form = this.#form();
        if (form) {
            this.#submitNavigation(form);
        }
    }

    selectUserMode(event) {
        event?.preventDefault();
        this.#applyMode(true);
    }

    selectDeveloperMode(event) {
        event?.preventDefault();
        this.#applyMode(false);
    }

    copyAll(event) {
        event?.preventDefault();
        this.defaultInputTargets.forEach((input) => {
            if (input.value !== '') {
                return;
            }
            const sourceField = input.closest('tr')?.querySelector('input[name="text[]"]');
            if (sourceField?.value) {
                input.value = sourceField.value;
                this.dirty = true;
            }
        });
    }

    copyOne(event) {
        event?.preventDefault();
        const row = event.currentTarget.closest('tr');
        const source = row?.querySelector('input[name="text[]"]');
        const target = row?.querySelector('input[name="translation[]"]');
        if (source && target) {
            target.value = source.value;
            this.dirty = true;
        }
    }

    trackChange() {
        this.dirty = true;
    }

    confirmUnsaved(event) {
        if (!this.dirty) {
            return;
        }
        // Save buttons (save_mode=stay|close) are intentional submits.
        if (event.submitter?.name === 'save_mode') {
            return;
        }
        const message = this.element.dataset.boTranslationsUnsavedMessage
            ?? 'Some of your translations are not saved. Continue anyway?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    }

    #form() {
        return this.element.querySelector('form');
    }

    #submitNavigation(form) {
        // Navigation submits should not trigger the unsaved-changes guard.
        this.dirty = false;
        // Navigate with the scope params only: GET-submitting the whole form
        // would serialise every translation input into the URL and overflow the
        // server's request-line limit (414) on large catalogs.
        const base = this.hasBaseUrlValue ? this.baseUrlValue : form.action;
        const params = new URLSearchParams();
        ['item_to_translate', 'item_name', 'module_part', 'edit_language_id'].forEach((name) => {
            const field = form.querySelector(`[name="${name}"]`);
            if (field && field.value !== '') {
                params.set(name, field.value);
            }
        });
        const query = params.toString();
        window.location.assign(query ? `${base}?${query}` : base);
    }

    #readMode() {
        const cookie = this.#getCookie(this.userModeCookieValue);
        return cookie === null ? true : cookie === '1';
    }

    #applyMode(userMode) {
        const showCustomGlobal = userMode;
        this.customRowTargets.forEach((row) => { row.hidden = !showCustomGlobal; });
        this.globalRowTargets.forEach((row) => { row.hidden = !showCustomGlobal; });
        this.defaultInputTargets.forEach((input) => { input.readOnly = userMode; });
        this.developerOnlyTargets.forEach((node) => { node.hidden = userMode; });
        if (this.hasUserModeButtonTarget) {
            this.userModeButtonTarget.classList.toggle('active', userMode);
            this.userModeButtonTarget.setAttribute('aria-pressed', userMode ? 'true' : 'false');
        }
        if (this.hasDeveloperModeButtonTarget) {
            this.developerModeButtonTarget.classList.toggle('active', !userMode);
            this.developerModeButtonTarget.setAttribute('aria-pressed', userMode ? 'false' : 'true');
        }
        this.#setCookie(this.userModeCookieValue, userMode ? '1' : '0');
    }

    #getCookie(name) {
        const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
        return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
    }

    #setCookie(name, value) {
        const oneYear = 60 * 60 * 24 * 365;
        document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${oneYear}; SameSite=Lax`;
    }
}
