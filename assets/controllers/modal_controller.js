import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['panel'];
    static values = { open: Boolean };

    connect() {
        this.boundKeydown = this.onKeydown.bind(this);
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundKeydown);
    }

    open() {
        this.openValue = true;
        this.panelTarget.removeAttribute('hidden');
        document.addEventListener('keydown', this.boundKeydown);
        document.body.style.overflow = 'hidden';
        this.panelTarget.focus();
    }

    close() {
        this.openValue = false;
        this.panelTarget.setAttribute('hidden', '');
        document.removeEventListener('keydown', this.boundKeydown);
        document.body.style.overflow = '';
    }

    backdropClick(event) {
        if (event.target === event.currentTarget) {
            this.close();
        }
    }

    onKeydown(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }
}
