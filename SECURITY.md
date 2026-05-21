# Security Policy

## Supported Versions

Only the latest release of this template receives security fixes. If you have forked or deployed an older version, upgrade to the latest release before reporting an issue.

| Version | Supported |
|---|---|
| Latest (`main`) | Yes |
| Older releases | No |

---

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues, pull requests, or discussions.**

### How to report

Send a report to **security@[your-domain]** with the subject line `[SECURITY] Brief description`.

If you prefer encrypted communication, request our PGP key by emailing the address above.

### What to include

A useful report includes:

- A clear description of the vulnerability and its potential impact
- The component or file affected (e.g., "Stripe webhook handler in `src/Controller/Webhook/`")
- Step-by-step reproduction instructions
- Any proof-of-concept code or screenshots
- Your assessment of severity (Critical / High / Medium / Low) and why

The more detail you provide, the faster we can triage and fix the issue.

### What happens next

| Timeframe | Action |
|---|---|
| Within 48 hours | We acknowledge receipt and confirm whether the report is in scope |
| Within 7 days | We provide an initial assessment and expected resolution timeline |
| Within 30 days | We aim to release a fix for confirmed Critical/High issues |
| After fix ships | We notify you and ask if you want to be credited |

We follow [responsible disclosure](https://en.wikipedia.org/wiki/Coordinated_vulnerability_disclosure): we ask that you give us a reasonable time to fix the issue before publishing details publicly.

---

## Scope

### In scope

- Authentication and session management flaws
- Authorisation bypasses (RBAC, voter, permission checks)
- SQL injection, XSS, CSRF, SSRF, command injection
- API authentication or rate-limiting bypasses
- Stripe webhook signature bypass
- Audit log tampering or bypass
- Sensitive data exposure (credentials, tokens, PII in logs or API responses)
- Insecure direct object references
- Privilege escalation (user → admin, one org's data accessible by another)
- Dependency vulnerabilities in production dependencies (`composer audit`)

### Out of scope

- Vulnerabilities in third-party services (Stripe, ZeptoMail, etc.): report these to the respective vendor
- Issues that require physical access to the server
- Social engineering attacks
- Denial-of-service attacks (volumetric or resource exhaustion)
- Issues only exploitable by an authenticated Super Admin with full system access
- Scanner output without a demonstrated, reproducible impact
- Missing security headers on non-application routes (health checks, static assets)

---

## Security Architecture Overview

For context when evaluating a potential issue:

- **Authentication:** Symfony Security with Argon2id password hashing; 2FA/TOTP enforced for admin accounts
- **Authorisation:** RBAC via Symfony Security voters; permissions are code-defined, roles are DB-managed
- **Session security:** `Secure`, `HttpOnly`, `SameSite=Strict` cookies; session ID regenerated on login
- **Account lockout:** configurable max failed attempts before temporary lockout
- **Audit logging:** immutable append-only log covering all auth events and admin mutations
- **API:** opaque API keys stored as SHA-256 hash only; rate-limited per key and per org
- **Webhooks:** all outbound payloads signed with HMAC-SHA256; Stripe inbound webhooks signature-verified before processing
- **Payment data:** Stripe Checkout/Customer Portal only: no raw card data ever reaches this application (PCI SAQ-A scope)
- **Sensitive data:** Monolog processor scrubs passwords, tokens, secrets, and auth headers from all log output
- **Transport:** TLS enforced via Caddy (FrankenPHP); HSTS, CSP, and other security headers on all responses

---

## Acknowledgements

We appreciate security researchers who responsibly disclose issues. Confirmed, in-scope vulnerability reporters will be acknowledged here (with your permission) once the issue is resolved.
