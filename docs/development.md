# What is in here, and the checks

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
