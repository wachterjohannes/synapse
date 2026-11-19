import { Controller } from '@hotwired/stimulus';

// Copies the plain text of an answer. The button says "Copied" for a moment.
export default class extends Controller {
    static targets = ['plain', 'label'];

    timer = null;

    async copy() {
        try {
            await navigator.clipboard.writeText(this.plainTarget.textContent);
            this.say('Copied');
        } catch {
            // the clipboard needs a secure page and a visitor who allowed it
            this.say('Could not copy');
        }
    }

    say(text) {
        clearTimeout(this.timer);
        this.labelTarget.textContent = text;
        this.timer = setTimeout(() => (this.labelTarget.textContent = 'Copy'), 1800);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
