import { Controller } from '@hotwired/stimulus';

/**
 * Reveals dependent field(s) only while a trigger checkbox is checked — used to
 * show the virtual-document selector only when "virtual product" is ticked.
 * State is synced on connect and on every change of the trigger.
 */
export default class extends Controller {
    static targets = ['trigger', 'field'];

    connect() {
        this.update();
    }

    update() {
        const on = this.hasTriggerTarget && this.triggerTarget.checked;
        this.fieldTargets.forEach((el) => el.classList.toggle('d-none', !on));
    }
}
