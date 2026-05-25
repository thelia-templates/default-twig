import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
        name: { type: String, default: 'bo-print-iframe' },
    };

    print(event) {
        event.preventDefault();

        const iframeName = `${this.nameValue}-${Date.now()}`;
        const iframe = document.createElement('iframe');
        iframe.src = this.urlValue;
        iframe.name = iframeName;
        iframe.id = iframeName;
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';

        iframe.addEventListener('load', () => {
            try {
                const win = iframe.contentWindow;
                win.focus();
                win.print();
            } catch (e) {
                // cross-origin fallback : open in new tab
                window.open(this.urlValue, '_blank');
            }
        });

        document.body.appendChild(iframe);
    }
}
