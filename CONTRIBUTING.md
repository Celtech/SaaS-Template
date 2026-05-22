# Contributing & Developer Guide

This document covers everything you need to develop, test, and review changes to this project.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Local Setup](#local-setup)
3. [Project Structure](#project-structure)
4. [Environment Variables](#environment-variables)
5. [Daily Development Workflow](#daily-development-workflow)
6. [Database Migrations](#database-migrations)
7. [Running Tests](#running-tests)
8. [Code Standards](#code-standards)
9. [Branching & Commit Conventions](#branching--commit-conventions)
10. [Merge Request Review Checklist](#merge-request-review-checklist)

---

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) 4.x+
- [Make](https://www.gnu.org/software/make/) (pre-installed on macOS/Linux; Windows: use WSL2)
- No PHP, Composer, or Node installation required: everything runs inside Docker

---

## Local Setup

```bash
# 1. Clone the repository
git clone <repo-url> saas-template && cd saas-template

# 2. Copy environment file and fill in required values
cp .env.example .env

# 3. Start all services (builds containers on first run: takes ~2 minutes)
make up

# 4. Run database migrations and seed default data
make migrate
make seed
```

Visit **[https://localhost](https://localhost)**. Accept the self-signed certificate on first run (Caddy generates a local dev cert).

Default Super Admin credentials are printed to the console during `make seed`. Change them immediately.

### Available Services

| Service | URL / Port |
|---|---|
| Application | https://localhost |
| Mailpit (email catcher) | http://localhost:8025 |
| Adminer (DB UI) | http://localhost:8080 |
| PostgreSQL | localhost:5432 |
| Valkey | localhost:6379 |

---

## Project Structure

```
saas-template/
├── config/                   # Symfony configuration
│   ├── packages/             # Bundle configuration (doctrine, messenger, scheduler, security, etc.)
│   └── routes/               # Route imports
├── docker/                   # Dockerfiles and service configs
│   ├── development/
│   └── production/
├── docs/                     # Extended documentation
│   ├── api/                  # OpenAPI spec (generated)
│   ├── compliance.md         # Detailed compliance notes for auditors
│   └── deployment.md         # Production deployment checklist
├── migrations/               # Doctrine migrations (never edit manually)
├── public/                   # Web root: index.php + compiled assets
├── src/
│   ├── Controller/           # HTTP layer only: no business logic
│   │   ├── Admin/
│   │   ├── Api/V1/
│   │   ├── Auth/
│   │   ├── Billing/
│   │   ├── Dashboard/
│   │   ├── Onboarding/
│   │   └── Webhook/          # Stripe inbound webhook endpoint
│   ├── Entity/               # Doctrine entities
│   ├── Enum/                 # PHP enums (Permission, EntitlementType, SubscriptionStatus, etc.)
│   ├── EventListener/        # Symfony event listeners / subscribers
│   ├── Form/                 # Symfony form types
│   ├── Message/              # Messenger message DTOs
│   ├── MessageHandler/       # Messenger handlers (async work)
│   ├── Repository/           # Doctrine repositories
│   ├── Schedule/             # Symfony Scheduler tasks (recurring jobs)
│   ├── Security/             # Voters, authenticators
│   ├── Service/              # Domain logic
│   │   ├── Audit/            # AuditLogger
│   │   ├── Billing/          # StripeService, EntitlementService, SubscriptionService
│   │   ├── Auth/             # LoginHandler, ImpersonationService
│   │   └── Webhook/          # WebhookDispatcher, WebhookDeliveryHandler
│   └── Twig/                 # Twig extensions and runtime
├── templates/
│   ├── components/           # Reusable Twig components (shadcn-inspired)
│   ├── layouts/              # Base layout templates
│   ├── admin/
│   ├── auth/
│   ├── billing/
│   ├── dashboard/
│   └── onboarding/
├── tests/
│   ├── Unit/                 # Pure unit tests (no kernel, no DB)
│   ├── Integration/          # Tests that boot kernel and hit DB
│   └── Functional/           # HTTP-level tests via Symfony test client
├── .env.example
├── docker-compose.yml        # Development
├── docker-compose.prod.yml   # Production
├── Makefile
├── ROADMAP.md
└── COMPLIANCE.md
```

---

## Environment Variables

All variables are documented in `.env.example`. Required variables that have no sensible default are marked `REQUIRED`.

| Variable | Description |
|---|---|
| `APP_ENV` | `dev` or `prod` |
| `APP_SECRET` | Symfony app secret: generate with `make secret` |
| `APP_NAME` | Application name shown in 2FA authenticator apps and emails |
| `DATABASE_URL` | PostgreSQL DSN |
| `MESSENGER_TRANSPORT_DSN` | Valkey DSN for Symfony Messenger |
| `MAILER_DSN` | ZeptoMail SMTP DSN (see below) |
| `STRIPE_SECRET_KEY` | Stripe secret key (`sk_live_` or `sk_test_`) |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret (`whsec_`) |
| `STRIPE_PUBLISHABLE_KEY` | Stripe publishable key |

**ZeptoMail DSN format:**
```
MAILER_DSN=smtp://your-smtp-token@smtppro.zoho.in:587
```

---

## Daily Development Workflow

```bash
make up          # Start all containers (detached)
make down        # Stop all containers
make restart     # Restart app container only
make shell       # Open a shell in the app container
make logs        # Tail application logs
make worker      # Start Messenger consumer + Scheduler worker (runs in foreground)
make migrate     # Run pending migrations
make migration   # Generate a new migration from entity changes
make test        # Run the full test suite
make audit       # Run composer security audit
make cs          # Run PHP-CS-Fixer (check only)
make cs-fix      # Run PHP-CS-Fixer (auto-fix)
make stan        # Run PHPStan static analysis
```

---

## Database Migrations

**Never edit a migration file after it has been committed and run**, even in development. Always generate a new migration.

```bash
# After changing an Entity, generate the migration
make migration

# Review the generated file in migrations/ before running
make migrate
```

Migrations must always implement `down()`. A migration that cannot be safely reversed must include a comment explaining why and must be flagged in the PR for extra review.

**Never use `--force` in production without reading the migration first.**

---

## Running Tests

### Full suite

```bash
make test
```

This runs unit, integration, and functional suites in order. All tests must pass before a PR can be merged.

### Individual suites

```bash
# Unit tests only (fast: no kernel, no DB)
make test-unit

# Integration tests (boots kernel, uses a test database)
make test-integration

# Functional / HTTP tests
make test-functional

# Single test file or test case
make shell
php bin/phpunit tests/Unit/Service/Billing/EntitlementServiceTest.php
php bin/phpunit --filter testAccountLockoutTriggersAfterMaxAttempts
```

### Test database

Integration and functional tests use a separate `saas_template_test` database. It is created automatically by `make test`. Each test case wraps DB operations in a transaction and rolls back, so tests are fully isolated and order-independent.

### Coverage report

```bash
make test-coverage
# Opens coverage/index.html in your browser
```

### Browser smoke testing

Before marking a PR ready for review, manually verify the changed feature in the browser:

1. Run `make up` and navigate to the affected pages
2. Open DevTools → Console: **zero errors is required**
3. Open DevTools → Network: check for unexpected 4xx/5xx responses
4. Test the golden path (happy path)
5. Test at least one meaningful edge case or error state
6. Test on a narrow viewport (mobile breakpoint)
7. Document what you tested in the PR description under **"Browser smoke test"**

---

## Code Standards

### PHP

- **PHP-CS-Fixer** enforces PSR-12 + Symfony coding standards. Run `make cs-fix` before committing.
- **PHPStan** at level 8. Run `make stan`. PRs must not introduce new Stan errors.
- No logic in controllers: controllers orchestrate, services do the work.
- No inline role/permission checks: always use Symfony Security voters.
- No service locator pattern: always use constructor injection.

### Symfony conventions

- **Forms:** use Symfony Form types for all user input.
- **Validation:** use Symfony Validator constraints on DTOs and entities.
- **Events:** use Symfony EventDispatcher for cross-cutting concerns.
- **Async:** all side effects (emails, webhook delivery, Stripe events) go through Symfony Messenger.
- **Scheduled work:** all recurring tasks go through Symfony Scheduler. No manual cron wiring.
- **Routing:** use PHP attributes (`#[Route]`): no YAML/XML route files.

### Entities

- UUID v7 on every entity: no auto-increment integers.
- Every entity that can be mutated must be covered by `AuditLogger` in its service layer.
- Repository classes must not expose `delete()` for the `AuditLog` and `ImpersonationSession` entities.

### Frontend

- Use existing Twig components from `templates/components/`: do not inline one-off styles.
- New interactive behaviour goes in a Stimulus controller in `assets/controllers/`.
- No `<script>` tags inline in templates.

### Sensitive data

- Never pass passwords, tokens, API keys, or secrets to any logger.
- The `SensitiveDataProcessor` Monolog processor is a safety net, not the first line of defence.
- PCI: never store card numbers, CVVs, or full PANs: Stripe tokens only.

---

## Branching & Commit Conventions

### Branches

```
feat/phase-N-short-description    # New feature (aligned to a roadmap phase)
fix/short-description              # Bug fix
chore/short-description            # Non-functional change (deps, config, tooling)
docs/short-description             # Documentation only
refactor/short-description         # Refactor with no behaviour change
test/short-description             # Tests only
```

Always branch from `main`. PRs target `main`.

### Commit messages: Conventional Commits

```
feat: add account lockout after configurable failed login attempts
fix: prevent session fixation on login by regenerating session ID
chore: upgrade stripe/stripe-php to 13.x
test: add functional tests for Stripe webhook signature verification
refactor: extract SubscriptionService from BillingController
docs: document ZeptoMail SMTP configuration in CONTRIBUTING
ci: add composer audit to PR workflow
```

- Present tense, imperative mood ("add", not "added" or "adds")
- No period at the end
- Body (optional): explain *why*, not *what*: the diff shows what

---

## Merge Request Review Checklist

This checklist is **mandatory** for every reviewer. Work through it in order. Do not approve a PR with unchecked items unless you have explicitly documented why an item does not apply and got agreement from the author.

---

### 1. Scope & Intent

- [ ] The PR description clearly explains what changed and why
- [ ] The changes are scoped to a single logical unit (one phase or one fix)
- [ ] No unrelated changes are bundled in (stray refactors, style fixes in unrelated files)
- [ ] The branch name and commit messages follow conventions

---

### 2. Automated Checks

Run these before reading a single line of diff:

```bash
# Pull the branch locally
git fetch origin && git checkout <branch>

# Run the full test suite
make test

# Run static analysis
make stan

# Run security audit
make audit

# Run code style check
make cs
```

- [ ] `make test` passes: all suites, zero failures, zero errors
- [ ] `make stan` passes: no new PHPStan errors at level 8
- [ ] `make audit` passes: no known vulnerable dependencies
- [ ] `make cs` passes: no code style violations

**Stop here if any automated check fails. Do not proceed with manual review until they pass.**

---

### 3. Architecture & Symfony Best Practices

- [ ] Controllers contain no business logic: they orchestrate calls to services
- [ ] No inline permission/role checks: `$this->denyAccessUnlessGranted()` or voters only
- [ ] No service locator pattern (`$container->get(...)`): constructor injection only
- [ ] New async work goes through Symfony Messenger (no synchronous side effects in controllers)
- [ ] New recurring tasks use Symfony Scheduler (no ad-hoc cron instructions)
- [ ] Form types used for all user-facing input
- [ ] Symfony Validator constraints used for validation (not manual `if` checks on input)
- [ ] Routing via PHP attributes: no YAML/XML route files introduced

---

### 4. Database & Entities

- [ ] All new entities use UUID v7: no auto-increment IDs
- [ ] New migration generated (not manual SQL): `migrations/` file present
- [ ] Migration `down()` method is implemented and correct
- [ ] Migration does not add a NOT NULL column to a large table without a default or a batched backfill strategy
- [ ] Foreign keys have explicit `ON DELETE` behaviour (`CASCADE`, `SET NULL`, or `RESTRICT`: never silent)
- [ ] Indexes added for columns used in `WHERE`, `ORDER BY`, or `JOIN` conditions
- [ ] No raw SQL in services: Doctrine QueryBuilder or DQL only (unless a complex report query with documented justification)

---

### 5. Security

- [ ] New routes are covered by the appropriate security voter or access attribute
- [ ] User-supplied input is validated before use (Symfony constraints, not manual filtering)
- [ ] No new `eval()`, `shell_exec()`, `exec()`, `system()` calls
- [ ] No dynamic query construction with unescaped user input (SQL injection)
- [ ] No output of user data without Twig's auto-escaping (XSS): if `|raw` is used, it is explicitly justified
- [ ] No hardcoded secrets, credentials, or API keys in code or config files
- [ ] CSRF protection present on all state-mutating forms (`{{ csrf_token() }}` or Symfony form component)
- [ ] File uploads (if any) validate MIME type server-side and store outside the web root

---

### 6. Compliance: SOC2

- [ ] **Every state-mutating operation** in this PR has a corresponding `AuditLogger` call (auth events, admin mutations, billing changes, permission changes)
- [ ] `AuditLog` and `ImpersonationSession` repositories do not expose any delete or update methods in new code
- [ ] Audit log entries capture: actor, action, subject, old value (where applicable), new value, IP, user agent
- [ ] Admin-only operations are gated to the appropriate `admin.*` permission via voter
- [ ] Super-admin-only operations (system config, admin account management, impersonation notification toggle) are gated to `admin.system.configure` or `admin.admins.manage`
- [ ] Any new admin action that can be performed during an impersonation session is tagged with the `impersonation_session_id` in the audit log
- [ ] Session timeout is enforced for any new admin-facing session mechanism
- [ ] 2FA enforcement is not bypassed by any new route under `/admin`

---

### 7. Compliance: PCI DSS

- [ ] No card numbers, CVVs, expiry dates, or full PANs are stored anywhere (database, logs, cache, session)
- [ ] Stripe webhook handler verifies the `Stripe-Signature` header before processing any payload
- [ ] Stripe tokens (payment method IDs, customer IDs) are the only payment identifiers stored
- [ ] Payment-related fields in the UI are masked during admin impersonation sessions
- [ ] No payment-related data is written to application logs (verified by inspecting logger calls in changed files)

---

### 8. Sensitive Data & Logging

- [ ] No passwords, plaintext tokens, API keys, or secrets are passed to any logger or exception handler
- [ ] New log entries do not include request bodies from auth endpoints (registration, login, password reset)
- [ ] If a new field is sensitive, its key name is added to `SensitiveDataProcessor::SCRUBBED_KEYS`
- [ ] Exception messages that could contain sensitive data are caught and sanitised before logging

---

### 9. API (if applicable)

- [ ] New API endpoints are under `/api/v1/` and use the `ApiController` base
- [ ] Authentication is enforced via `ApiKeyAuthenticator`: no unauthenticated endpoints unless explicitly a public endpoint (documented in PR)
- [ ] Rate limiting is applied to new endpoints
- [ ] Error responses follow RFC 7807 Problem Details format
- [ ] New endpoints have OpenAPI annotations
- [ ] Scope enforcement tested (403 when key lacks required scope)

---

### 10. Webhooks (if applicable)

- [ ] New webhook events are added to the `WebhookEvent` PHP enum
- [ ] `WebhookDispatcher` is used to dispatch outbound events (not direct HTTP calls)
- [ ] Stripe inbound webhook processing is done via Messenger handler (async), not synchronously in the controller
- [ ] Webhook secrets are never logged or exposed in responses

---

### 11. Frontend

- [ ] Uses existing Twig components from `templates/components/`: no one-off inline component reimplementations
- [ ] No inline `<script>` tags in templates: behaviour goes in Stimulus controllers
- [ ] All form fields have associated `<label>` elements
- [ ] Interactive elements are keyboard-accessible
- [ ] New Stimulus controllers are scoped and clean up event listeners in `disconnect()`

---

### 12. Browser Smoke Test (reviewer performs independently)

The author documents what they tested. The reviewer must also verify in a browser:

```bash
git checkout <branch>
make up
make migrate
```

- [ ] Navigate to all pages touched by this PR: zero console errors
- [ ] Perform the primary user action introduced or modified by this PR
- [ ] Check at least one error/edge case (invalid form input, 403 page, etc.)
- [ ] Check on a narrow viewport (resize browser to ~375px width)
- [ ] Network tab shows no unexpected 4xx or 5xx responses during normal use

---

### 13. Tests

- [ ] Unit tests cover all new service/domain logic: not just the happy path
- [ ] Functional tests cover all new controller routes (success response, auth-required 401/403, validation error 422)
- [ ] Tests do not rely on execution order or shared mutable state
- [ ] No `@todo` or skipped tests introduced without a linked issue explaining why
- [ ] Test method names describe the scenario, not the implementation (`testAccountLocksAfterFiveFailedAttempts`, not `testLoginHandler`)

---

### 14. Final Sign-off

- [ ] I have run all automated checks locally and they pass
- [ ] I have performed the browser smoke test
- [ ] I am satisfied that this PR does not introduce a compliance gap
- [ ] I am satisfied that this PR does not introduce a security vulnerability
- [ ] I approve this PR for merge
