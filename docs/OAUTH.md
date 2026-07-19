# OAuth 2.0 API Authentication

This application uses OAuth 2.0 as its API authentication mechanism. Register an
application under **Developer → Applications** to get a `client_id` and `client_secret`,
then use one of the grant types below to obtain an access token.

---

## Discovery

Server metadata (RFC 8414) is published at:

```
GET /.well-known/oauth-authorization-server
```

This returns the token, authorization, device authorization, revocation, and
introspection endpoint URLs, the supported grant types, and the full scope catalog —
use it instead of hardcoding endpoint paths where your client library supports it.

---

## Scopes

| Scope | Grants |
|---|---|
| `openid` | Verify identity |
| `profile` | Read name and avatar |
| `email` | Read email address |
| `org:read` | Read organization data |
| `org:write` | Write organization data |
| `api:read` | Read access to the API |
| `api:write` | Write access to the API |

Requested scopes must be a subset of the scopes the application was registered with.
If no `scope` parameter is sent, all of the application's registered scopes are granted.

---

## Grant Types

### Client Credentials (machine-to-machine)

No end user is involved — the application acts as itself. Use this for
server-to-server integrations.

```bash
curl -X POST https://your-app.example/oauth/token \
  -d "grant_type=client_credentials" \
  -d "client_id=YOUR_CLIENT_ID" \
  -d "client_secret=YOUR_CLIENT_SECRET" \
  -d "scope=api:read api:write"
```

Client credentials may also be sent via HTTP Basic auth (`client_secret_basic`)
instead of as POST body fields (`client_secret_post`) — both are supported on every
grant that requires client authentication.

Returns an access token only — no refresh token, since the client can simply
re-authenticate whenever it needs a new one.

### Authorization Code + PKCE (delegated, user-present)

Use this when a user needs to grant your application access to their own
organization's data — the resulting token acts on behalf of that user, scoped to
whatever they approve on the consent screen.

1. Generate a PKCE `code_verifier` (random string) and its S256 `code_challenge`
   (`BASE64URL(SHA256(code_verifier))`).
2. Redirect the user to:

   ```
   GET /oauth/authorize
     ?response_type=code
     &client_id=YOUR_CLIENT_ID
     &redirect_uri=YOUR_REGISTERED_REDIRECT_URI
     &scope=openid+profile+org:read
     &state=RANDOM_CSRF_STRING
     &code_challenge=YOUR_CODE_CHALLENGE
     &code_challenge_method=S256
   ```

   `redirect_uri` must exactly match one of the URIs registered on the application.
3. After the user approves, they're redirected back to your `redirect_uri` with a
   `code` and your original `state`. Verify `state` matches before continuing.
4. Exchange the code for a token:

   ```bash
   curl -X POST https://your-app.example/oauth/token \
     -d "grant_type=authorization_code" \
     -d "code=THE_CODE" \
     -d "redirect_uri=YOUR_REGISTERED_REDIRECT_URI" \
     -d "code_verifier=YOUR_ORIGINAL_CODE_VERIFIER" \
     -d "client_id=YOUR_CLIENT_ID" \
     -d "client_secret=YOUR_CLIENT_SECRET"
   ```

The authorization code expires after 60 seconds and is single-use.

### Device Authorization (input-constrained devices)

For CLIs, TVs, or other devices without a convenient browser-based redirect flow.

1. Start the flow:

   ```bash
   curl -X POST https://your-app.example/oauth/device/authorization \
     -d "client_id=YOUR_CLIENT_ID" \
     -d "scope=api:read"
   ```

   Returns `device_code`, `user_code`, `verification_uri`,
   `verification_uri_complete`, `expires_in`, and `interval`.
2. Show the user the `user_code` (or the QR-code-friendly
   `verification_uri_complete`) and have them approve it in a browser at
   `verification_uri`.
3. Poll the token endpoint at the given `interval`:

   ```bash
   curl -X POST https://your-app.example/oauth/token \
     -d "grant_type=urn:ietf:params:oauth:grant-type:device_code" \
     -d "device_code=THE_DEVICE_CODE" \
     -d "client_id=YOUR_CLIENT_ID"
   ```

   Returns `authorization_pending` until the user approves, `access_denied` if they
   deny, or a token once approved.

### Refresh Token

Access tokens expire after 1 hour. Exchange a refresh token for a new pair:

```bash
curl -X POST https://your-app.example/oauth/token \
  -d "grant_type=refresh_token" \
  -d "refresh_token=YOUR_REFRESH_TOKEN" \
  -d "client_id=YOUR_CLIENT_ID" \
  -d "client_secret=YOUR_CLIENT_SECRET"
```

Per RFC 6749 §6, the client must (re-)authenticate on every refresh — this is not
optional. Each refresh **rotates** the refresh token: the old one is revoked and a new
one is issued alongside the new access token. Refresh tokens are valid for 30 days
from issuance (not from last use).

---

## Introspection & Revocation

### Introspect a token (RFC 7662)

```bash
curl -X POST https://your-app.example/oauth/introspect \
  -d "token=THE_ACCESS_TOKEN"
```

Returns `{"active": false}` for any invalid, expired, or revoked token — no
authentication is required to call this endpoint, so avoid leaking whether a token
existed at all in your own client-side error handling.

### Revoke a token (RFC 7009)

```bash
curl -X POST https://your-app.example/oauth/revoke \
  -d "token=THE_TOKEN" \
  -d "token_type_hint=access_token"
```

Set `token_type_hint` to `refresh_token` to revoke a refresh token instead. Per
RFC 7009 §2.2, this always returns `200 OK`, even for a token that doesn't exist.

---

## Managing Applications

Under **Developer → Applications**, org members with the `org.api_keys.manage`
permission (Admin/Owner roles by default) can register, edit, and delete OAuth
applications and regenerate client secrets. Members with only
`org.api_keys.view` can see registered applications but not modify them.

The `client_secret` is shown **once**, at creation or regeneration time, and stored
only as a SHA-256 hash — it cannot be retrieved again through the UI.
