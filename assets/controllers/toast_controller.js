import { Controller } from '@hotwired/stimulus';

// A short message in the corner. It goes away by itself, or on a click. Optionally it empties a form first:
// a capture that went through has nothing left to send.
export default class extends Controller {
    static values = { reset: String, seconds: { type: Number, default: 4 } };

    connect() {
        if (this.resetValue) {
            document.getElementById(this.resetValue)?.reset();
        }
        this.timer = setTimeout(() => this.element.remove(), this.secondsValue * 1000);
        this.element.addEventListener('click', () => this.element.remove());
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
