import { Controller } from '@hotwired/stimulus';

/**
 * Drives the "Module hooks" page:
 *  - client-side filtering of hook cards (status / module / type / name / hide empty),
 *  - prefilling the create and delete modals,
 *  - auto-submitting the inline activation switch,
 *  - cascading AJAX selects (module → class → method) in the create modal.
 */
export default class extends Controller {
    static targets = [
        'hookCard', 'emptyMessage',
        'filterStatus', 'filterModule', 'filterType', 'filterName', 'filterEmpty',
        'createModule', 'createHook', 'createClassname', 'createMethod', 'deleteInput',
    ];
    static values = { classnamesUrl: String, methodsUrl: String };

    connect() {
        this.applyFilters();
    }

    applyFilters() {
        const status = this.hasFilterStatusTarget ? this.filterStatusTarget.value : '';
        const moduleId = this.hasFilterModuleTarget ? this.filterModuleTarget.value : '';
        const type = this.hasFilterTypeTarget ? this.filterTypeTarget.value : '';
        const name = this.hasFilterNameTarget ? this.filterNameTarget.value.trim().toLowerCase() : '';
        const hideEmpty = this.hasFilterEmptyTarget ? this.filterEmptyTarget.checked : false;

        let visibleCards = 0;

        for (const card of this.hookCardTargets) {
            const matchesType = type === '' || card.dataset.hookType === type;
            const matchesName = name === '' || (card.dataset.hookName || '').includes(name);

            let visibleRows = 0;
            for (const row of card.querySelectorAll('.module-hook-row')) {
                const matchesStatus = status === '' || row.dataset.active === status;
                const matchesModule = moduleId === '' || row.dataset.moduleId === moduleId;
                const rowVisible = matchesStatus && matchesModule;
                row.classList.toggle('d-none', !rowVisible);
                if (rowVisible) {
                    visibleRows += 1;
                }
            }

            // A status/module filter implicitly hides hooks with no matching row.
            const rowFilterActive = status !== '' || moduleId !== '';
            const emptyAfterFilter = visibleRows === 0;
            const hiddenByEmpty = (hideEmpty || rowFilterActive) && emptyAfterFilter;

            const cardVisible = matchesType && matchesName && !hiddenByEmpty;
            card.classList.toggle('d-none', !cardVisible);
            if (cardVisible) {
                visibleCards += 1;
            }
        }

        if (this.hasEmptyMessageTarget) {
            this.emptyMessageTarget.classList.toggle('d-none', visibleCards > 0);
        }
    }

    prefillHook(event) {
        const hookId = event.currentTarget.dataset.hookId;
        if (hookId && this.hasCreateHookTarget) {
            this.createHookTarget.value = hookId;
        }
    }

    prefillDelete(event) {
        const id = event.currentTarget.dataset.moduleHookId;
        if (id && this.hasDeleteInputTarget) {
            this.deleteInputTarget.value = id;
        }
    }

    submitToggle(event) {
        const form = event.currentTarget.closest('form');
        if (form) {
            form.submit();
        }
    }

    async loadClassnames() {
        const moduleId = this.createModuleTarget.value;
        this.resetSelect(this.createClassnameTarget, this.classnamePlaceholder());
        this.resetSelect(this.createMethodTarget, this.methodPlaceholder());

        if (!moduleId) {
            return;
        }

        const url = this.classnamesUrlValue.replace(/\/0(\/|$)/, `/${moduleId}$1`);
        const data = await this.fetchJson(url);
        const classnames = data && Array.isArray(data.classnames) ? data.classnames : [];
        this.fillSelect(this.createClassnameTarget, classnames, this.classnamePlaceholder());
        this.createClassnameTarget.disabled = classnames.length === 0;
    }

    async loadMethods() {
        const moduleId = this.createModuleTarget.value;
        const className = this.createClassnameTarget.value;
        this.resetSelect(this.createMethodTarget, this.methodPlaceholder());

        if (!moduleId || !className) {
            return;
        }

        const url = this.methodsUrlValue
            .replace(/\/0\//, `/${moduleId}/`)
            .replace('__CLASS__', encodeURIComponent(className));
        const data = await this.fetchJson(url);
        const methods = data && Array.isArray(data.methods) ? data.methods : [];
        this.fillSelect(this.createMethodTarget, methods, this.methodPlaceholder());
        this.createMethodTarget.disabled = methods.length === 0;
    }

    async fetchJson(url) {
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                return null;
            }
            return await response.json();
        } catch {
            return null;
        }
    }

    fillSelect(select, values, placeholder) {
        this.resetSelect(select, placeholder);
        for (const value of values) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            select.appendChild(option);
        }
    }

    resetSelect(select, placeholder) {
        select.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;
        select.appendChild(option);
        select.disabled = true;
    }

    classnamePlaceholder() {
        return this.createClassnameTarget.dataset.placeholder || '-';
    }

    methodPlaceholder() {
        return this.createMethodTarget.dataset.placeholder || '-';
    }
}
