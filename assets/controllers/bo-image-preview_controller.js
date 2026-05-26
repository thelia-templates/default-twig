import { Controller } from '@hotwired/stimulus';

/**
 * Live preview for a file <input>: reads the selected image client-side and
 * swaps the sibling <img> src so the user sees what will be uploaded before
 * submitting the form.
 *
 * Targets:
 *   - `input` the file input (required, listens to its change event)
 *   - `image` the <img> to update (required, shown when a file is picked)
 */
export default class extends Controller {
    static targets = ['input', 'image'];

    connect() {
        if (this.hasInputTarget) {
            this.inputTarget.addEventListener('change', this.preview);
        }
    }

    disconnect() {
        if (this.hasInputTarget) {
            this.inputTarget.removeEventListener('change', this.preview);
        }
    }

    preview = (event) => {
        const file = event.target.files?.[0];
        if (!file || !this.hasImageTarget) {
            return;
        }
        const reader = new FileReader();
        reader.onload = (loaded) => {
            this.imageTarget.src = loaded.target.result;
            this.imageTarget.style.display = '';
        };
        reader.readAsDataURL(file);
    };
}
