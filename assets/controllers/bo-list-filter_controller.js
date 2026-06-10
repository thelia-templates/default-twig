import { Controller } from '@hotwired/stimulus';

/**
 * Client-side filtering across stacked DataTable sections.
 * Rows must carry `data-search` and `data-active`; sections carry `data-group`.
 * Drag-and-drop reorder is suspended while a filter is active: a position
 * computed against a partially hidden list would be wrong once persisted.
 */
export default class extends Controller {
    static targets = ['search', 'group', 'state', 'section', 'empty'];

    apply() {
        const search = this.searchTarget.value.trim().toLowerCase();
        const group = this.groupTarget.value;
        const state = this.stateTargets.find((radio) => radio.checked)?.value ?? '';
        const filtering = search !== '' || group !== '' || state !== '';
        let anyVisible = false;

        this.sectionTargets.forEach((section) => {
            const groupMatches = group === '' || section.dataset.group === group;
            let visibleRows = 0;

            section.querySelectorAll('tr[data-row-id]').forEach((row) => {
                const matches = groupMatches
                    && (search === '' || (row.dataset.search || '').includes(search))
                    && (state === '' || row.dataset.active === state);
                row.classList.toggle('d-none', !matches);
                row.draggable = !filtering;
                if (matches) {
                    visibleRows += 1;
                }
            });

            section.classList.toggle('d-none', !groupMatches || (filtering && visibleRows === 0));
            section.classList.toggle('bo-list-filter--filtering', filtering);
            anyVisible = anyVisible || visibleRows > 0;
        });

        this.emptyTarget.classList.toggle('d-none', !filtering || anyVisible);
    }
}
