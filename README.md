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

Open http://127.0.0.1:8080 and sign in as `synapse` with your `UI_PASSWORD`. While `UI_PASSWORD` is empty the page stays closed and every request gets a `401`. The workers are there because an answer streams while the page loads its files.

`ai:store:setup` and `ai:store:index` build the search index in `var/`. The repository ships a handful of example notes in `notes/brain/`, so there is something to ask right away, for example "What breaks when I upgrade to Symfony AI 1.0 beta?". `composer install` also builds the stylesheet. You need no Node.

On the command line:

```bash
php bin/console app:ask "What breaks when I upgrade to Symfony AI 1.0 beta?"
php bin/console app:capture "Decision: we use SQLite for the vector store, because it needs no service."
php bin/console app:status               # every note with its status
```

A captured note is not searchable until you index again, see below.

### Hugging Face instead of Cohere

Put `HUGGINGFACE_API_KEY=...` and `SYNAPSE_PROVIDER=huggingface` into `.env.local`. Every provider has its own container and its own index file (`var/brain-<provider>.sqlite`), so run `ai:store:setup brain` and `ai:store:index brain` once after a switch. The model names are in `config/providers/`. Federation still reranks with Cohere.

I ran indexing, questions and capturing on Hugging Face. The default model is `openai/gpt-oss-120b`, which calls tools reliably. The first default, `meta-llama/Llama-3.3-70B-Instruct`, sometimes printed a tool call as text or filed the example from `describe_format` instead of your text. The validator keeps a note out of `brain/` only when it breaks a rule, so look at what the capture agent wrote.

`list_notes` takes an optional `folder` argument for a reason: Symfony AI 1.0.0-BETA1 sends a tool without arguments without a `parameters` field, and the Hugging Face router refuses that with a 422 (symfony/ai#2846, fixed in #2848 after BETA1).

### Voice

With `ELEVENLABS_API_KEY` set, the microphone in the page fills the text field and "Read it aloud" plays the answer. On the command line:

```bash
php bin/console app:ask --audio-in=question.mp3 --speak=answer.mp3
php bin/console app:capture --audio-in=note.mp3
```

Without the key the page works and the two buttons answer with a message that names the missing key.

### Change the notes, then index again

The index is not updated when a note is written. Run `php bin/console ai:store:clear brain --force` before `php bin/console ai:store:index brain`. Indexing twice stores every passage twice.

## The MCP server

The brain answers MCP on `/mcp`, on the same server as the page. It checks tokens and never hands them out. An authorization server does that. `tools/fake-as.php` stands in for it on your machine:

```bash
php -S 127.0.0.1:8081 tools/fake-as.php &
TOKEN=$(curl -s -X POST http://127.0.0.1:8081/token -d client_id=admin -d client_secret=admin-secret -d resource=http://127.0.0.1:8080/mcp | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p')
```

Point an MCP client at `http://127.0.0.1:8080/mcp` with the header `Authorization: Bearer $TOKEN`. The client `reader` (secret `reader-secret`) gets `brain:read`, the client `admin` gets both scopes. A token only works for the address it was asked for. Use exactly `http://127.0.0.1:8080/mcp`. A token for `localhost` is refused.

The fake server is a stand-in. It has fixed secrets, no consent screen and no HTTPS, and it has no authorization endpoint, so a client that wants to log in by itself finds nothing to talk to. Get the token with `curl` as above. A third client, `guest`, gets a valid token without any brain scope, so you can see the 403 below.

What the endpoint answers when it does not let a request in:

| Request | Answer |
|---|---|
| no token | `401`, `WWW-Authenticate: Bearer resource_metadata="...", scope="brain:read"` |
| token that is not active, or meant for another address | `401` with `error="invalid_token"` |
| valid token without `brain:read` or `brain:write` | `403` with `error="insufficient_scope"` |
| authorization server cannot be reached | `503` with `Retry-After`. The token may be fine, and the client should not log in again |

The metadata is served at `/.well-known/oauth-protected-resource` and, as RFC 9728 asks for a resource with a path, at `/.well-known/oauth-protected-resource/mcp`. There is no CORS: a client that runs in a browser tab and calls the endpoint directly is refused.

### A real authorization server: Keycloak

The brain needs two things from the server: token introspection (RFC 7662) and an `aud` claim that contains the address of the brain. The access tokens may be JWTs, because the brain asks the server instead of reading them. Point `OAUTH_INTROSPECTION_URL`, `OAUTH_ISSUER`, `OAUTH_RESOURCE_CLIENT_ID` and `OAUTH_RESOURCE_CLIENT_SECRET` to it, and set `OAUTH_AUDIENCE` to the address of your brain.

`tools/keycloak-realm.json` is a realm for Keycloak 26 that I ran the brain against, with curl and without a browser: tokens for `reader` and `admin`, introspection, an expired token, a revoked token, a stopped server, and the authorization code flow with PKCE and dynamic client registration.

```bash
docker run -d --name synapse-kc -p 8180:8080 \
  -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e KC_BOOTSTRAP_ADMIN_PASSWORD=admin \
  -v "$PWD/tools/keycloak-realm.json:/opt/keycloak/data/import/synapse.json:ro" \
  quay.io/keycloak/keycloak:26.8 start-dev --import-realm
export OAUTH_ISSUER=http://127.0.0.1:8180/realms/synapse
export OAUTH_INTROSPECTION_URL=$OAUTH_ISSUER/protocol/openid-connect/token/introspect
export OAUTH_RESOURCE_CLIENT_ID=brain-server OAUTH_RESOURCE_CLIENT_SECRET=brain-server-secret
PHP_CLI_SERVER_WORKERS=4 php -d variables_order=EGPCS -S 127.0.0.1:8080 -t public public/index.php
curl -s -d grant_type=client_credentials -d client_id=admin -d client_secret=admin-secret $OAUTH_ISSUER/protocol/openid-connect/token
```

Two things work differently from the fake server:

- **Keycloak ignores the `resource` parameter (RFC 8707).** The audience comes from the client scope `brain-audience`, which adds `http://127.0.0.1:8080/mcp` to every token that has the scope. Another address needs another scope. Change the address in the realm file if your brain runs elsewhere. A second brain needs its own scope that a client asks for with `scope=`.
- **Introspection answers `active: false` for a token that does not name the calling client in `aud`.** The same scope adds a second audience, `brain-server`. The brain only checks that its own address is among the values.

The MCP specification wants an MCP client to find the authorization server by itself. With this realm that works as far as I tested it without a browser: the metadata names Keycloak, Keycloak publishes the authorization server metadata with PKCE and a registration endpoint, and a client registered on the fly got a token that the brain accepted. Claude Code as a client lists the tools, searches, reads, is refused as a reader and shows "Needs authentication" for the address without a token. It worked with a token from the fake server and with one from Keycloak. I did not click through the browser login of a client, and I did not try the MCP Inspector.

### Ask a second brain

`examples/friend-notes` is a second brain. Start it with its own notes, index and audience, and ask both:

```bash
export BRAIN_DIR=$PWD/examples/friend-notes BRAIN_DB=$PWD/var/friend.sqlite
php bin/console ai:store:setup brain && php bin/console ai:store:index brain
OAUTH_AUDIENCE=http://127.0.0.1:38512/mcp APP_CACHE_DIR=$PWD/var/cache-friend php -d variables_order=EGPCS -S 127.0.0.1:38512 -t public public/index.php &
unset BRAIN_DIR BRAIN_DB
FRIEND_BRAIN_TOKEN=$(curl -s -X POST http://127.0.0.1:8081/token -d client_id=reader -d client_secret=reader-secret -d resource=http://127.0.0.1:38512/mcp | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p') \
  php bin/console app:federate "How do I protect an MCP server?"
```

The token is meant for the friend's brain and for nothing else. The friend's brain refuses a token for yours.

## What goes where, and what it costs

Read this before you point it at notes you care about.

- **Notes leave your machine.** `ai:store:index` sends every note to the embedding model of the provider. Each question sends the passages it found, and `capture` sends the text you hand over. With `app:federate`, passages of the other brain go to Cohere for reranking. With voice, the answer text goes to ElevenLabs and the recording goes to ElevenLabs for transcription.
- **It costs money.** Every question, capture, read-aloud and recording is a paid call. A trial key covers a demo. There is no rate limit. The page is for your own machine.
- **A model can be slow.** An answer with tool calls took 25 to 35 seconds on a Cohere trial key at a busy hour, once more than two minutes. The HTTP client waits 120 seconds without a byte (`config/packages/framework.yaml`) before it gives up with "Idle timeout". Run the command again if that happens.
- **The page is a single-user tool.** HTTP basic auth with one password, and no roles. A request that writes or costs money has to come from the page itself (`Sec-Fetch-Site: same-origin`) or carry an `X-Synapse-Ui` header. Reading the notes needs only the password. `TRUSTED_HOSTS` in `.env` lets only `127.0.0.1`, `localhost` and `[::1]` through. The page sends no cookie, it sets a Content-Security-Policy with a nonce for its scripts, and it cannot be put in a frame.
- **Input has a size, and calls have a rate.** A capture can have 8000 characters, a question 1000, a text to read aloud 4000, a recording 10 MB (the recorder stops after four minutes). The page refuses more before any model is called, and it lets through 20 calls per minute that cost money (`rate_limiter` in `config/packages/framework.yaml`). An event stream answers once. A browser that asks again for the same one gets a `204`.
- **An empty key stops the command.** `app:ask`, `app:capture`, `app:federate` and `ai:store:index` say which key is missing and send nothing. The page says the same in the answer card.
- **The agents write drafts.** Notes the agents write carry `status: draft`. They never replace a note. The `Vault` refuses every path outside `inbox/` and `brain/`, and every symlink. Keep `notes/` in Git, so every change is a diff you can review.
- **The model can still be talked into things.** A note can contain text that talks to the model. A token check does not stop that, and neither does the `Vault`. It only limits what the model can do with the files.
- **Tool errors.** A tool that cannot do what it was asked, like a path that is not allowed, answers over MCP with `isError: true` and the reason as text, so the agent behind the client can read it and try again. The agents of this repository get the same text from the tool directly.
- **`APP_ENV=dev` is on.** An error in the browser shows a stack trace and paths. That is fine on your machine.

## What is in here

| Where | What |
|---|---|
| `src/Brain/` | `Vault` (the only code that touches files), `Brain`, `OkfValidator`, `Note`, the loader and the search |
| `src/Ai/` | the tools of the agents |
| `src/Mcp/` | the MCP server, `ToolGuard` and the pass that wraps every tool |
| `src/Security/` | token handler, bearer challenge, the guard of the page |
| `src/Controller/`, `templates/`, `assets/` | the page: Twig, Symfony UX, Turbo Streams, Tailwind through AssetMapper |
| `config/okf.yaml` | the note types and their rules, read by the validator and by the agent |
| `config/providers/` | one file per provider |
| `tools/` | the fake authorization server and the checks |

## Checks

`bin/smoke` runs the checks for the checked out code. Without a key it checks the wiring, the OKF rules, the MCP endpoint, the roles, the host check and the page. With a `COHERE_API_KEY` the agents answer for real. It copies your notes first and puts them back at the end. If a run is killed hard, `var/smoke-notes` holds the original notes, and the next run refuses to start until you have moved them back.

`php tools/okf-check.php` checks the note rules and the `Vault` against a throw-away folder. Add `-v` to print what the `Vault` says to a bad path. There is no PHPUnit suite. `tools/guard-check.php` walks every tool the MCP server lists and checks that each one is read-only or refused for a reader.

Symfony AI is at `1.0.0-BETA1`. The backwards compatibility promise starts with 1.0.0, so the code may need small changes when the final release ships. The Hugging Face model names in `config/providers/huggingface.yaml` are a first guess.

## License

MIT, see `LICENSE`.
