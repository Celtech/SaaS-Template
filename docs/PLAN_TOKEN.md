# Plan Passthrough Tokens

Plan passthrough tokens let an external site (e.g. your marketing pricing page) pre-select a
billing plan for a new user **before** they register. The selected plan is cryptographically
signed so it cannot be tampered with in transit.

---

## How It Works

1. Your marketing site generates a signed token for the plan the visitor clicked.
2. The token is appended to the registration URL: `/auth/register?plan_token=<token>`.
3. The registration controller validates the token and stores the decoded plan selection in
   the user's session under the key `billing.pending_plan`.
4. After the user completes registration and the onboarding org-creation step, the app reads
   the pending plan from session and redirects directly to the Stripe Checkout page with that
   plan pre-selected.

If the token is missing, expired, or invalid, the user still reaches the registration form
normally and lands on the plan selection page after onboarding.

---

## Token Format

```
<base64url(json_payload)>.<hmac_sha256_hex>
```

**Payload (JSON):**

```json
{
  "plan": "basic",
  "interval": "month",
  "exp": 1748000000
}
```

| Field      | Type     | Description                                         |
|------------|----------|-----------------------------------------------------|
| `plan`     | string   | Plan slug as defined in the `plans` database table  |
| `interval` | string   | `"month"` or `"year"`                               |
| `exp`      | integer  | Unix timestamp; tokens are valid for **1 hour**     |

The payload is Base64URL-encoded (RFC 4648 §5, no padding), dot-separated from the lowercase
hex HMAC-SHA256 signature.

---

## Generating Tokens

### PHP (Symfony — same app)

Use `App\Service\Billing\PlanTokenService` directly:

```php
use App\Service\Billing\PlanTokenService;

$token = $planTokenService->generate('basic', 'month');
// e.g. eyJwbGFuIjoiYmFzaWMiLCJpbnRlcnZhbCI6Im1vbnRoIiwiZXhwIjoxNzQ4MDAwMDAwfQ.a1b2c3...
```

The service is auto-wired; inject it where needed.

### PHP (external site / microservice)

```php
$secret = $_ENV['PLAN_TOKEN_SECRET'];
$payload = rtrim(strtr(base64_encode(json_encode([
    'plan'     => 'basic',
    'interval' => 'month',
    'exp'      => time() + 3600,
])), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $payload, $secret);
$token = $payload . '.' . $signature;
```

### Node.js

```js
const crypto = require('crypto');

function generatePlanToken(planSlug, interval, secret) {
  const payload = Buffer.from(JSON.stringify({
    plan: planSlug,
    interval,
    exp: Math.floor(Date.now() / 1000) + 3600,
  })).toString('base64url');

  const signature = crypto
    .createHmac('sha256', secret)
    .update(payload)
    .digest('hex');

  return `${payload}.${signature}`;
}
```

### Python

```python
import base64
import hashlib
import hmac
import json
import time

def generate_plan_token(plan_slug: str, interval: str, secret: str) -> str:
    payload_bytes = json.dumps({
        'plan': plan_slug,
        'interval': interval,
        'exp': int(time.time()) + 3600,
    }, separators=(',', ':')).encode()

    encoded_payload = base64.urlsafe_b64encode(payload_bytes).rstrip(b'=').decode()
    signature = hmac.new(secret.encode(), encoded_payload.encode(), hashlib.sha256).hexdigest()
    return f'{encoded_payload}.{signature}'
```

### Ruby

```ruby
require 'base64'
require 'json'
require 'openssl'

def generate_plan_token(plan_slug, interval, secret)
  payload = Base64.urlsafe_encode64(
    JSON.generate(plan: plan_slug, interval: interval, exp: Time.now.to_i + 3600),
    padding: false
  )
  signature = OpenSSL::HMAC.hexdigest('SHA256', secret, payload)
  "#{payload}.#{signature}"
end
```

---

## Embedding Tokens in Your Marketing Site

```html
<!-- Pricing page "Get started" button for the Basic monthly plan -->
<a href="https://app.example.com/auth/register?plan_token={{ basic_monthly_token }}">
  Get started — Basic
</a>
```

Pre-generate tokens server-side when the pricing page renders (tokens are valid for 1 hour so
regenerate on every page load, or cache for a shorter window).

---

## Shared Secret

The signing secret is set via the `PLAN_TOKEN_SECRET` environment variable.

| Environment | Variable           | Value                                    |
|-------------|--------------------|------------------------------------------|
| Production  | `PLAN_TOKEN_SECRET` | A long random string (≥ 32 bytes)       |
| Test        | `PLAN_TOKEN_SECRET` | `test-plan-token-secret` (`.env.test`)   |

Generate a production secret:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
# or
openssl rand -hex 32
```

**Never share this secret publicly or commit it to source control.**
Both the app and any external marketing site that generates tokens must use the same secret.

---

## Security Properties

| Property              | Implementation                                                  |
|-----------------------|-----------------------------------------------------------------|
| Tamper detection      | HMAC-SHA256; any modification invalidates the signature         |
| Replay attack window  | 1-hour expiry (`exp` claim enforced server-side)                |
| Timing-safe comparison| `hash_equals()` prevents timing attacks on the signature check  |
| Interval validation   | Only `"month"` and `"year"` are accepted                        |

Tokens do **not** include the user's identity — they carry only the plan selection.
A stolen token can only pre-fill a plan choice for another user's registration, not grant
access to an existing account.

---

## Validation Logic (app internals)

`PlanTokenService::validate(string $token): ?array` returns `['plan' => ..., 'interval' => ...]`
on success, or `null` for any failure (invalid format, bad signature, expired, unknown interval).

The controller never reveals *why* a token failed — it silently falls back to the plan
selection page.
