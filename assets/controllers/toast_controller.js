import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        duration: { type: Number, default: 4000 },
        closeable: { type: Boolean, default: true },
    };

    connect() {
        if (this.durationValue > 0) {
            this.timer = setTimeout(() => this.dismiss(), this.durationValue);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    dismiss() {
        this.element.classList.add('opacity-0', 'translate-y-2');
        this.element.addEventListener('transitionend', () => this.element.remove(), { once: true });
    }
}
