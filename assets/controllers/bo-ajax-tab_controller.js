import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.boundOnShown = this.onShown.bind(this);
        this.element.addEventListener('shown.bs.tab', this.boundOnShown);
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.tab', this.boundOnShown);
    }

    async onShown(event) {
        const button = event.target;
        const href = button?.getAttribute('data-href');
        if (!href || button.dataset.loaded === '1') {
            return;
        }

        const targetSelector = button.getAttribute('data-bs-target');
        const pane = targetSelector ? this.element.querySelector(targetSelector) : null;
        if (!pane) {
            return;
        }

        button.dataset.loaded = '1';
        try {
            const response = await fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            pane.innerHTML = await response.text();
        } catch (error) {
            button.dataset.loaded = '';
            pane.innerHTML = `<div class="alert alert-danger">${button.dataset.errorMessage || 'Unable to load this tab.'}</div>`;
        }
    }
}
