import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { message: String };
    static outlets = ['modal'];

    connect() {
        this.boundSubmit = this.onSubmit.bind(this);
        this.element.addEventListener('submit', this.boundSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.boundSubmit);
    }

    onSubmit(event) {
        const message = this.messageValue || 'Are you sure? This action cannot be undone.';
        if (!window.confirm(message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }
}
