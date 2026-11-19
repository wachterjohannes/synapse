# Providers, voice and re-indexing

## Hugging Face instead of Cohere

Put `HUGGINGFACE_API_KEY=...` and `SYNAPSE_PROVIDER=huggingface` into `.env.local`. Every provider has its own container and its own index file (`var/brain-<provider>.sqlite`), so run `ai:store:setup brain` and `ai:store:index brain` once after a switch. The model names are in `config/providers/`. Federation still reranks with Cohere.

I ran indexing, questions and capturing on Hugging Face. The default model is `openai/gpt-oss-120b`, which calls tools reliably. The first default, `meta-llama/Llama-3.3-70B-Instruct`, sometimes printed a tool call as text or filed the example from `describe_format` instead of your text. The validator keeps a note out of `brain/` only when it breaks a rule, so look at what the capture agent wrote.

`list_notes` takes an optional `folder` argument for a reason: Symfony AI 1.0.0-BETA1 sends a tool without arguments without a `parameters` field, and the Hugging Face router refuses that with a 422 (symfony/ai#2846, fixed in #2848 after BETA1).

## Voice

With `ELEVENLABS_API_KEY` set, the microphone in the page fills the text field and "Read it aloud" plays the answer. On the command line:

```bash
php bin/console app:ask --audio-in=question.mp3 --speak=answer.mp3
php bin/console app:capture --audio-in=note.mp3
```

Without the key the page works and the two buttons answer with a message that names the missing key.

## Change the notes, then index again

The index is not updated when a note is written. Run `php bin/console ai:store:clear brain --force` before `php bin/console ai:store:index brain`. Indexing twice stores every passage twice.
