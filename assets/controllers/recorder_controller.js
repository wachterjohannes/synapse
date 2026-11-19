import { Controller } from '@hotwired/stimulus';

// Records from the microphone, sends the audio for transcription and puts the text into the textarea.
export default class extends Controller {
    static targets = ['text', 'button', 'label', 'status'];
    static values = { url: String };

    recorder = null;
    timer = null;

    // The server takes recordings up to 10 MB. A few minutes of speech is far below that, so stop before.
    static maxSeconds = 240;

    async toggle() {
        if (this.recorder) {
            this.recorder.stop();
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const chunks = [];
            this.recorder = new MediaRecorder(stream);
            this.recorder.ondataavailable = (event) => chunks.push(event.data);
            this.recorder.onstop = () => this.transcribe(stream, new Blob(chunks, { type: this.recorder.mimeType }));
            this.recorder.start();
            this.timer = setTimeout(() => this.recorder?.stop(), this.constructor.maxSeconds * 1000);
            this.labelTarget.textContent = 'Stop';
            this.buttonTarget.dataset.recording = 'true';
            this.buttonTarget.setAttribute('aria-pressed', 'true');
            this.statusTarget.textContent = 'Recording...';
        } catch (error) {
            this.statusTarget.textContent = `No microphone: ${error.message}`;
        }
    }

    async transcribe(stream, blob) {
        clearTimeout(this.timer);
        stream.getTracks().forEach((track) => track.stop());
        this.recorder = null;
        this.labelTarget.textContent = 'Record';
        delete this.buttonTarget.dataset.recording;
        this.buttonTarget.setAttribute('aria-pressed', 'false');
        this.statusTarget.textContent = 'Listening...';

        const form = new FormData();
        form.append('audio', blob, 'speech.webm');
        try {
            const response = await fetch(this.urlValue, { method: 'POST', headers: { 'X-Synapse-Ui': '1' }, body: form });
            // a body the server refuses before the app runs (too big for PHP) is not JSON
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.statusTarget.textContent = data.error ?? 'Could not understand that.';
                return;
            }
            this.textTarget.value = data.text;
            this.statusTarget.textContent = '';
        } catch (error) {
            this.statusTarget.textContent = `Could not send the recording: ${error.message}`;
        }
    }
}
