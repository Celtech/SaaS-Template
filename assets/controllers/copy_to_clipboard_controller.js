import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'button'];
    static values = { successText: { type: String, default: 'Copied!' } };

    copy() {
        const text = this.sourceTarget.value ?? this.sourceTarget.textContent;
        navigator.clipboard.writeText(text.trim()).then(() => {
            const original = this.buttonTarget.textContent;
            this.buttonTarget.textContent = this.successTextValue;
            setTimeout(() => { this.buttonTarget.textContent = original; }, 2000);
        });
    }
}
