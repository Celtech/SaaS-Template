import { Controller } from '@hotwired/stimulus';

/** Periodically reloads the turbo-frame it's attached to. Used for the notification bell's unread count. */
export default class extends Controller {
    static values = { interval: { type: Number, default: 30000 } };

    connect() {
        this.timer = setInterval(() => {
            if (typeof this.element.reload === 'function') {
                this.element.reload();
            }
        }, this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }
}
