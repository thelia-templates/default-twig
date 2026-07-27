import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Warns before leaving an edit screen through a navigation link while a watched
 * form holds unsaved changes. Without it, the prev/next chevrons silently drop
 * everything the user typed.
 *
 * Usage:
 *   <div data-controller="bo-unsaved-changes"
 *        data-bo-unsaved-changes-modal-value="#product-unsaved-changes-modal">
 *       <form data-bo-unsaved-changes-target="form">…</form>
 *       <a href="…" data-bo-unsaved-changes-target="link">next</a>
 *   </div>
 *
 * The modal drives the outcome through three actions: #save submits the dirty
 * form (the server brings the user back to the same screen), #leave follows the
 * link, and dismissing keeps editing.
 */
export default class extends Controller {
    static targets = ['form', 'link'];

    static values = {
        modal: { type: String, default: '' },
    };

    connect() {
        this.dirty = false;
        this.pendingUrl = null;
        this.onFieldChange = () => { this.dirty = true; };
        this.onSubmit = () => { this.dirty = false; };
        this.onLinkClick = (event) => this.guard(event);

        this.formTargets.forEach((form) => {
            form.addEventListener('input', this.onFieldChange);
            form.addEventListener('change', this.onFieldChange);
            form.addEventListener('submit', this.onSubmit);
        });
        this.linkTargets.forEach((link) => link.addEventListener('click', this.onLinkClick));
    }

    disconnect() {
        this.formTargets.forEach((form) => {
            form.removeEventListener('input', this.onFieldChange);
            form.removeEventListener('change', this.onFieldChange);
            form.removeEventListener('submit', this.onSubmit);
        });
        this.linkTargets.forEach((link) => link.removeEventListener('click', this.onLinkClick));
    }

    guard(event) {
        if (!this.dirty) {
            return;
        }

        const url = event.currentTarget.getAttribute('href');
        const modalEl = this.modalElement;
        if (!url || !modalEl) {
            return;
        }

        event.preventDefault();
        this.pendingUrl = url;
        (Modal.getInstance(modalEl) ?? new Modal(modalEl)).show();
    }

    /** Saves the dirty form; the server redirect decides where the user lands. */
    save() {
        const form = this.dirtyForm;
        if (!form) {
            this.leave();

            return;
        }

        this.dirty = false;
        this.hideModal();
        form.requestSubmit();
    }

    /** Follows the link that was intercepted, dropping the pending changes. */
    leave() {
        const url = this.pendingUrl;
        this.dirty = false;
        this.pendingUrl = null;
        this.hideModal();

        if (url) {
            window.location.assign(url);
        }
    }

    get dirtyForm() {
        return this.formTargets[0] ?? null;
    }

    get modalElement() {
        return this.modalValue ? document.querySelector(this.modalValue) : null;
    }

    hideModal() {
        const modalEl = this.modalElement;
        if (modalEl) {
            Modal.getInstance(modalEl)?.hide();
        }
    }
}
