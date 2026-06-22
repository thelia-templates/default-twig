import { Controller } from '@hotwired/stimulus';

/**
 * Client-side enhancement of the single, server-rendered module list.
 * Progressive enhancement: every row is already in the DOM with its
 * data-* attributes (type, active, code, name, version, search); this
 * controller only filters, sorts, highlights and drives bulk selection.
 *
 * No data leaves the page for filtering/sorting. Bulk activate/deactivate
 * fans out over the existing per-module toggle endpoint (no batch endpoint).
 */
export default class extends Controller {
    static targets = [
        'search', 'sort', 'type', 'status', 'list', 'row', 'empty',
        'typeCount', 'summaryTotal', 'summaryActive', 'summaryInactive',
        'checkbox', 'bulkBar', 'bulkCount',
    ];

    static values = {
        activateConfirm: String,
        deactivateConfirm: String,
    };

    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
        this.apply();
        this.refreshSelection();
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    /** "/" focuses the search field, unless the user is already typing somewhere. */
    onKeydown(event) {
        if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }
        const tag = document.activeElement?.tagName;
        const editing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable;
        if (editing) {
            return;
        }
        event.preventDefault();
        this.searchTarget.focus();
    }

    apply() {
        const search = this.searchTarget.value.trim().toLowerCase();
        const type = this.checkedValue(this.typeTargets);
        const status = this.checkedValue(this.statusTargets);

        const counts = { '': 0, delivery: 0, payment: 0, classic: 0, other: 0 };
        let visible = 0;

        this.rowTargets.forEach((row) => {
            const matchesSearch = search === '' || (row.dataset.search || '').includes(search);
            const matchesStatus = status === '' || row.dataset.active === status;
            // Type segment counters reflect the search + status filters but ignore the
            // type filter itself, so each segment shows what selecting it would yield.
            if (matchesSearch && matchesStatus) {
                counts[''] += 1;
                const slug = row.dataset.type in counts ? row.dataset.type : 'other';
                counts[slug] += 1;
            }

            const matchesType = type === '' || row.dataset.type === type;
            const shown = matchesSearch && matchesStatus && matchesType;
            row.classList.toggle('d-none', !shown);
            if (shown) {
                visible += 1;
            }

            this.highlight(row, search);
        });

        this.updateTypeCounts(counts);
        this.sortRows();
        this.emptyTarget.classList.toggle('d-none', visible > 0);
        this.listTarget.classList.toggle('d-none', visible === 0);
    }

    sortRows() {
        const mode = this.sortTarget.value;
        const rows = [...this.rowTargets];

        rows.sort((a, b) => {
            if (mode === 'recent') {
                return Number(b.dataset.id) - Number(a.dataset.id);
            }
            if (mode === 'status') {
                const byStatus = Number(b.dataset.active) - Number(a.dataset.active);
                return byStatus !== 0 ? byStatus : this.byName(a, b);
            }
            return this.byName(a, b);
        });

        rows.forEach((row) => this.listTarget.appendChild(row));
    }

    byName(a, b) {
        return (a.dataset.name || '').localeCompare(b.dataset.name || '', undefined, { sensitivity: 'base' });
    }

    updateTypeCounts(counts) {
        this.typeCountTargets.forEach((node) => {
            const slug = node.dataset.type || '';
            node.textContent = counts[slug] ?? 0;
        });
    }

    /** Wrap occurrences of the search term in name / code / description with <mark>. */
    highlight(row, search) {
        row.querySelectorAll('[data-bo-modules-list-target="name"], [data-bo-modules-list-target="code"], [data-bo-modules-list-target="desc"]')
            .forEach((node) => {
                if (node.dataset.orig === undefined) {
                    node.dataset.orig = node.textContent;
                }
                const text = node.dataset.orig;
                if (search === '') {
                    node.textContent = text;
                    return;
                }
                const index = text.toLowerCase().indexOf(search);
                if (index === -1) {
                    node.textContent = text;
                    return;
                }
                node.textContent = '';
                node.append(
                    document.createTextNode(text.slice(0, index)),
                    this.mark(text.slice(index, index + search.length)),
                    document.createTextNode(text.slice(index + search.length)),
                );
            });
    }

    mark(text) {
        const el = document.createElement('mark');
        el.className = 'bo-modules__hl';
        el.textContent = text;
        return el;
    }

    // ---------- Bulk selection ----------

    onSelect(event) {
        event.target.closest('.bo-module-row')?.classList.toggle('bo-module-row--selected', event.target.checked);
        this.refreshSelection();
    }

    refreshSelection() {
        const selected = this.selectedCheckboxes();
        this.bulkCountTarget.textContent = String(selected.length);
        this.bulkBarTarget.classList.toggle('d-none', selected.length === 0);
    }

    clearSelection() {
        this.checkboxTargets.forEach((box) => {
            box.checked = false;
            box.closest('.bo-module-row')?.classList.remove('bo-module-row--selected');
        });
        this.refreshSelection();
    }

    bulkActivate() {
        this.runBulk('1', this.activateConfirmValue);
    }

    bulkDeactivate() {
        this.runBulk('0', this.deactivateConfirmValue);
    }

    /**
     * Toggle every selected row currently in the opposite state. The per-module
     * endpoint flips the activation, so we only call it for rows that need it.
     */
    runBulk(targetActive, confirmTemplate) {
        const wanted = targetActive === '1';
        const toFlip = this.selectedCheckboxes().filter((box) => {
            const row = box.closest('.bo-module-row');
            const url = box.dataset.toggleUrl;
            return url && (row.dataset.active === '1') !== wanted;
        });

        if (toFlip.length === 0) {
            this.clearSelection();
            return;
        }

        const message = (confirmTemplate || '').replace('%count%', String(toFlip.length));
        if (message && !window.confirm(message)) {
            return;
        }

        Promise.all(toFlip.map((box) => fetch(box.dataset.toggleUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }))).finally(() => window.location.reload());
    }

    selectedCheckboxes() {
        return this.checkboxTargets.filter((box) => box.checked);
    }

    checkedValue(radios) {
        return radios.find((radio) => radio.checked)?.value ?? '';
    }
}
