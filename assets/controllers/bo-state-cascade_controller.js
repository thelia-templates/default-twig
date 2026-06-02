import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['country', 'state', 'wrapper'];

    connect() {
        this.boundSync = this.sync.bind(this);
        if (this.hasCountryTarget) {
            this.countryTarget.addEventListener('change', this.boundSync);
        }
        this.sync();
    }

    disconnect() {
        if (this.hasCountryTarget) {
            this.countryTarget.removeEventListener('change', this.boundSync);
        }
    }

    sync() {
        if (!this.hasStateTarget || !this.hasCountryTarget) {
            return;
        }

        const country = String(this.countryTarget.value || '');
        let matches = 0;

        Array.from(this.stateTarget.options).forEach((option) => {
            if (option.value === '') {
                return;
            }
            const belongs = option.dataset.countryId === country;
            option.hidden = !belongs;
            option.disabled = !belongs;
            if (belongs) {
                matches += 1;
            }
        });

        const selected = this.stateTarget.selectedOptions[0];
        if (selected && selected.value !== '' && selected.dataset.countryId !== country) {
            this.stateTarget.value = '';
        }

        if (this.hasWrapperTarget) {
            this.wrapperTarget.style.display = matches === 0 ? 'none' : '';
        }
    }
}
