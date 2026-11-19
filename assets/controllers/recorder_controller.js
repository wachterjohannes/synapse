import { Controller } from '@hotwired/stimulus';

// Records from the microphone, sends the audio for transcription and puts the text into the textarea.
export default class extends Controller {
    static targets = ['text', 'button', 'status'];
    static values = { url: String };

    recorder = null;

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
            this.buttonTarget.textContent = 'Stop';
            this.buttonTarget.dataset.recording = 'true';
        } catch (error) {
            this.statusTarget.textContent = `No microphone: ${error.message}`;
        }
    }

    async transcribe(stream, blob) {
        stream.getTracks().forEach((track) => track.stop());
        this.recorder = null;
        this.buttonTarget.textContent = 'Record';
        delete this.buttonTarget.dataset.recording;
        this.statusTarget.textContent = 'Listening...';

        const form = new FormData();
        form.append('audio', blob, 'speech.webm');
        const response = await fetch(this.urlValue, { method: 'POST', headers: { 'X-Synapse-Ui': '1' }, body: form });
        const data = await response.json();
        if (!response.ok) {
            this.statusTarget.textContent = data.error ?? 'Could not understand that.';
            return;
        }
        this.textTarget.value = data.text;
        this.statusTarget.textContent = '';
    }
}
