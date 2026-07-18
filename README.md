# SaaS Template

A production-ready, compliance-aware SaaS starter built on Symfony 8, FrankenPHP, PostgreSQL, and Tailwind CSS. Ships with multi-tenancy, Stripe billing with tiered plans and entitlements, a fully dynamic RBAC system, a custom admin backend, a versioned REST API, and outgoing webhooks. All wired for SOC2 and PCI DSS (SAQ-A) compliance from day one.

## Stack

PHP 8.5 · Symfony 8 · FrankenPHP · PostgreSQL · Valkey · Tailwind CSS v4 · Stripe · Docker

## Run locally

```bash
cp .env.example .env && make up
```

Visit [https://localhost](https://localhost). The first run will seed the database and create a default Super Admin account. Credentials are printed to the console on first boot.

---

For setup details, environment variables, testing, and contribution guidelines see [CONTRIBUTING.md](CONTRIBUTING.md).  
For the full build roadmap and architecture decisions see [ROADMAP.md](ROADMAP.md).  
For vulnerability reporting see [SECURITY.md](SECURITY.md).  
For compliance documentation see [COMPLIANCE.md](COMPLIANCE.md).
