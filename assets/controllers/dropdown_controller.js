import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.boundClickOutside = this.onClickOutside.bind(this);
        this.boundKeydown = this.onKeydown.bind(this);
    }

    disconnect() {
        document.removeEventListener('click', this.boundClickOutside);
        document.removeEventListener('keydown', this.boundKeydown);
    }

    toggle() {
        const isOpen = !this.menuTarget.hasAttribute('hidden');
        if (isOpen) {
            this.hide();
        } else {
            this.show();
        }
    }

    show() {
        this.menuTarget.removeAttribute('hidden');
        document.addEventListener('click', this.boundClickOutside);
        document.addEventListener('keydown', this.boundKeydown);
    }

    hide() {
        this.menuTarget.setAttribute('hidden', '');
        document.removeEventListener('click', this.boundClickOutside);
        document.removeEventListener('keydown', this.boundKeydown);
    }

    onClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.hide();
        }
    }

    onKeydown(event) {
        if (event.key === 'Escape') {
            this.hide();
        }
    }
}
