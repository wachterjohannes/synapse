# Synapse: a second brain with Symfony AI

Synapse is a folder of markdown notes that agents write to and read from, that other tools reach over MCP, and that can ask a second brain for help. It runs on Symfony AI 1.0.0-BETA1 and the PHP MCP SDK. I built it for the Symfony AI & MCP hackathon.

What you can do with it:

- **Capture.** Hand a piece of text to an agent. A rough thought lands in `notes/inbox/`. A clear piece of knowledge becomes a structured note in `notes/brain/`, in the Open Knowledge Format (OKF v0.2).
- **Ask.** A second agent answers from the notes with RAG and names the file it used. A question the notes cannot answer gets "not found" and no guess.
- **Browse.** A page in the browser shows the notes by type and status, answers questions word by word, and takes voice in and out. A person sets the status (`draft`, `stable`, `verified`, `deprecated`). No tool of the agents can.
- **MCP.** The brain is an MCP server behind an external authorization server. A token with `brain:read` can search and read. A token with `brain:write` can also write. A new tool is closed to readers unless it says it is read-only.
- **Federate.** `app:federate` asks a second brain over MCP and ranks both answers together with a reranker.
- **Providers.** Cohere and Hugging Face, switched with one variable.

The code is also the base of a [tutorial series](https://github.com/wachterjohannes/synapse-tutorial), one branch per step. This repository is the finished state.

## Run it in five minutes

You need PHP 8.4 or newer and Composer. You also need one key: a `COHERE_API_KEY` ([dashboard.cohere.com](https://dashboard.cohere.com/api-keys), a trial key is enough) or a `HUGGINGFACE_API_KEY`. Voice needs an `ELEVENLABS_API_KEY` and is optional.

```bash
git clone https://github.com/wachterjohannes/synapse synapse
cd synapse
composer install
printf 'COHERE_API_KEY=...\nUI_PASSWORD=pick-something\n' > .env.local
php bin/console ai:store:setup brain
php bin/console ai:store:index brain
PHP_CLI_SERVER_WORKERS=4 php -d variables_order=EGPCS -S 127.0.0.1:8080 -t public public/index.php
```

Open http://127.0.0.1:8080 and sign in as `synapse` with your `UI_PASSWORD` on the login page. While `UI_PASSWORD` is empty the page stays closed. The workers are there because an answer streams while the page loads its files.

`ai:store:setup` and `ai:store:index` build the search index in `var/`. The repository ships a handful of example notes in `notes/brain/`, so there is something to ask right away, for example "What breaks when I upgrade to Symfony AI 1.0 beta?". `composer install` also builds the stylesheet. You need no Node.

On the command line:

```bash
php bin/console app:ask "What breaks when I upgrade to Symfony AI 1.0 beta?"
php bin/console app:capture "Decision: we use SQLite for the vector store, because it needs no service."
php bin/console app:status               # every note with its status
```

A note you capture, and a status you change, is indexed right away. If the index gets out of step, see [Providers](docs/providers.md).

## More

- [Providers, voice and re-indexing](docs/providers.md): Hugging Face instead of Cohere, ElevenLabs, updating the index.
- [The MCP server](docs/mcp.md): tokens, roles, what the endpoint answers.
- [Keycloak](docs/keycloak.md): a real authorization server.
- [Ask a second brain](docs/federation.md): `app:federate`.
- [What goes where, and what it costs](docs/security-and-costs.md): read this before you use notes you care about.
- [Code map and checks](docs/development.md): where things live, `bin/smoke`.

Symfony AI is at `1.0.0-BETA1`. The backwards compatibility promise starts with 1.0.0, so the code may need small changes when the final release ships.

## License

MIT, see `LICENSE`.
