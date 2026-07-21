import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['label', 'navItem', 'chevron'];
    static values = { collapsed: Boolean };

    collapsedValueChanged(collapsed) {
        this.element.classList.toggle('w-[72px]', collapsed);
        this.element.classList.toggle('w-[236px]', !collapsed);
        this.labelTargets.forEach((el) => el.classList.toggle('hidden', collapsed));
        this.navItemTargets.forEach((el) => el.classList.toggle('justify-center', collapsed));
        if (this.hasChevronTarget) {
            this.chevronTarget.classList.toggle('rotate-180', collapsed);
        }
        document.cookie = `sidebar_collapsed=${collapsed ? '1' : '0'}; path=/; max-age=31536000; SameSite=Lax`;
    }

    toggle() {
        this.collapsedValue = !this.collapsedValue;
    }
}
