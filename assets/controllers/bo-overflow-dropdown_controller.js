import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.boundOnShow = this.onShow.bind(this);
        this.boundOnHidden = this.onHidden.bind(this);
        this.element.addEventListener('show.bs.dropdown', this.boundOnShow);
        this.element.addEventListener('hidden.bs.dropdown', this.boundOnHidden);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.dropdown', this.boundOnShow);
        this.element.removeEventListener('hidden.bs.dropdown', this.boundOnHidden);
    }

    onShow() {
        this.lifted = [];
        let node = this.element.parentElement;
        while (node && node !== document.body) {
            const style = getComputedStyle(node);
            const clips = [style.overflow, style.overflowX, style.overflowY].some(
                value => value !== 'visible' && value !== '',
            );
            if (clips) {
                this.lifted.push({
                    node,
                    overflow: node.style.overflow,
                    overflowX: node.style.overflowX,
                    overflowY: node.style.overflowY,
                });
                node.style.overflow = 'visible';
                node.style.overflowX = 'visible';
                node.style.overflowY = 'visible';
            }
            node = node.parentElement;
        }
    }

    onHidden() {
        if (!this.lifted) {
            return;
        }
        for (const entry of this.lifted) {
            entry.node.style.overflow = entry.overflow;
            entry.node.style.overflowX = entry.overflowX;
            entry.node.style.overflowY = entry.overflowY;
        }
        this.lifted = null;
    }
}
