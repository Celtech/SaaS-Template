# SaaS Template: Project Roadmap

> This document is the source of truth for the full build plan. Update it as phases complete and decisions evolve.

---

## Tech Stack

| Layer | Choice | Why |
|---|---|---|
| Language / Framework | PHP 8.5 + Symfony 8 | Latest stable; required by Symfony 8 |
| App Server | FrankenPHP (worker mode) | Built-in Caddy, no nginx, Symfony-recommended, better throughput |
| Database | PostgreSQL 17 | RLS for tenant isolation, pgaudit (SOC2), JSONB, UUID native, Citus sharding path |
| ORM | Doctrine ORM | Migrations, type system, UUID v7 support |
| Cache / Queue transport | Valkey 8 | OSS Redis fork (Redis went SSPL 2024), wire-compatible |
| Async | Symfony Messenger | Webhook delivery, emails, subscription events, notifications |
| Scheduler | Symfony Scheduler | All cron-style tasks, set up in Phase 1 |
| Frontend | Twig + Symfony UX TwigComponent + Turbo + Stimulus | Single runtime, no JS framework |
| CSS | Tailwind v4 + CSS custom properties | shadcn-inspired component library in Twig |
| Payments | Stripe PHP SDK | Checkout, Customer Portal, webhooks: SAQ-A PCI scope |
| Email | ZeptoMail (Zoho) via Symfony Mailer SMTP | Production; Mailpit locally |
| IDs | UUID v7 everywhere | No enumeration attack, time-ordered, shard key |
| Containers | Docker multi-stage + Docker Compose | Dev and production variants |

---

## Architecture Decisions

### Personal Organization Pattern
Every user gets a personal `Organization` on signup (`type: personal`). All resources (subscriptions, API keys, webhooks) belong to an `Organization`. Converting personal to team is a first-class operation. `organization_id` is the shard key on all tenant data tables.

### RBAC
One engine, two namespaces (`admin.*`, `org.*`). Permissions are code-defined (PHP enum). Roles, permission-to-role assignments, and user-to-role assignments are all DB-managed and editable via admin UI. Default roles are seeded on install.

### Impersonation (SOC2 / PCI Compliant)
Symfony `switch_user`: no credential access. Immutable `ImpersonationSession` audit record. Hard 60-min expiry. Payment fields masked during session. Persistent UI banner. Email notification configurable (off by default, `admin.system.configure` required to change).

### Billing Configuration
`BillingSettings` singleton in DB. Admin-editable. Controls: trial enabled/days, free tier enabled/plan, trial expiry behavior (`require_payment` | `downgrade_to_free` | `cancel`). Default: 14-day trial, no free tier, `require_payment` on expiry.

### Entitlement Caching
`EntitlementService` never queries Postgres on every request. When a Stripe webhook changes a tenant's subscription status, a Messenger handler compiles the full entitlement matrix into a flat JSON structure and writes it to Valkey under `entitlements:{org_id}`. `EntitlementService` reads from Valkey first; it falls back to Postgres only on a cold cache miss, then repopulates Valkey. This eliminates per-request DB queries for entitlement checks across all controllers, Twig renders, and API calls.

### Notification System
An extensible, channel-agnostic notification pipeline. All notification types are PHP classes implementing a `NotifiableInterface`. Supported channels from day one: in-app (database-backed) and email. Additional channels (Slack, Discord, SMS) are added by implementing a `NotificationChannelInterface` — no core changes required. Per-user, per-type channel preferences are stored in `NotificationPreference`. Delivery is always async via Symfony Messenger. The in-app feed is a real-time-compatible append-only table polled via Turbo Streams.

### User Session Management
Every login creates a `UserSession` record (UUID v7, user, session_token_hash, ip_address, user_agent, created_at, last_active_at, revoked_at). Session token is the Symfony session ID stored as SHA-256. A `SessionTrackingListener` updates `last_active_at` on each authenticated request (debounced to at most once per minute). A "Security" tab in the user profile lists all active sessions and allows individual revocation, which destroys the Valkey/session store entry and marks `revoked_at` in the DB.

### GDPR / CCPA: Anonymization vs. Immutable Audit Log
Hard-deleting a user conflicts directly with SOC2: `AuditLog` rows reference `actor_id` and `subject_id` UUIDs, and destroying the user breaks audit trail integrity. The resolution is a formal **anonymization pipeline** instead of hard-deletion:

- `User::status` enum: `active | suspended | anonymized`
- On a verified erasure request, a `AnonymizeUserMessage` is dispatched via Messenger
- The handler scrubs all PII from the `users` table (email replaced with `anonymized_{uuid}@deleted.invalid`, name cleared, avatar_url nulled, all tokens revoked)
- `UserSession`, `OrganizationInvite`, and any other PII-bearing tables are similarly scrubbed
- IP addresses in `AuditLog` rows where `actor_id = user.id` are replaced with `0.0.0.0`
- The UUID v7 identifier is preserved: audit trail references remain valid for SOC2 auditors
- The anonymized user record itself is retained indefinitely (contains no PII)
- A `DataErasureRequest` entity tracks the request, requestor, status, and completion timestamp for compliance evidence

This satisfies GDPR Article 17 ("right to be forgotten") while preserving audit log integrity for SOC2 Type II.

### API Idempotency
All state-mutating API endpoints (`POST`, `PATCH`, `DELETE`) accept an optional `Idempotency-Key` header (UUID v4, client-generated). A Symfony event listener intercepts requests with this header, computes a cache key of `idempotency:{org_id}:{idempotency_key}`, and checks Valkey. On a cache hit, the stored response is returned immediately without executing the handler. On a miss, the response is stored in Valkey (TTL: 24 hours) after the handler completes. This prevents duplicate resource creation when enterprise clients retry after network timeouts.

### SOC2 Compliance Strategy
Built in from Phase 1, not bolted on. The system satisfies the three relevant Trust Service Criteria:

**Security**
- RBAC + least-privilege by default (Phases 1, 4)
- Immutable append-only `AuditLog` covering all auth events and admin mutations (Phase 1)
- 2FA/TOTP **required** for admin accounts, optional for users (Phase 2 scaffold, Phase 7a enforce)
- Account lockout after configurable failed login attempts (Phase 2)
- Session timeout: 15-min idle for admin, configurable for users (Phase 2)
- Security headers on all responses from Phase 1 (CSP, HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy)
- `composer audit` on every CI run

**Confidentiality**
- Passwords: Argon2id (Symfony default)
- API keys: SHA-256 hash stored, prefix + last 4 shown in UI, never logged
- Webhook secrets: shown once on creation, stored hashed
- Custom Monolog processor scrubs all sensitive fields (passwords, tokens, secrets, Authorization headers) before any log entry is written
- Impersonation: payment fields masked (Stripe tokens only; we never hold card data)

**Availability**
- Health check endpoints (`/health`, `/health/ready`) from Phase 1
- Structured JSON logging for SIEM/alerting integration
- Audit log retention: configurable minimum (default 1 year); logs are never deletable via admin UI

### PCI DSS Strategy (SAQ-A scope)
Stripe Checkout + Customer Portal means we never handle, process, or store raw card data. SAQ-A is the applicable tier. Compliance requirements:
- Never log card data, even partial: Monolog scrubber handles this
- TLS 1.2+ everywhere: Caddy/FrankenPHP handles automatically
- Stripe webhook signature verification on every inbound webhook (Phase 6)
- Strong access controls: RBAC (Phase 4)
- Vulnerability management: `composer audit` + security headers (Phase 1)
- Session security: `Secure`, `HttpOnly`, `SameSite=Strict` cookies; CSRF protection; session ID regeneration on privilege escalation (Symfony handles)

---

## Phase 1: Foundation

> Goal: runnable app with all infrastructure wired. Compliance scaffolding in from day one.

**Status: complete**

**App Server & Containers**
- [x] Symfony 8 project scaffold, FrankenPHP Dockerfile (multi-stage: dev + prod)
- [x] Docker Compose dev: app (FrankenPHP), postgres, valkey, mailpit, worker, adminer
- [x] Docker Compose prod variant: prod FrankenPHP, no dev tools, health checks, restart policies
- [x] Makefile: `make up`, `make down`, `make migrate`, `make test`, `make worker`, `make shell`, `make audit`

**Database**
- [x] PostgreSQL container, Doctrine configured, UUID v7 strategy set as global default
- [x] Migrations workflow: `make migrate`, `make migration` (generate), rollback documented

**Async & Scheduling**
- [x] Valkey container, Symfony Messenger configured (async transport for all side effects)
- [x] Symfony Scheduler configured with worker (runs alongside Messenger worker)

**Frontend**
- [x] Tailwind v4 via Asset Mapper (no separate Node build step)
- [x] CSS custom property theming layer: full shadcn-compatible palette
- [x] Twig component library: Button, Card family, Input, Textarea, Label, Badge, Alert, Avatar, Separator, Spinner, Table, Pagination, EmptyState, PageHeader, FormGroup, FormError, Dialog, Sheet, Tooltip, Sidebar, Topbar
- [x] Base layouts: `base.html.twig`, `app.html.twig`, `auth.html.twig`, `admin.html.twig`
- [x] Stimulus controllers: modal, dropdown, toast, confirm-dialog, copy-to-clipboard, character-counter

**Compliance Infrastructure (SOC2 / PCI)**
- [x] `AuditLog` entity and `AuditLogger` service (DBAL-direct, bypasses outer transactions)
- [x] `SensitiveDataProcessor` Monolog processor
- [x] Security headers response listener (CSP, HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy)
- [x] Health check endpoints: `GET /health`, `GET /health/ready`

**Testing & CI**
- [x] PHPUnit 13 with unit/integration/functional suites
- [x] Base test case classes and `DatabaseTransactionTrait`
- [x] GitHub Actions CI: tests job (postgres + valkey services) + quality job (audit, cs-fixer, phpstan level 8)
- [x] PHP-CS-Fixer and PHPStan level 8 configured

**Branch:** `feat/phase-1-foundation` (merged)

---

## Phase 2: Auth & Users

> Goal: users can register, verify email, log in, reset password. All auth events audited. User-facing session security.

**Entities**
- [ ] `User` entity (UUID v7, email, password_hash: Argon2id, name, avatar_url, email_verified_at, status: active|suspended|anonymized, failed_login_count, locked_until, created_at, updated_at)
- [ ] `UserSession` entity (UUID v7, user, session_token_hash SHA-256, ip_address, user_agent, created_at, last_active_at, revoked_at)
- [ ] `DataErasureRequest` entity (UUID v7, user, requested_by, status: pending|processing|complete, requested_at, completed_at, notes)

**Registration & Verification**
- [ ] Registration form with password strength validation (min length, complexity: Symfony constraints)
- [ ] Email verification: signed URL (Symfony `UrlSigner`), 24hr expiry, resend option
- [ ] Auto-create personal `Organization` on successful registration
- [ ] `AuditLogger::logAuth('registered', ...)` on creation

**Login & Session Security**
- [ ] Login form (Symfony Security `FormLoginAuthenticator`)
- [ ] Remember-me (secure, HttpOnly, SameSite=Strict cookie)
- [ ] Session cookie flags: `Secure`, `HttpOnly`, `SameSite=Strict`
- [ ] Session ID regeneration on login (prevents session fixation)
- [ ] Configurable session timeout (default: 2hr idle for users)
- [ ] `SessionTrackingListener`: on each authenticated request, upsert `UserSession::lastActiveAt` (debounced to at most once per minute via Valkey TTL flag)
- [ ] `AuditLogger::logAuth('login_success', ...)` and `logAuth('login_failed', ...)` on every attempt

**Account Lockout (SOC2)**
- [ ] `SecuritySettings` singleton entity (admin-editable): `max_failed_attempts` (default: 5), `lockout_duration_minutes` (default: 30)
- [ ] `LoginFailureHandler` increments `User::failedLoginCount`; sets `User::lockedUntil` when threshold reached
- [ ] `LoginSuccessHandler` resets `User::failedLoginCount`
- [ ] `AuditLogger::logSecurityEvent('account_locked', ...)` when lockout triggers
- [ ] Locked account shows clear error with unlock instructions (not a generic "invalid credentials")

**Password Reset**
- [ ] Forgot password form, signed token email, new password form, token invalidated
- [ ] Tokens single-use, 1hr expiry
- [ ] `AuditLogger::logAuth('password_reset_requested', ...)` and `logAuth('password_reset_completed', ...)`

**2FA Scaffold (enforced for admin in Phase 7a)**
- [ ] `scheb/2fa-bundle` installed and configured
- [ ] TOTP (Google Authenticator compatible) as the 2FA method
- [ ] `User::totpSecret`, `User::totpEnabled` fields
- [ ] 2FA setup flow (QR code, verify code, backup codes)
- [ ] 2FA optional for regular users, flag to enforce per-role (used in Phase 7a for admin)

**User Profile**
- [ ] Profile page: update name, avatar, change password
- [ ] **Security tab**: active session list (IP, browser, last active, current session highlighted), "Revoke" button per session, "Revoke all other sessions" button
- [ ] Session revocation: destroys session store entry (Valkey/filesystem) + sets `UserSession::revokedAt`
- [ ] `AuditLogger::logSecurityEvent('session_revoked', ...)` on revoke

**GDPR / CCPA Foundation**
- [ ] `User::status` field (active | suspended | anonymized) wired into security voter (anonymized users cannot log in)
- [ ] `AnonymizeUserMessage` + `AnonymizeUserHandler` Messenger handler: scrubs email, name, avatar, IPs in audit log, revokes all sessions and API keys, sets status to `anonymized`
- [ ] Admin action "Request erasure" creates `DataErasureRequest` and dispatches message (gated to `admin.users.delete`)
- [ ] `AuditLogger::logAdminAction('user_anonymized', ...)` on completion

**Tests**
- [ ] Unit: password strength constraints, account lockout logic, lockout expiry, anonymization handler
- [ ] Functional: full registration flow, email verification, login, failed login lockout, password reset
- [ ] Functional: session fixation prevention (session ID changes post-login)
- [ ] Functional: session revocation destroys session and prevents subsequent requests
- [ ] Functional: anonymized user cannot log in; audit log UUID references remain intact
- [ ] Browser smoke test: register, verify, login, view sessions, revoke session, logout

**Branch:** `feat/phase-2-auth`

---

## Phase 3: Organizations & Teams

> Goal: users can create/join team orgs, invite members, manage membership.

- [ ] `Organization` entity (UUID v7, name, slug, type: personal|team, stripe_customer_id, status: active|anonymized, created_at)
- [ ] `OrganizationMember` entity (org, user, joined_at): role resolved via RBAC (Phase 4)
- [ ] `OrganizationInvite` entity (UUID v7, org, email, token_hash, role, expires_at, accepted_at)
- [ ] Org creation form (name, slug auto-generated + editable, uniqueness validated)
- [ ] Email invite: signed token, 7-day expiry, accept/decline pages
- [ ] Org context switcher in navigation (personal org vs team orgs)
- [ ] Org settings page (name, slug, danger zone: transfer ownership, delete org)
- [ ] Member list page (member, role, joined date, remove action)
- [ ] `AuditLogger` events: org_created, member_invited, invite_accepted, member_removed, org_deleted
- [ ] GDPR: org deletion anonymizes org data (name, slug replaced with anonymized placeholder) rather than hard-deleting; members retain their user records
- [ ] Unit tests: invite token hashing, expiry, org slug uniqueness
- [ ] Functional tests: create org, full invite flow, member removal, org deletion
- [ ] Browser smoke tests

**Branch:** `feat/phase-3-organizations`

---

## Phase 4: RBAC

> Goal: fully dynamic role/permission system for both admin and org contexts.

- [ ] `Permission` PHP backed enum: all `admin.*` and `org.*` keys (canonical list in enum docblock)
- [ ] `Role` entity (UUID v7, name, description, context: admin|org, is_system: bool, created_at)
- [ ] `RolePermission` join entity (role, permission_key)
- [ ] `UserRole` join entity (user, role, context_id: null for admin / org_id for org, granted_by_id, granted_at)
- [ ] `AdminVoter`: resolves admin-context permissions via UserRole for current user
- [ ] `OrganizationVoter`: resolves org-scoped permissions for current user in current org
- [ ] `is_granted()` works transparently in controllers and Twig for both contexts
- [ ] Database seed: system roles (not editable, `is_system: true`):
  - Admin: Support, Admin, Super Admin
  - Org: Member, Admin, Owner
- [ ] `AuditLogger` events: role_created, role_updated, permission_assigned, permission_revoked, user_role_granted, user_role_revoked
- [ ] Unit tests: voter resolution for every permission in each context
- [ ] Functional tests: 403 on permission-gated routes, correct access for role holders

**Branch:** `feat/phase-4-rbac`

---

## Phase 5: Onboarding

> Goal: post-registration wizard guides users to a productive starting state.

- [ ] Onboarding wizard: step 1 (complete profile + avatar), step 2 (create or join org), step 3 (choose plan / start trial)
- [ ] Wizard state in session (step, partial data), resumable if interrupted
- [ ] `KernelEvents::REQUEST` listener redirects incomplete-onboarding users to wizard (except auth/webhook/api/health routes)
- [ ] `User::onboardingCompletedAt` set on wizard completion
- [ ] Skip option where legally/UX appropriate
- [ ] Functional tests: complete flow, interrupt + resume, skip path

**Branch:** `feat/phase-5-onboarding`

---

## Phase 6: Billing & Subscriptions

> Goal: full Stripe integration, plan/entitlement system, configurable trial and free tier logic.

**Entities**
- [ ] `Plan` (UUID v7, name, slug, stripe_price_id_monthly, stripe_price_id_annual, is_active, sort_order, is_enterprise, description)
- [ ] `Entitlement` (UUID v7, key, name, type: boolean|integer|unlimited, description, group)
- [ ] `PlanEntitlement` (plan, entitlement, value)
- [ ] `Subscription` (UUID v7, organization, plan, status, stripe_subscription_id, stripe_customer_id, trial_ends_at, current_period_end, seat_count, cancelled_at)
- [ ] `BillingSettings` (singleton: trial_enabled, trial_days, free_tier_enabled, free_tier_plan_id, trial_expiry_behavior)

**Services**
- [ ] `EntitlementService`: `check(string $key): bool`, `getValue(string $key): int|bool|null`
  - Checks Valkey key `entitlements:{org_id}` first (JSON flat map)
  - Falls back to Postgres on cold cache miss, then writes to Valkey
  - Cache invalidated (and rebuilt synchronously) by `EntitlementCacheWarmHandler` Messenger handler on every subscription state change
- [ ] Twig extension: `entitlement_check('key')`, `entitlement_value('key')`
- [ ] `SubscriptionService`: create, upgrade, downgrade, cancel, reactivate
- [ ] `StripeWebhookHandler`: validates signature, routes events to Messenger messages

**Stripe Integration**
- [ ] Stripe Checkout session creation (new subscription, trial)
- [ ] Stripe Customer Portal redirect (self-service changes)
- [ ] Webhook endpoint `POST /stripe/webhook`: **signature verified on every request** (PCI requirement)
- [ ] Messenger handlers for: `subscription.created`, `subscription.updated`, `subscription.deleted`, `invoice.payment_succeeded`, `invoice.payment_failed`
- [ ] Each subscription Messenger handler dispatches `EntitlementCacheWarmMessage` after updating DB
- [ ] `AuditLogger::logBillingEvent()` on every subscription state change

**Scheduler Tasks**
- [ ] Daily trial expiry check: applies `BillingSettings::trialExpiryBehavior`
- [ ] Daily failed-payment grace period enforcement
- [ ] Daily seat count enforcement (enterprise)

**UI**
- [ ] Pricing page (plan cards with entitlement comparison)
- [ ] Billing settings page (current plan, next renewal, usage summary, upgrade/downgrade, cancel)
- [ ] Enterprise: seat count display and management

**Seed Data**
- [ ] Default plans: Basic, Pro, Ultimate, Enterprise
- [ ] Sample entitlements: `max_seats`, `max_api_keys`, `can_use_webhooks`, `can_use_api`, `max_api_calls_per_month`, `can_export`

**Tests**
- [ ] Unit: `EntitlementService` Valkey hit/miss/rebuild, trial expiry logic, seat enforcement
- [ ] Integration: Stripe webhook handler with fixture payloads (each event type), entitlement cache warm
- [ ] Functional: checkout redirect, portal redirect, entitlement gates (403 when over limit)
- [ ] Browser smoke test: pricing page, start trial, view billing settings

**Branch:** `feat/phase-6-billing`

---

## Phase 7: Admin Backend

> Goal: full admin panel for managing customers, orgs, plans, and system config. SOC2-compliant access controls throughout.

### 7a: Admin Shell, Auth & 2FA

- [ ] `/admin` route prefix, separate firewall in `security.yaml`
- [ ] Admin requires `ROLE_ADMIN` minimum; `ROLE_SUPER_ADMIN` gates system config
- [ ] **2FA enforced for all admin accounts**: redirect to 2FA setup if not configured; `scheb/2fa-bundle` enforcement at firewall level
- [ ] Admin session timeout: 15-min idle (separate from user session timeout)
- [ ] Optional IP allowlist for `/admin` (configurable in `SecuritySettings`, super-admin only)
- [ ] Admin layout: sidebar nav, user menu, audit log quick-access
- [ ] `AuditLogger::logAuth('admin_login', ...)` and `logAuth('admin_logout', ...)`

**Branch:** `feat/phase-7a-admin-shell`

### 7b: User & Org Management

- [ ] User list: search, filter by status/plan/org, pagination, export CSV
- [ ] User detail: profile, org memberships, active subscription, API keys, recent audit log
- [ ] User detail: active session list visible to admins (same data as user-facing security tab)
- [ ] User status management: suspend, unsuspend, trigger anonymization (GDPR erasure)
- [ ] Org list + detail: members, subscription, webhook endpoints
- [ ] Impersonation:
  - Start impersonation, `ImpersonationSession` record created (actor, target, IP, user agent, started_at)
  - All actions during session tagged to impersonation session in audit log
  - Persistent banner: "Viewing as [User Name]: [End Impersonation]"
  - Hard 60-min expiry (Scheduler task checks and terminates)
  - End impersonation, `ImpersonationSession::ended_at` set
  - Email notification: configurable (off by default), toggle gated to `admin.system.configure`
  - Payment/billing fields display as `••••` during impersonation
- [ ] All mutations logged via `AuditLogger::logAdminAction()`

**Branch:** `feat/phase-7b-admin-users`

### 7c: Billing Plan Management

- [ ] Plan CRUD (create, edit, toggle active, reorder drag-and-drop)
- [ ] Entitlement CRUD (create new entitlement keys, add to Permission enum via code: admin creates DB record)
- [ ] Assign/edit entitlement values per plan
- [ ] `BillingSettings` form (trial config, free tier config, expiry behavior)
- [ ] Subscription management (list, view, manual cancel, seat adjustment, extend trial)
- [ ] All changes logged via `AuditLogger`

**Branch:** `feat/phase-7c-admin-billing`

### 7d: Admin RBAC & Security Management

- [ ] Admin role list: view, create custom roles, edit permissions, delete (system roles protected)
- [ ] Permission assignment UI: grouped by namespace (`admin.*`, `org.*`)
- [ ] Admin user role assignment (grant/revoke roles to admin users)
- [ ] `SecuritySettings` form (max failed logins, lockout duration, session timeouts, admin IP allowlist)
- [ ] Audit log viewer:
  - Filterable by: actor, action category, subject, date range, IP
  - Paginated, read-only (no delete UI: enforced at repo level too)
  - Export to CSV (super-admin only)
- [ ] Impersonation log viewer (separate from audit log, all sessions with duration and action count)
- [ ] GDPR erasure request queue: list pending `DataErasureRequest` records, view status, re-trigger on failure

**Branch:** `feat/phase-7d-admin-rbac`

---

## Phase 8: Public API (v1)

> Goal: documented, versioned, authenticated REST API with idempotency support.

> **Superseded by OAuth 2.0.** This phase originally planned a simple `ApiKey`/SHA-256 model. That was replaced with the full OAuth 2.0 infrastructure below (Client Credentials, Authorization Code + PKCE, Device Authorization) as the actual API auth mechanism — it covers everything the `ApiKey` plan did plus delegated/scoped access. OpenAPI docs were dropped from scope entirely (operator decision); rate limiting and the idempotency middleware are still outstanding.

- [x] Client credential storage (UUID v7, name, client_secret hash SHA-256, organization, scopes[], created_by_id, revoked_at) — `OAuthClient` entity
- [x] Credential generation: `client_id`/`client_secret` random bytes; hash stored; full secret shown **once** on creation
- [x] `OAuthTokenAuthenticator` Symfony Security authenticator (Bearer token) — now exercised against real `/api/v1/*` endpoints; see "Functional tests" below
- [x] Tokens and codes never logged: `SensitiveDataProcessor` covers `token`, `client_secret`, `authorization`, `device_code`, `user_code`, `code_verifier`
- [ ] Rate limiting via Symfony RateLimiter (per client and per org, configurable limits)
- [x] `/api/v1/` route prefix, versioned from day one
- [x] `ApiController` base: standard `{"data": ...}` JSON envelope; `ApiExceptionListener` renders every exception on `/api/*` as RFC 7807 Problem Details
- [ ] **Idempotency-Key middleware**: Symfony event listener on all mutating requests (`POST`, `PATCH`, `DELETE`)
  - Client sends `Idempotency-Key: <uuid4>` header
  - Cache key: `idempotency:{org_id}:{idempotency_key}` in Valkey, TTL 24 hours
  - On hit: return cached response immediately (status code + body), skip handler entirely
  - On miss: execute handler, serialize response to Valkey, return normally
  - Conflicting in-flight requests (same key, concurrent): 409 Conflict
- [x] OAuth client management UI (create, name + set scopes + grants, view client_id, regenerate secret, revoke)
- [x] `AuditLogger` events: client created/updated/deleted/secret regenerated, token issued/revoked, authorization/device-code granted/denied
- [x] Starter endpoints: `GET /api/v1/me` (works for both delegated and Client Credentials tokens, scope-gated fields), `GET /api/v1/organizations` (requires `org:read`)
- [ ] ~~OpenAPI annotations + NelmioApiDocBundle (`/api/docs`)~~ — explicitly out of scope for this template by operator decision
- [x] Functional tests: auth (all 4 grants), invalid client, scope enforcement, token exchange/refresh/revoke/introspect, `/api/v1/me` and `/api/v1/organizations` (401/403/200 paths) — via `OAuthControllerTest`, `AuthorizeControllerTest`, `DeviceVerifyControllerTest`, `MeControllerTest`, `OrganizationsControllerTest`
- [ ] Rate limit (429) and idempotency tests — not applicable until those controls exist

**Branch:** `feat/oauth-developer-area`

---

## Phase 9: Outgoing Webhooks

> Goal: customers register endpoints; events delivered reliably with HMAC signing and retries.

- [x] `WebhookEndpoint` entity (UUID v7, url, secret, display_hint, events[], organization, is_active, created_at)
- [x] `WebhookDelivery` entity (UUID v7, endpoint, event_type, payload JSON, status, attempts, next_attempt_at, last_response_code, last_response_body, created_at)
- [x] Secret: shown once on creation; encrypted at rest (libsodium `sodium_crypto_secretbox`), not hashed — the server must recover the plaintext to compute the HMAC on each delivery
- [x] Signature: `X-Webhook-Signature: sha256=<HMAC-SHA256(secret, body)>`: documented for customers (`docs/WEBHOOKS.md`)
- [x] `WebhookEvent` PHP enum (code-defined catalog of all event types)
- [x] `WebhookDispatcher` service: takes event type + payload, fans out to all matching endpoints via Messenger
- [x] `DeliverWebhookMessageHandler` Messenger handler: sends HTTP POST, logs response, schedules retry on failure
- [x] Retry backoff: 1min, 5min, 30min, 2hr, 12hr (max 5 attempts, configurable)
- [x] Endpoint management UI: create, edit, test (sends sample payload), delivery log per endpoint
- [x] Admin: webhook delivery log across all orgs
- [x] Unit tests: HMAC signing, retry backoff schedule, event fan-out
- [x] Integration tests: delivery handler (mock HTTP client), failure + retry scheduling

**Branch:** `feat/phase-9-webhooks`

---

## Phase 10: Notification System

> Goal: extensible, channel-agnostic notification pipeline. In-app and email from day one; Slack, Discord, SMS addable without core changes.

**Core Infrastructure**
- [ ] `Notification` entity (UUID v7, user, type, channel, title, body, action_url, read_at, created_at): append-only, never updated in place
- [ ] `NotificationPreference` entity (user, notification_type, channel, enabled): per-user per-type per-channel opt-in/out
- [ ] `NotificationChannelInterface`: `supports(string $channel): bool`, `send(Notification $notification): void`
- [ ] `NotificationDispatcher` service: resolves which channels are enabled for user+type, dispatches `SendNotificationMessage` per channel via Messenger
- [ ] `SendNotificationMessage` + `SendNotificationHandler`: writes `Notification` record, delegates to channel driver

**Channel Drivers**
- [ ] `InAppNotificationChannel`: writes to `notification` table; no external call
- [ ] `EmailNotificationChannel`: renders a Twig template and dispatches via `SendMailMessage`
- [ ] Channel registration via Symfony DI tag (`app.notification_channel`): adding Slack/Discord requires implementing the interface and tagging the service

**UI**
- [ ] Notification bell icon in `Topbar` with unread count badge (polled via Turbo Stream every 30s)
- [ ] Notification dropdown: latest 10, "Mark all read", link to full feed
- [ ] Full notification feed page: paginated, filter by type, mark individual/all as read
- [ ] **Notification preferences page** (in user profile settings): per-type toggles per channel (e.g., "Billing alerts" via In-App: on, Email: on, Slack: off)

**Built-in Notification Types**
- [ ] `billing.payment_failed`: email + in-app when invoice fails
- [ ] `billing.trial_expiring`: email + in-app 3 days before trial ends (Scheduler task)
- [ ] `billing.subscription_cancelled`: email + in-app
- [ ] `org.member_invited`: in-app to org admins
- [ ] `org.member_joined`: in-app to org admins
- [ ] `security.new_login`: email on login from new IP/device (opt-in, off by default)
- [ ] `security.session_revoked`: email when a session is remotely revoked

**Tests**
- [ ] Unit: `NotificationDispatcher` channel resolution, preference checking, each channel driver
- [ ] Integration: full dispatch pipeline (in-app persisted, email queued)
- [ ] Functional: unread count in UI, mark as read, preferences save/load
- [ ] Browser smoke test: trigger notification, see bell badge update, mark read

**Branch:** `feat/phase-10-notifications`

---

## Phase 11: Compliance Audit, Polish & Documentation

> Goal: end-to-end compliance verification, hardening pass, production readiness.

**Compliance Verification**
- [ ] Full `AuditLog` coverage audit: every state-mutating action in the system has a log entry
- [ ] Verify no sensitive data appears in any log output (`make test-log-scrubber`)
- [ ] Verify all auth endpoints are rate-limited
- [ ] Verify Stripe webhook signature check cannot be bypassed
- [ ] Verify all admin routes enforce 2FA
- [ ] Verify impersonation session expires correctly at 60 min
- [ ] Verify account lockout triggers and clears correctly
- [ ] Security header audit (run against a headless browser security scanner)
- [ ] `composer audit` passes clean
- [ ] GDPR: verify anonymization pipeline leaves zero PII in user/org tables; audit log UUIDs intact
- [ ] GDPR: verify `DataErasureRequest` records provide audit evidence of completion timestamps

**Production Hardening**
- [ ] Production Docker Compose review (no dev tools, secrets via env, health checks on all services)
- [ ] PostgreSQL: `pgaudit` logging config, connection pooling (PgBouncer consideration)
- [ ] Valkey: password auth, maxmemory config, persistence config
- [ ] CSP policy tightened (remove `unsafe-inline` where possible, add nonces)
- [ ] Review all `TODO` / `FIXME` comments

**Documentation**
- [ ] `README.md`: first-time setup, env vars, Docker commands, ZeptoMail config
- [ ] `docs/compliance.md`: what's covered for SOC2 and PCI, what auditors will ask for, GDPR erasure procedure
- [ ] `docs/deployment.md`: production deployment checklist
- [ ] OpenAPI spec exported to `docs/api/openapi.yaml` and committed
- [ ] `make test` runs full suite clean with coverage report

**Branch:** `feat/phase-11-polish`

---

## Deferred / Future Phases

Intentionally out of scope for the template, but designed to be easy to add:

- **SSO / OAuth login**: `knpuniversity/oauth2-client-bundle` (GitHub, Google)
- **Additional notification channels**: Slack and Discord drivers implement `NotificationChannelInterface` — no core changes needed (Phase 10 architecture supports this)
- **Mobile push notifications**: APNs/FCM via another `NotificationChannelInterface` implementation
- **Usage metering**: hook into `EntitlementService` check layer
- **Feature flags beyond entitlements**: simple `FeatureFlag` entity or LaunchDarkly
- **Multi-region / sharding**: `organization_id` is the shard key; add Citus when needed
- **Audit log SIEM export**: Monolog JSON is already SIEM-ready; add a forwarder
- **SOC2 Type II evidence collection tooling**
- **GDPR: data portability (Article 20)**: export all user data as a zip archive

---

## Conventions

- **Branches:** `feat/phase-N-description`, `fix/short-description`, `chore/short-description`
- **Commits:** Conventional Commits: `feat:`, `fix:`, `chore:`, `test:`, `refactor:`, `docs:`, `ci:`
- **PRs:** One per phase/sub-phase. Must pass `make test`. Must include browser smoke test sign-off in PR description.
- **IDs:** UUID v7 on all entities, no auto-increment integers
- **Async:** All side effects (emails, webhook delivery, Stripe events, notifications) go through Symfony Messenger
- **Permissions:** Always use Symfony Security voters: never inline role checks in controllers
- **Audit logging:** Every state-mutating action calls `AuditLogger`. No exceptions.
- **Sensitive data:** Never log passwords, tokens, secrets, or card data. `SensitiveDataProcessor` is the safety net, not the first line of defence.
- **PCI:** Never store raw card numbers, CVVs, or magnetic stripe data. Stripe tokens only.
- **Entitlements:** Always check via `EntitlementService` (Valkey-cached). Never query `PlanEntitlement` directly in controllers.
- **Notifications:** Never send notifications synchronously. Always dispatch via `NotificationDispatcher` which routes through Messenger.
- **GDPR:** Never hard-delete users or organizations. Always anonymize via the pipeline.
- **API idempotency:** All mutating API handlers must be safe to execute multiple times; the idempotency middleware handles deduplication at the transport layer.
