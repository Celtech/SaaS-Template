# SaaS Template: Project Roadmap

> This document is the source of truth for the full build plan. Update it as phases complete and decisions evolve.

---

## Tech Stack

| Layer | Choice | Why |
|---|---|---|
| Language / Framework | PHP 8.3 + Symfony 8 |: |
| App Server | FrankenPHP (worker mode) | Built-in Caddy, no nginx, Symfony-recommended, better throughput |
| Database | PostgreSQL | RLS for tenant isolation, pgaudit (SOC2), JSONB, UUID native, Citus sharding path |
| ORM | Doctrine ORM |: |
| Cache / Queue transport | Valkey | OSS Redis fork (Redis went SSPL 2024), wire-compatible |
| Async | Symfony Messenger | Webhook delivery, emails, subscription events |
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
Every user gets a personal `Organization` on signup (`type: personal`). All resources (subscriptions, API keys, webhooks) belong to an `Organization`. Converting personal → team is a first-class operation. `organization_id` is the shard key on all tenant data tables.

### RBAC
One engine, two namespaces (`admin.*`, `org.*`). Permissions are code-defined (PHP enum). Roles, permission-to-role assignments, and user-to-role assignments are all DB-managed and editable via admin UI. Default roles are seeded on install.

### Impersonation (SOC2 / PCI Compliant)
Symfony `switch_user`: no credential access. Immutable `ImpersonationSession` audit record. Hard 60-min expiry. Payment fields masked during session. Persistent UI banner. Email notification configurable (off by default, `admin.system.configure` required to change).

### Billing Configuration
`BillingSettings` singleton in DB. Admin-editable. Controls: trial enabled/days, free tier enabled/plan, trial expiry behavior (`require_payment` | `downgrade_to_free` | `cancel`). Default: 14-day trial, no free tier, `require_payment` on expiry.

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
- Custom Monolog processor scrubs all sensitive fields (passwords, tokens, secrets, Authorization headers) before any log entry is written: no exceptions on auth endpoints
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

**App Server & Containers**
- [ ] Symfony 8 project scaffold, FrankenPHP Dockerfile (multi-stage: dev + prod)
- [ ] Docker Compose dev: app (FrankenPHP), postgres, valkey, mailpit, worker
- [ ] Docker Compose prod variant: prod FrankenPHP, no dev tools, health checks, restart policies
- [ ] Makefile: `make up`, `make down`, `make migrate`, `make test`, `make worker`, `make shell`, `make audit`

**Database**
- [ ] PostgreSQL container, Doctrine configured, UUID v7 strategy set as global default
- [ ] Migrations workflow: `make migrate`, `make migration` (generate), rollback documented
- [ ] `pgaudit` extension enabled in dev and prod Postgres config

**Async & Scheduling**
- [ ] Valkey container, Symfony Messenger configured (async transport for all side effects)
- [ ] Symfony Scheduler configured with worker (runs alongside Messenger worker)
- [ ] `Makefile` worker command starts both Messenger consumer and Scheduler

**Frontend**
- [ ] Tailwind v4 via Asset Mapper (no separate Node build step)
- [ ] CSS custom property theming layer: full shadcn-compatible palette (slate, primary, secondary, destructive, muted, accent, background, foreground, border, ring, radius)
- [ ] Twig component library scaffold (`/templates/components/`):
  - Layout: `AppShell`, `Sidebar`, `Topbar`, `PageHeader`
  - Primitives: `Button`, `Input`, `Textarea`, `Select`, `Checkbox`, `Radio`, `Label`, `Switch`
  - Display: `Card`, `Badge`, `Alert`, `Avatar`, `Separator`, `Spinner`
  - Overlays: `Dialog`, `Dropdown`, `Tooltip`, `Sheet` (slide-over panel)
  - Data: `Table`, `Pagination`, `EmptyState`
  - Forms: `FormGroup`, `FormError`, `FormRow`
- [ ] Base layouts: `base.html.twig`, `app.html.twig`, `auth.html.twig`, `admin.html.twig`
- [ ] Stimulus controllers: modal, dropdown, toast, confirm-dialog, copy-to-clipboard, character-counter

**Compliance Infrastructure (SOC2 / PCI: in from day one)**
- [ ] `AuditLog` entity (UUID v7, actor_id, actor_type, action, subject_id, subject_type, old_value JSON, new_value JSON, ip_address, user_agent, created_at): append-only, no update/delete in repository
- [ ] `AuditLogger` service: typed methods: `logAuth()`, `logAdminAction()`, `logImpersonation()`, `logBillingEvent()`, `logSecurityEvent()`
- [ ] Monolog JSON formatter on all handlers (structured logs for SIEM)
- [ ] `SensitiveDataProcessor` Monolog processor: scrubs: `password`, `token`, `secret`, `api_key`, `authorization`, `card`, `cvv`, `ssn` from all log context before write
- [ ] Security headers response listener: `Strict-Transport-Security`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Content-Security-Policy` (strict baseline)
- [ ] Health check endpoints: `GET /health` (liveness), `GET /health/ready` (readiness: DB + Valkey ping)
- [ ] `composer audit` in Makefile (`make audit`) and documented as required CI check

**Testing**
- [ ] PHPUnit configured, `phpunit.xml` with test suites (unit, integration, functional)
- [ ] Base test case classes: `UnitTestCase`, `IntegrationTestCase` (boots kernel), `FunctionalTestCase` (HTTP client)
- [ ] Database test trait: transaction rollback between tests
- [ ] ZeptoMail SMTP + Mailpit dev config, `.env.example` with all required vars documented

**Branch:** `feat/phase-1-foundation`

---

## Phase 2: Auth & Users

> Goal: users can register, verify email, log in, reset password. All auth events audited.

**Entities**
- [ ] `User` entity (UUID v7, email, password_hash: Argon2id, name, avatar_url, email_verified_at, failed_login_count, locked_until, created_at, updated_at)

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
- [ ] `AuditLogger::logAuth('login_success', ...)` and `logAuth('login_failed', ...)` on every attempt

**Account Lockout (SOC2)**
- [ ] `SecuritySettings` singleton entity (admin-editable): `max_failed_attempts` (default: 5), `lockout_duration_minutes` (default: 30)
- [ ] `LoginFailureHandler` increments `User::failedLoginCount`; sets `User::lockedUntil` when threshold reached
- [ ] `LoginSuccessHandler` resets `User::failedLoginCount`
- [ ] `AuditLogger::logSecurityEvent('account_locked', ...)` when lockout triggers
- [ ] Locked account shows clear error with unlock instructions (not a generic "invalid credentials")

**Password Reset**
- [ ] Forgot password form → signed token email → new password form → token invalidated
- [ ] Tokens single-use, 1hr expiry
- [ ] `AuditLogger::logAuth('password_reset_requested', ...)` and `logAuth('password_reset_completed', ...)`

**2FA Scaffold (enforced for admin in Phase 7a)**
- [ ] `scheb/2fa-bundle` installed and configured
- [ ] TOTP (Google Authenticator compatible) as the 2FA method
- [ ] `User::totpSecret`, `User::totpEnabled` fields
- [ ] 2FA setup flow (QR code, verify code, backup codes)
- [ ] 2FA optional for regular users, flag to enforce per-role (used in Phase 7a for admin)

**Tests**
- [ ] Unit: password strength constraints, account lockout logic, lockout expiry
- [ ] Functional: full registration flow, email verification, login, failed login lockout, password reset
- [ ] Functional: session fixation prevention (session ID changes post-login)
- [ ] Browser smoke test: register → verify → login → logout

**Branch:** `feat/phase-2-auth`

---

## Phase 3: Organizations & Teams

> Goal: users can create/join team orgs, invite members, manage membership.

- [ ] `Organization` entity (UUID v7, name, slug, type: personal|team, stripe_customer_id, created_at)
- [ ] `OrganizationMember` entity (org, user, joined_at): role resolved via RBAC (Phase 4)
- [ ] `OrganizationInvite` entity (UUID v7, org, email, token_hash, role, expires_at, accepted_at)
- [ ] Org creation form (name, slug auto-generated + editable, uniqueness validated)
- [ ] Email invite: signed token, 7-day expiry, accept/decline pages
- [ ] Org context switcher in navigation (personal org vs team orgs)
- [ ] Org settings page (name, slug, danger zone: transfer ownership, delete org)
- [ ] Member list page (member, role, joined date, remove action)
- [ ] `AuditLogger` events: org_created, member_invited, invite_accepted, member_removed, org_deleted
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
- [ ] `EntitlementService`: `check(string $key): bool`, `getValue(string $key): int|bool|null`, resolves via current org's active subscription
- [ ] Twig extension: `entitlement_check('key')`, `entitlement_value('key')`
- [ ] `SubscriptionService`: create, upgrade, downgrade, cancel, reactivate
- [ ] `StripeWebhookHandler`: validates signature, routes events to Messenger messages

**Stripe Integration**
- [ ] Stripe Checkout session creation (new subscription, trial)
- [ ] Stripe Customer Portal redirect (self-service changes)
- [ ] Webhook endpoint `POST /stripe/webhook`: **signature verified on every request** (PCI requirement)
- [ ] Messenger handlers for: `subscription.created`, `subscription.updated`, `subscription.deleted`, `invoice.payment_succeeded`, `invoice.payment_failed`
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
- [ ] Unit: `EntitlementService`, trial expiry logic, seat enforcement
- [ ] Integration: Stripe webhook handler with fixture payloads (each event type)
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
- [ ] Org list + detail: members, subscription, webhook endpoints
- [ ] Impersonation:
  - Start impersonation → `ImpersonationSession` record created (actor, target, IP, user agent, started_at)
  - All actions during session tagged to impersonation session in audit log
  - Persistent banner: "Viewing as [User Name]: [End Impersonation]"
  - Hard 60-min expiry (Scheduler task checks and terminates)
  - End impersonation → `ImpersonationSession::ended_at` set
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

**Branch:** `feat/phase-7d-admin-rbac`

---

## Phase 8: Public API (v1)

> Goal: documented, versioned, authenticated REST API.

- [ ] `ApiKey` entity (UUID v7, name, key_hash SHA-256, key_prefix, organization, scopes[], last_used_at, expires_at, created_by_id, revoked_at)
- [ ] Key generation: prefix `sk_live_` + 32 random bytes; hash stored; full key shown **once** on creation
- [ ] `ApiKeyAuthenticator` Symfony Security authenticator (Bearer token or `X-API-Key` header)
- [ ] API key never logged: `SensitiveDataProcessor` covers `Authorization` and `X-API-Key` headers
- [ ] Rate limiting via Symfony RateLimiter (per key and per org, configurable limits)
- [ ] `/api/v1/` route prefix, versioned from day one
- [ ] `ApiController` base: standard JSON envelope, RFC 7807 Problem Details for errors
- [ ] API key management UI (create, name + set scopes, view prefix/last4, revoke)
- [ ] `AuditLogger` events: api_key_created, api_key_revoked
- [ ] Starter endpoints: `GET /api/v1/me`, `GET /api/v1/organizations`
- [ ] OpenAPI annotations + NelmioApiDocBundle (`/api/docs`)
- [ ] Functional tests: auth, invalid key, expired key, rate limit (429), scope enforcement (403)

**Branch:** `feat/phase-8-api`

---

## Phase 9: Outgoing Webhooks

> Goal: customers register endpoints; events delivered reliably with HMAC signing and retries.

- [ ] `WebhookEndpoint` entity (UUID v7, url, secret_hash, display_hint, events[], organization, is_active, created_at)
- [ ] `WebhookDelivery` entity (UUID v7, endpoint, event_type, payload JSON, status, attempts, next_attempt_at, last_response_code, last_response_body, created_at)
- [ ] Secret: shown once on creation, stored as HMAC key (hashed for storage, used for signing)
- [ ] Signature: `X-Webhook-Signature: sha256=<HMAC-SHA256(secret, body)>`: documented for customers
- [ ] `WebhookEvent` PHP enum (code-defined catalog of all event types)
- [ ] `WebhookDispatcher` service: takes event type + payload, fans out to all matching endpoints via Messenger
- [ ] `WebhookDeliveryHandler` Messenger handler: sends HTTP POST, logs response, schedules retry on failure
- [ ] Retry backoff: 1min, 5min, 30min, 2hr, 12hr (max 5 attempts, configurable)
- [ ] Endpoint management UI: create, edit, test (sends sample payload), delivery log per endpoint
- [ ] Admin: webhook delivery log across all orgs
- [ ] Unit tests: HMAC signing, retry backoff schedule, event fan-out
- [ ] Integration tests: delivery handler (mock HTTP client), failure + retry scheduling

**Branch:** `feat/phase-9-webhooks`

---

## Phase 10: Compliance Audit, Polish & Documentation

> Goal: end-to-end compliance verification, hardening pass, production readiness.

**Compliance Verification**
- [ ] Full `AuditLog` coverage audit: every state-mutating action in the system has a log entry
- [ ] Verify no sensitive data appears in any log output (`make test-log-scrubber`)
- [ ] Verify all auth endpoints are rate-limited
- [ ] Verify Stripe webhook signature check can't be bypassed
- [ ] Verify all admin routes enforce 2FA
- [ ] Verify impersonation session expires correctly at 60 min
- [ ] Verify account lockout triggers and clears correctly
- [ ] Security header audit (run against a headless browser security scanner)
- [ ] `composer audit` passes clean

**Production Hardening**
- [ ] Production Docker Compose review (no dev tools, secrets via env, health checks on all services)
- [ ] PostgreSQL: `pgaudit` logging config, connection pooling (PgBouncer consideration)
- [ ] Valkey: password auth, maxmemory config, persistence config
- [ ] CSP policy tightened (remove `unsafe-inline` where possible, add nonces)
- [ ] Review all `TODO` / `FIXME` comments

**Documentation**
- [ ] `README.md`: first-time setup, env vars, Docker commands, ZeptoMail config
- [ ] `docs/compliance.md`: what's covered for SOC2 and PCI, what auditors will ask for
- [ ] `docs/deployment.md`: production deployment checklist
- [ ] OpenAPI spec exported to `docs/api/openapi.yaml` and committed
- [ ] `make test` runs full suite clean with coverage report

**Branch:** `feat/phase-10-polish`

---

## Deferred / Future Phases

Intentionally out of scope for the template, but designed to be easy to add:

- **SSO / OAuth login**: `knpuniversity/oauth2-client-bundle` (GitHub, Google)
- **Usage metering**: hook into `EntitlementService` check layer
- **Feature flags beyond entitlements**: simple `FeatureFlag` entity or LaunchDarkly
- **Multi-region / sharding**: `organization_id` is the shard key; add Citus when needed
- **Audit log SIEM export**: Monolog JSON is already SIEM-ready; add a forwarder
- **Mobile push notifications**
- **SOC2 Type II evidence collection tooling**

---

## Conventions

- **Branches:** `feat/phase-N-description`, `fix/short-description`, `chore/short-description`
- **Commits:** Conventional Commits: `feat:`, `fix:`, `chore:`, `test:`, `refactor:`, `docs:`, `ci:`
- **PRs:** One per phase/sub-phase. Must pass `make test`. Must include browser smoke test sign-off in PR description.
- **IDs:** UUID v7 on all entities, no auto-increment integers
- **Async:** All side effects (emails, webhook delivery, Stripe events) go through Symfony Messenger
- **Permissions:** Always use Symfony Security voters: never inline role checks in controllers
- **Audit logging:** Every state-mutating action calls `AuditLogger`. No exceptions.
- **Sensitive data:** Never log passwords, tokens, secrets, or card data. `SensitiveDataProcessor` is the safety net, not the first line of defence.
- **PCI:** Never store raw card numbers, CVVs, or magnetic stripe data. Stripe tokens only.
