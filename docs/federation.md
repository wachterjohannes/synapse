# Ask a second brain

Federation needs the fake authorization server from [the MCP page](mcp.md) (or Keycloak) and a `COHERE_API_KEY`.

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
