import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['body'];

    static values = {
        triggerAttribute: { type: String, default: 'data-fetch-url' },
        loadingHtml: { type: String, default: '' },
        errorHtml: { type: String, default: '' },
    };

    connect() {
        this.boundOnShow = this.onShow.bind(this);
        this.element.addEventListener('show.bs.modal', this.boundOnShow);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this.boundOnShow);
    }

    async onShow(event) {
        const trigger = event.relatedTarget;
        if (!trigger || !this.hasBodyTarget) {
            return;
        }

        const url = trigger.getAttribute(this.triggerAttributeValue);
        if (!url) {
            return;
        }

        this.bodyTarget.innerHTML = this.loadingHtmlValue
            || '<div class="text-center text-body-secondary py-4"><div class="spinner-border" role="status" aria-hidden="true"></div></div>';

        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.bodyTarget.innerHTML = await response.text();
        } catch (error) {
            this.bodyTarget.innerHTML = this.errorHtmlValue
                || `<div class="alert alert-danger mb-0">${error.message}</div>`;
        }
    }
}
