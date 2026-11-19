# The MCP server

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

For a real authorization server see [Keycloak](keycloak.md). To ask a second brain see [federation](federation.md).
