# Yellowfirst Sales Prospecting Agent

An AI-powered, multi-tenant sales prospecting platform built for shared cPanel hosting.
Daily research agent + warm-intro CSV finder + 3-touch follow-up automation + pipeline CRM.

**Owner:** Karna Shukla (Yellowfirst)
**Stack:** Laravel 11 (PHP 8.2+) · MySQL · Inertia.js + Vue 3 · Tailwind CSS · Python 3.10+ (agent)
**Hosting:** GoDaddy shared cPanel + cron jobs

---

## What this app does

1. **Daily Agent (8 AM):** Picks 5 small + 3 mid + 1 enterprise company per tenant, detects high-intent
   signals (funding, hiring, launches, IPO, etc.), identifies CXO-level prospects, drafts personalized
   cold emails with 3-touch follow-up sequence pre-staged.
2. **CSV Warm-Intro Finder:** Ingests LinkedIn connection exports (yours, your friends', your team's).
   Surfaces high-signal connections + warm-intro paths through your network.
3. **Pipeline CRM:** Revenue forecasting, BU filters (US / EU / India / APAC / AU), stage tracking,
   conversion rates, rejection-reason learning loop.
4. **Multi-tenant white-label:** Orgs → teams → users with hierarchical data isolation. Per-tenant
   theming via single CSS variable file. Each tenant's connections/CSVs are private.

## Why these technology choices

| Decision | Reason |
|---|---|
| **Laravel + MySQL** instead of Next.js + Postgres | Runs natively on cPanel shared hosting. Built-in auth, queues, multi-tenancy patterns. |
| **Inertia + Vue 3** instead of separate API + SPA | Single deploy artifact. Modern SPA feel without splitting frontend/backend. |
| **Tailwind + CSS variables** | One file (`resources/css/tokens.css`) controls full white-label rebrand. |
| **Python agent** invoked by cron | LLM/scraping ecosystem is in Python. Cron triggers it, it writes results to MySQL, Laravel reads. |
| **No paid email tools** | Per Karna's decision. Bounce-risk mitigations documented in `docs/09-email-deliverability.md`. |
| **MD-driven config** | All signal definitions, ICP profiles, filters, cadences live in `docs/*.md` with YAML frontmatter. Edit markdown → restart agent → behavior changes. No code touched. |

## Repository layout

```
yellowfirst-prospect/
├── docs/                          ← EDITABLE CONFIG + DOCUMENTATION
│   ├── 00-architecture.md         System overview, data flow, deployment
│   ├── 01-signals.md              ★ Signal definitions (edit to add/remove signals)
│   ├── 02-icp-profiles.md         ★ Ideal customer profiles per tenant
│   ├── 03-filters.md              ★ Industry / size / location / BU filter taxonomy
│   ├── 04-cadences.md             ★ Follow-up timing per segment (small/mid/enterprise)
│   ├── 05-rejection-taxonomy.md   ★ Rejection reasons + learning rules
│   ├── 06-permissions.md          ★ Role matrix + multi-tenant isolation rules
│   ├── 07-email-templates.md      ★ Prompt templates for draft generation
│   ├── 08-tenant-config.md        ★ Per-org overrides
│   ├── 09-email-deliverability.md Bounce mitigation, warm-up protocol, MX probing
│   ├── 10-cpanel-deploy.md        Step-by-step deployment to GoDaddy cPanel
│   ├── 11-database-schema.md      Full DB schema reference
│   └── 12-changelog.md            Release notes
│
├── app/                           Laravel application code
│   ├── Http/Controllers/          Web/API controllers
│   ├── Models/                    Eloquent models (Tenant, User, Company, Prospect, Signal, Draft)
│   ├── Services/                  Business logic (PipelineService, CSVImportService)
│   └── Console/Commands/          Artisan commands invoked by cron
│
├── agent/                         Python research agent (invoked by cron)
│   ├── daily_agent.py             Main entrypoint - runs at 8 AM
│   ├── signals/                   One module per signal type
│   ├── sources/                   Data source adapters (web search, RSS, public-page scraping)
│   ├── emails/                    Email pattern detection + draft generation
│   └── requirements.txt
│
├── resources/
│   ├── views/                     Inertia page components
│   ├── css/
│   │   └── tokens.css             ★ DESIGN SYSTEM - single source of truth for theming
│   └── js/                        Vue 3 components
│
├── database/migrations/           Schema migrations
├── config/                        Laravel config files
├── public/                        Web root (where cPanel points)
└── routes/                        web.php, api.php, console.php
```

★ = files Karna will edit regularly to tune the system.

## How the editable-config system works

The `docs/*.md` files marked with ★ are not just documentation — they are **runtime configuration**.

Each starred file has a YAML frontmatter block at the top that the agent parses on startup:

```markdown
---
signals:
  - id: funding_round
    name: New Funding Round
    weight: 9
    sources: [crunchbase_news, techcrunch_rss, llm_web_search]
    detection_window_days: 90
    enabled: true
---

# Detailed prose explaining the signal goes below for humans...
```

The prose below the frontmatter is for you (and future devs). The frontmatter is for the agent.
**To change behavior: edit the frontmatter, save, restart the agent (one cron flag).**

## Build order (per Karna's MVP ranking)

| Phase | Deliverable | Status |
|---|---|---|
| 0 | Foundation: repo, DB schema, design system, auth, multi-tenant scaffolding | 🟡 In progress |
| 1 | **Daily Agent v1** — 9 companies/day, signals, prospects, drafts | ⚪ Next |
| 2 | **CSV Warm-Intro Finder** — LinkedIn ingestion, friend network, intro paths | ⚪ Pending |
| 3 | **3-Touch Follow-up Automation** — scheduled sends, reply detection, auto-cancel | ⚪ Pending |
| 4 | **Pipeline Dashboard** — revenue/BU filters, charts, conversion tracking | ⚪ Pending |
| 5 | **White-Label Admin** — orgs, teams, theming, tenant isolation tests | ⚪ Pending |

## Trade-offs explicitly accepted

These were Karna's calls and are documented here so future-Karna remembers why:

- **Shared cPanel hosting** chosen over Vercel/VPS. Trade-off: agent runs as cron batch, not
  persistent worker. Follow-ups depend on cron reliability. See `docs/10-cpanel-deploy.md` for
  cPanel-specific risks and mitigations.
- **No Hunter.io / paid email tools.** Trade-off: higher bounce rate (~8-15% expected vs. 2-3%
  with verification). Mitigated via pattern-from-public-source detection, MX/SMTP probing,
  warm-up cadence, and per-domain bounce blacklisting. See `docs/09-email-deliverability.md`.

---

**Next steps:** See `docs/00-architecture.md` for the system design, then `docs/01-signals.md`
for the editable signal config.
