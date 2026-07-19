# Compliance Documentation

This document describes the compliance posture of this SaaS template, what controls are built into the application layer, and what responsibilities remain with the operator (you, the person deploying this template) to achieve and maintain certification.

**Important:** No software template (including this one) can certify you for SOC2 or PCI DSS on its own. Certification requires a licensed third-party auditor (SOC2) or a Qualified Security Assessor (PCI DSS Level 1) or a completed self-assessment questionnaire (PCI DSS SAQ). This document helps you understand what ground the application already covers and what you still need to do.

---

## SOC2

### Overview

SOC2 (System and Organisation Controls 2) is an auditing standard developed by the AICPA. It evaluates controls relevant to five Trust Service Criteria (TSC). For most SaaS companies the mandatory criterion is **Security**, with **Confidentiality** and **Availability** commonly included.

This template is designed to satisfy the technical control requirements within those three criteria. Organisational, policy, and procedural controls are the operator's responsibility.

---

### What this application provides

#### Security (CC6: Logical and Physical Access)

| Control | Implementation |
|---|---|
| Least-privilege access | Dynamic RBAC system; users have only the permissions their role grants; admin and org contexts are separated |
| Multi-factor authentication | 2FA/TOTP enforced for all admin accounts (`scheb/2fa-bundle`); optional for end users |
| Account lockout | Configurable max failed login attempts (default: 5) and lockout duration (default: 30 min) |
| Session management | Session ID regenerated on login (prevents session fixation); configurable idle timeout (15 min for admin, 2 hr for users); `Secure`, `HttpOnly`, `SameSite=Strict` cookies |
| Audit logging | Immutable, append-only `AuditLog` covering: all authentication events (success, failure, logout), all admin mutations (with before/after values), all impersonation sessions and actions taken during them, all permission and role changes, all billing and subscription events, OAuth client/token lifecycle (client created/deleted, token issued/revoked, authorization and device-code grant/deny) and outgoing webhook endpoint mutations |
| Privileged access monitoring | All admin actions are logged with actor identity, IP, and user agent; impersonation sessions are separately tracked with hard 60-minute expiry |
| Separation of duties | Super Admin role required for system configuration and admin account management; support-level admins have read-only access |
| Transmission encryption | TLS enforced by Caddy; HSTS header ensures browsers never connect over HTTP; HTTP/3 supported |
| Security headers | `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` on all responses |
| Vulnerability management | `composer audit` detects known vulnerable dependencies; run on every CI build |
| Dependency integrity | `composer.lock` committed; deterministic installs |

#### Confidentiality (C1: Information Classification and Protection)

| Control | Implementation |
|---|---|
| Password storage | Argon2id hashing (Symfony default; compliant with NIST SP 800-63B) |
| OAuth client & token storage | Client secrets and access/refresh tokens stored as SHA-256 hash; plaintext shown once on generation and never persisted |
| Webhook secret storage | Encrypted at rest (libsodium `sodium_crypto_secretbox`), not one-way hashed — the server must recover the plaintext secret to recompute the HMAC-SHA256 signature on every delivery attempt; shown once on creation |
| Sensitive data scrubbing | Monolog `SensitiveDataProcessor` removes passwords, tokens, secrets, and `Authorization` headers from all log output before writing |
| Payment data | Stripe Checkout and Customer Portal handle all card data; no card numbers, CVVs, or full PANs ever reach the application (see PCI section) |
| Impersonation data isolation | Payment fields display as masked during admin impersonation sessions |

#### Availability (A1: Performance Monitoring and Availability)

| Control | Implementation |
|---|---|
| Health checks | `GET /health` (liveness) and `GET /health/ready` (readiness: database + Valkey connectivity) |
| Structured logging | All application logs are emitted in JSON format for ingestion by SIEM, log aggregation, and alerting systems |
| Async resilience | All side effects (emails, webhook delivery, Stripe event processing) are processed asynchronously via Symfony Messenger with automatic retry |
| Audit log retention | Configurable minimum retention period (default: 1 year); logs are never deletable via the application interface |

---

### What the operator must provide

The following are **not** covered by the application layer and must be addressed by you to obtain and maintain SOC2 certification.

#### Policies and procedures (written documentation required by auditors)

- **Information Security Policy**: overall security programme statement
- **Access Control Policy**: who gets access to what systems and how access is reviewed/revoked
- **Incident Response Plan**: how you detect, respond to, and recover from security incidents; who is on-call; escalation procedures
- **Change Management Procedure**: how code changes are reviewed, tested, and deployed (your PR/review process qualifies if documented)
- **Vendor Management Policy**: how you evaluate and monitor third-party services (Stripe, ZeptoMail, hosting provider, etc.)
- **Business Continuity / Disaster Recovery Plan**: RTO/RPO targets, backup and restore procedures
- **Data Classification Policy**: how you classify and handle customer data
- **Employee Acceptable Use Policy**
- **Security Awareness Training**: annual training records for all employees with access to production systems

#### Infrastructure and operational controls

- **Production server hardening**: OS patching cadence, firewall rules, unnecessary service removal
- **Network segmentation**: application, database, and cache tiers should not be publicly accessible; use private networking (VPC or equivalent)
- **Secrets management**: production secrets must not be in `.env` files in the repository; use a secrets manager (AWS Secrets Manager, HashiCorp Vault, Doppler, etc.)
- **Database backups**: automated, encrypted, tested restores; backup retention meeting your RPO
- **Log retention infrastructure**: forward structured JSON logs to a log aggregation service (Datadog, Splunk, CloudWatch, etc.) with retention configured to meet audit requirements (minimum 1 year)
- **Monitoring and alerting**: set up alerting on failed logins, account lockouts, error rate spikes, and infrastructure anomalies
- **Penetration testing**: annual penetration test by a qualified third party; remediation of findings
- **SSH / server access management**: MFA on all server access; access reviews; bastion host or VPN
- **Employee offboarding**: process to revoke all system access within a defined window of employee departure

#### SOC2 audit process

- Engage a licensed CPA firm accredited for SOC2 audits (Prescient Assurance, Vanta, Drata partner firms, etc.)
- Define your audit period (Type I: point-in-time; Type II: typically 6–12 months)
- Provide evidence collection covering the period (log exports, access reviews, policy documents, training records)
- Remediate any findings from the auditor
- Renew annually (Type II reports typically issued yearly)

---

## PCI DSS

### Scope

This application uses **Stripe Checkout** and **Stripe Customer Portal** for all payment processing. Card data (PANs, CVVs, expiry dates) is entered directly on Stripe-hosted pages and never transmitted to or processed by this application.

This places the application in **SAQ-A** (Self-Assessment Questionnaire A) scope, the lightest PCI DSS compliance tier, applicable to merchants that have fully outsourced payment processing to a PCI-certified provider.

**If you add any flow that collects card data outside of Stripe-hosted pages** (inline card form using Stripe.js Elements, for example), your PCI scope expands to SAQ-A-EP or SAQ-D. Consult a Qualified Security Assessor before making that change.

---

### What this application provides

| PCI DSS Requirement | Implementation |
|---|---|
| **Req 2**: No default credentials | Admin credentials are generated on first boot and must be changed; no hardcoded passwords |
| **Req 4**: Encrypt transmission | TLS enforced by Caddy; `Strict-Transport-Security` header; no HTTP fallback |
| **Req 6**: Secure systems and applications | `composer audit` for dependency vulnerabilities; security headers; input validation; CSRF protection |
| **Req 7**: Restrict access | RBAC with least-privilege defaults; roles required for all admin and billing operations |
| **Req 8**: Identify and authenticate users | Unique user accounts; Argon2id password hashing; 2FA enforced for admin; account lockout |
| **Req 10**: Log and monitor | Immutable audit log covering all access and mutations; structured JSON logs for SIEM ingestion |
| **Req 12**: Security policy | This document, `SECURITY.md`, and `CONTRIBUTING.md` together constitute the application-level security policy |
| Stripe webhook integrity | Every inbound Stripe webhook has its `Stripe-Signature` header verified before any processing occurs |
| No cardholder data storage | Application never stores, logs, or caches card numbers, CVVs, or full PANs: only Stripe payment method tokens |

---

### What the operator must provide

#### Annual compliance tasks

- **Complete SAQ-A**: the Stripe-provided SAQ-A questionnaire annually; retain the signed copy
- **Review Stripe's PCI compliance**: confirm Stripe maintains its PCI DSS Level 1 Service Provider certification (available at [https://stripe.com/guides/pci-compliance](https://stripe.com/guides/pci-compliance))
- **Penetration test**: SAQ-A technically does not require an annual pentest but it is strongly recommended; some enterprise customers will ask for it

#### Infrastructure

- **PCI-compliant hosting**: use a hosting provider with PCI DSS certification on their infrastructure (AWS, GCP, Azure all qualify)
- **Firewall / network segmentation**: database and cache must not be publicly accessible
- **TLS certificate management**: Caddy handles cert renewal automatically; monitor for renewal failures
- **Keep Stripe libraries current**: update `stripe/stripe-php` promptly when new versions are released (subscribe to Stripe's security announcements)

#### What you must never do

- Never build a form that collects card numbers, CVVs, or expiry dates outside of a Stripe-hosted page or Stripe.js
- Never log, store, or cache any card data in any form: not in the database, not in Valkey, not in log files
- Never disable Stripe webhook signature verification

---

## Shared Responsibility Summary

| Area | Template provides | Operator provides |
|---|---|---|
| Application authentication | Argon2id, 2FA, lockout, session management | MFA on all server/infrastructure access |
| Application authorisation | RBAC, voters, least-privilege defaults | Access reviews; employee offboarding |
| Audit logging | Immutable application-level audit log | Log aggregation, retention infrastructure, SIEM alerting |
| Encryption in transit | TLS via Caddy | PCI-compliant hosting; cert monitoring |
| Encryption at rest | (application layer) N/A | Encrypted disk/volumes on servers; encrypted DB backups |
| Secrets management | `.env.example` structure | Production secrets manager (not `.env` files) |
| Vulnerability management | `composer audit` in CI | OS patching; infrastructure scanning; annual pentest |
| Incident response | Structured logs; audit trail | Written IRP; on-call; post-mortems |
| Policy documentation | This document | Full policy suite (ISP, ACP, BCP, etc.) |
| SOC2 certification | Application-level technical controls | Engage a licensed auditor; annual audit cycle |
| PCI certification | SAQ-A technical controls; no card data stored | Complete SAQ-A annually; PCI-compliant hosting |

---

## Questions

If you have questions about the compliance posture of this application, open a discussion in the repository or contact the maintainers directly. For questions about your specific audit or certification requirements, consult a licensed auditor or QSA: we cannot provide legal or compliance advice.
