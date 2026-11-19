import { Controller } from '@hotwired/stimulus';

// Ctrl or Cmd with Enter sends the form, from inside its textarea. requestSubmit() runs the checks of the form
// and lets Turbo send it, so it is the same as a click on the button.
export default class extends Controller {
    submit(event) {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.isComposing) {
            event.preventDefault();
            this.element.requestSubmit();
        }
    }
}
