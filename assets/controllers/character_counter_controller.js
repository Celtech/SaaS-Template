import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'count'];
    static values = { max: Number };

    connect() {
        this.update();
    }

    update() {
        const length = this.inputTarget.value.length;
        this.countTarget.textContent = this.maxValue
            ? `${length} / ${this.maxValue}`
            : String(length);

        if (this.maxValue && length > this.maxValue) {
            this.countTarget.classList.add('text-destructive');
        } else {
            this.countTarget.classList.remove('text-destructive');
        }
    }
}
