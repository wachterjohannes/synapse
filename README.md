# Synapse: a second brain with Symfony AI

A tutorial series for the Symfony AI hackathon. We build a second brain: a folder of markdown notes in the Open Knowledge Format that agents write to and read from, that other tools reach over MCP, and that can ask a second brain for help. Everything runs on Symfony AI 1.0.0-BETA1 with Cohere as the model.

The code lives in branches. Each branch builds on the previous one and holds the finished state of its step.

| Branch | What you get | Changes |
|---|---|---|
| [`01-write-agent`](../../tree/01-write-agent) | A capture agent that files raw material in the inbox and writes finished notes into the brain | - |
| [`02-read-agent`](../../tree/02-read-agent) | A read agent that answers from the brain, with RAG in the background | [`01-write-agent...02-read-agent`](../../compare/01-write-agent...02-read-agent) |
| [`03-mcp-server`](../../tree/03-mcp-server) | The brain as an MCP server, protected by an external authorization server | [`02-read-agent...03-mcp-server`](../../compare/02-read-agent...03-mcp-server) |
| [`04-roles`](../../tree/04-roles) | Two roles: admin may write, reader may only query | [`03-mcp-server...04-roles`](../../compare/03-mcp-server...04-roles) |
| [`05-tool-guards`](../../tree/05-tool-guards) | Tool permissions by default: a tool is closed to readers unless it says it is read-only, and a test walks every tool | [`04-roles...05-tool-guards`](../../compare/04-roles...05-tool-guards) |
| [`06-mcp-client`](../../tree/06-mcp-client) | Ask another brain over MCP and rank both answers together with a reranker | [`05-tool-guards...06-mcp-client`](../../compare/05-tool-guards...06-mcp-client) |
| [`07-polish`](../../tree/07-polish) | More than one provider (Hugging Face next to Cohere), speech in and out with ElevenLabs, and a browser page next to the commands | [`06-mcp-client...07-polish`](../../compare/06-mcp-client...07-polish) |

`git diff origin/06-mcp-client origin/07-polish` shows what a step changes.

## Requirements

- PHP 8.4 or newer and Composer
- A `COHERE_API_KEY` from https://dashboard.cohere.com/api-keys. A trial key is enough for this repository, because the brain is small.

Symfony AI is at `1.0.0-BETA1`. The backwards compatibility promise starts with 1.0.0, so the code may need small changes when the final release ships. The project sets `minimum-stability` to `beta` for that reason.

## Start

```bash
git checkout 01-write-agent
composer install
printf 'COHERE_API_KEY=...\n' > .env.local
php bin/console app:capture "Idea: a tutorial about MCP authorization"
```

The agent writes to `notes/`. `notes/inbox/` holds raw material, `notes/brain/` holds finished notes in OKF v0.2. The rules and the shape of each note type are in `config/okf.yaml`, and `src/Brain/OkfValidator.php` enforces the structure before anything is written to `notes/brain/`: path and folder, type, title, a one line description, one to four tags, sources, the heading and sections, and footnotes. `Brain::assertLinksExist()` checks that note links point to notes that exist. Whether a description is a good sentence is left to the model. `notes/inbox/` is raw material and is not validated. Read [the safety section of the first article](https://johanneswachter.dev/blog/synapse-capture-agent-symfony-ai/) before you give an agent write access to anything you care about, and keep `notes/` in Git so every change is a diff you can review.


## The index

From step 02 the notes are searched through an index in `var/brain.sqlite` (`var/brain-<provider>.sqlite` from step 07). It is not updated when a note is written. Run `php bin/console ai:store:clear brain --force` before `php bin/console ai:store:index brain`, because indexing twice stores every passage twice. The live run of `bin/smoke` drops and rebuilds this index.

## Providers, voice and the browser page (step 07)

Not part of the tutorial series. It is the polished product on top of step 06, kept as its own branch.

- `SYNAPSE_PROVIDER=cohere` (default) or `huggingface` in `.env.local` picks the embedding model and the agent models, see `config/providers/`. Every provider has its own index file, so after a switch run `php bin/console ai:store:setup brain` and `php bin/console ai:store:index brain` once. Federation (`app:federate`) still reranks with Cohere. Status: the Hugging Face files compile and are wired like the Cohere ones, but they have not been run against the live API yet, so the model names in `config/providers/huggingface.yaml` are a first guess.
- Cohere embeds queries differently from documents, so `App\Brain\QueryVectorizer` adds the query option of the provider. Other providers pass an empty list.
- Speech goes through ElevenLabs (`ELEVENLABS_API_KEY`). `php bin/console app:ask --audio-in=question.mp3 --speak=answer.mp3` listens to a question and speaks the answer, `app:capture --audio-in=note.mp3` files what is said.
- The browser page is built with Twig, Symfony UX (Stimulus controllers, Twig components, Turbo) and Tailwind through AssetMapper, without Node. Set `UI_PASSWORD` in `.env.local`, start `php -S 127.0.0.1:8000 -t public public/index.php` with `PHP_CLI_SERVER_WORKERS=4` in the environment (an answer streams while the page loads its files) and open http://127.0.0.1:8000 (user `synapse`). The first `composer install` also downloads the Tailwind binary and builds the stylesheet. Questions are answered word by word: the form gets a Turbo Stream with an empty answer card, the card opens an event stream (`turbo-stream-source`) and every word of the agent arrives there as one more Turbo Stream. The microphone fills the text field, "Read it aloud" plays the answer. The page calls the same two agents, so writes still go through the Vault. It is for your own machine: HTTP basic auth, plain password, and every request has to come from the page itself (`Sec-Fetch-Site: same-origin`) or carry an `X-Synapse-Ui` header. It stays off while `UI_PASSWORD` is empty.
- Notes can be looked through at `/ui/notes`: grouped by type, with a status filter, and a page per note that shows its text, its sources and links to the notes it mentions. A person sets the status there (`draft`, `stable`, `verified`, `deprecated`) or on the command line with `php bin/console app:status brain/topics/mcp.md verified`. `php bin/console app:status` lists all notes, `--list=draft` only the drafts. A change touches nothing but the `status:` line and adds a line to `log.md`. The agents cannot do this on purpose: no tool of the agents or of the MCP server sets a status, so "verified" means a person looked at the note, and `tools/okf-check.php` fails if a tool starts to. The search index still holds the old status line until you index again.

## Check a branch

`bin/smoke` runs the checks for the current branch. Without a key it checks the wiring, with a key the agents answer for real. It puts your notes back when it ends. If a run is killed hard, `var/smoke-notes` holds the original notes, and the next run refuses to start until you have moved them back.

The code is MIT licensed, see `LICENSE`.

