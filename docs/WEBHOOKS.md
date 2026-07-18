# Outgoing Webhooks

Register an endpoint under **Developer → Webhooks** to receive real-time, HMAC-signed
HTTP POST notifications when events happen in your organization.

---

## Event Catalog

| Event | Description |
|---|---|
| `org.member.invited` | A new member was invited to the organization |
| `org.member.joined` | An invited member joined the organization |
| `org.member.removed` | A member was removed from the organization |
| `billing.subscription.created` | A subscription was created |
| `billing.subscription.updated` | A subscription was updated |
| `billing.subscription.cancelled` | A subscription was cancelled |
| `billing.payment.succeeded` | An invoice payment succeeded |
| `billing.payment.failed` | An invoice payment failed |

Select which events an endpoint receives when you create or edit it.

---

## Payload Format

Every delivery is a `POST` request with a JSON body:

```json
{
  "event": "org.member.invited",
  "data": {
    "email": "new-member@example.com",
    "invited_by": "019836b1-...",
    "organization_id": "019836b1-..."
  },
  "delivery_id": "019836b1-...",
  "timestamp": "2026-07-18T09:00:00+00:00"
}
```

`data` varies per event type. `delivery_id` is unique per attempt retry, so you can
deduplicate using `event` + the `data` identifiers if you need idempotency across
retries of the *same underlying event* (each retry of one delivery reuses the same
`delivery_id`, but a fresh event of the same type gets a new one).

---

## Verifying the Signature

Every request includes an `X-Webhook-Signature` header:

```
X-Webhook-Signature: sha256=<hex-encoded HMAC-SHA256 of the raw request body, keyed with your endpoint's signing secret>
```

Compute the same HMAC over the **raw, unparsed** request body and compare using a
constant-time comparison — never compare with `==` or plain string equality.

### PHP

```php
$signature = 'sha256=' . hash_hmac('sha256', $rawBody, $endpointSecret);
if (!hash_equals($signature, $request->headers->get('X-Webhook-Signature'))) {
    throw new UnauthorizedHttpException('Invalid signature');
}
```

### Node.js

```js
const crypto = require('crypto');

function isValidSignature(rawBody, secret, header) {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(header));
}
```

### Python

```python
import hashlib
import hmac

def is_valid_signature(raw_body: bytes, secret: str, header: str) -> bool:
    expected = 'sha256=' + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, header)
```

The signing secret is shown **once**, when you create the endpoint or regenerate its
secret. Store it securely — it cannot be retrieved again through the UI (though it can
be rotated at any time via "Regenerate").

---

## Retries

If your endpoint doesn't respond with a `2xx` status (or doesn't respond at all within
10 seconds), the delivery is retried up to 5 times with the following backoff:

| Attempt | Delay after previous attempt |
|---|---|
| 2 | 1 minute |
| 3 | 5 minutes |
| 4 | 30 minutes |
| 5 | 2 hours |
| — | (final retry window: 12 hours) |

After 5 failed attempts, the delivery is marked `exhausted` and is not retried further.
The full delivery history (status, attempts, response codes) is visible on each
endpoint's detail page.

---

## Testing an Endpoint

Use the **Send Test Event** button on an endpoint's detail page to send a sample
`test` event payload — useful for verifying your signature validation logic before
subscribing to real events.
