# A real authorization server: Keycloak

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
