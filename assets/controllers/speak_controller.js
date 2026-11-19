import { Controller } from '@hotwired/stimulus';

// Reads the finished answer aloud.
export default class extends Controller {
    static targets = ['text', 'audio'];
    static values = { url: String };

    async play(event) {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'X-Synapse-Ui': '1', 'Content-Type': 'application/json' },
                body: JSON.stringify({ text: this.textTarget.textContent }),
            });
            if (!response.ok) {
                button.textContent = (await response.json()).error ?? 'No voice available';
                return;
            }
            this.audioTarget.src = URL.createObjectURL(await response.blob());
            this.audioTarget.hidden = false;
            this.audioTarget.play();
        } finally {
            button.disabled = false;
        }
    }
}
