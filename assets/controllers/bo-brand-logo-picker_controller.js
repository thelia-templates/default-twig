import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'preview', 'emptyPreview'];

    select(event) {
        const button = event.currentTarget;
        const params = event.params;
        const imageId = params.imageId !== undefined ? String(params.imageId) : '';
        const previewUrl = params.previewUrl !== undefined ? String(params.previewUrl) : '';

        if (this.hasInputTarget) {
            const value = imageId === '0' ? '' : imageId;
            this.inputTarget.value = value;
            this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (this.hasPreviewTarget) {
            if (previewUrl) {
                this.previewTarget.src = previewUrl;
                this.previewTarget.alt = button.getAttribute('title') || '';
                this.previewTarget.classList.remove('d-none');
                if (this.hasEmptyPreviewTarget) {
                    this.emptyPreviewTarget.classList.add('d-none');
                }
            } else {
                this.previewTarget.src = '';
                this.previewTarget.alt = '';
                this.previewTarget.classList.add('d-none');
                if (this.hasEmptyPreviewTarget) {
                    this.emptyPreviewTarget.classList.remove('d-none');
                }
            }
        }

        const buttons = this.element.querySelectorAll('[data-action~="bo-brand-logo-picker#select"]');
        buttons.forEach((candidate) => {
            const isActive = candidate === button;
            candidate.classList.toggle('border-primary', isActive);
            candidate.classList.toggle('border-2', isActive);
            candidate.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }
}
