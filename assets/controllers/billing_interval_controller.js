import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['monthContent', 'yearContent', 'toggleKnob', 'toggleButton']
    static values = { interval: { type: String, default: 'month' } }

    connect() {
        this.updateVisibility()
    }

    toggle() {
        this.intervalValue = this.intervalValue === 'month' ? 'year' : 'month'
    }

    intervalValueChanged() {
        this.updateVisibility()
    }

    updateVisibility() {
        const isYear = this.intervalValue === 'year'

        this.monthContentTargets.forEach(el => el.classList.toggle('hidden', isYear))
        this.yearContentTargets.forEach(el => el.classList.toggle('hidden', !isYear))

        if (this.hasToggleKnobTarget) {
            this.toggleKnobTarget.classList.toggle('translate-x-5', isYear)
            this.toggleKnobTarget.classList.toggle('translate-x-0', !isYear)
        }

        if (this.hasToggleButtonTarget) {
            this.toggleButtonTarget.classList.toggle('bg-primary', isYear)
            this.toggleButtonTarget.classList.toggle('bg-muted', !isYear)
        }
    }
}
