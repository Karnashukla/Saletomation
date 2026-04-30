# System Architecture

This document explains how the pieces fit together, with specific attention to **shared
cPanel hosting constraints**. If you're new to the codebase, read this before touching code.

## The big picture

```
                  ┌──────────────────────────────────────────────┐
                  │          GoDaddy cPanel Shared Host          │
                  │                                              │
   ┌──────────┐   │   ┌──────────────┐    ┌──────────────────┐  │
   │  USER    ├───┼──►│  Laravel App │◄──►│  MySQL Database  │  │
   │ Browser  │   │   │  (PHP 8.2)   │    │  (cPanel-managed)│  │
   └──────────┘   │   │              │    │                  │  │
                  │   │  Inertia.js  │    │  - tenants       │  │
                  │   │  Vue 3 SPA   │    │  - users         │  │
                  │   │  Tailwind    │    │  - companies     │  │
                  │   └──────┬───────┘    │  - prospects     │  │
                  │          │            │  - signals       │  │
                  │          │ reads/     │  - drafts        │  │
                  │          │ writes     │  - scheduled_    │  │
                  │          ▼            │    sends         │  │
                  │   ┌──────────────┐    │  - csv_imports   │  │
                  │   │ /storage/    │    │  - connections   │  │
                  │   │  uploads/    │    └────────▲─────────┘  │
                  │   │  csvs/       │             │            │
                  │   └──────────────┘             │ writes     │
                  │                                │ results    │
                  │   ┌────────────────────────────┴──────┐    │
                  │   │      cPanel Cron Jobs              │    │
                  │   │                                    │    │
                  │   │  08:00 daily → daily_agent.py     │    │
                  │   │  */15 *    → process_scheduled.php│    │
                  │   │  */30 *    → check_replies.py     │    │
                  │   │  03:00 daily → daily_summary.php  │    │
                  │   └──────────────┬─────────────────────┘    │
                  │                  │                          │
                  │                  ▼                          │
                  │   ┌──────────────────────────────────┐     │
                  │   │  Python Agent (cron-invoked)     │     │
                  │   │                                  │     │
                  │   │  agent/daily_agent.py            │     │
                  │   │   ├── sources/  (web, RSS, etc) │     │
                  │   │   ├── signals/  (12 detectors)  │     │
                  │   │   └── emails/   (draft + verify)│     │
                  │   │                                  │     │
                  │   │  Calls LLM API → Anthropic       │     │
                  │   │  Calls free APIs → news, RSS     │     │
                  │   │  Writes to MySQL via PyMySQL     │     │
                  │   └──────────────────────────────────┘     │
                  │                                              │
                  └──────────────────────────────────────────────┘
                                      │
                                      ▼
                       ┌─────────────────────────────┐
                       │   External (outbound)       │
                       │                             │
                       │  - Anthropic API (drafting) │
                       │  - SMTP (Gmail/Outlook OAuth│
                       │    or cPanel relay) for     │
                       │    sending approved emails  │
                       │  - Free RSS/news sources    │
                       └─────────────────────────────┘
```

## Component breakdown

### 1. Laravel web app (PHP)

**Purpose:** Everything the user sees and clicks.

- Authentication (Laravel Breeze with Inertia)
- Multi-tenant isolation (every query scoped by `tenant_id` via global scope)
- Dashboard, prospect lists, draft editing, pipeline CRM
- File upload handler for LinkedIn CSVs
- Admin panel (org/team/user management)
- Sends emails via SMTP when user clicks "Send"

**Why Laravel and not Node:** cPanel runs PHP natively, no setup. Laravel has multi-tenancy
patterns (`stancl/tenancy` package) built for exactly this scenario. Queue system works on
shared hosting via database queue driver.

### 2. MySQL database (cPanel-managed)

**Purpose:** Single source of truth. Everything reads/writes here.

- Created via cPanel's "MySQL Databases" interface
- Connection details go in `.env` (which never commits to git)
- Migrations run via `php artisan migrate` from cPanel terminal or SSH
- Schema documented in `docs/11-database-schema.md`

**Why MySQL, not Postgres:** cPanel supports MySQL natively. Postgres on shared cPanel is
finicky and not worth the fight. MySQL 8 has JSON columns, full-text search, and CTE support
— sufficient for our needs.

### 3. Python agent (cron-invoked)

**Purpose:** The research worker that does the heavy LLM/scraping work daily.

- **Not** a persistent service. It's a script that cron runs once a day.
- Reads config from `docs/01-signals.md`, `docs/02-icp-profiles.md`, etc. (parses YAML
  frontmatter on every run — no caching since it runs once/day).
- Connects to MySQL via PyMySQL using credentials from a shared `.env` file.
- Writes companies, signals, prospects, and drafts directly to the DB.
- **Idempotent:** running it twice in the same day produces the same result (it checks
  whether today's batch already exists).

**Why Python and not pure PHP:** the LLM ecosystem (anthropic SDK, web search, BeautifulSoup,
feedparser) is mature in Python. PHP equivalents exist but are less battle-tested.

### 4. cPanel cron jobs

This is where the magic glues together. cPanel cron lets you schedule shell commands.
Our cron schedule:

| Schedule | Command | What it does |
|---|---|---|
| `0 8 * * *` | `python3 /home/USER/yellowfirst/agent/daily_agent.py` | Generate today's 9 prospects per tenant |
| `*/15 * * * *` | `php /home/USER/yellowfirst/artisan schedule:run` | Laravel scheduler (handles follow-up sends, reminders) |
| `*/30 * * * *` | `python3 /home/USER/yellowfirst/agent/check_replies.py` | Poll connected mailboxes for replies, cancel pending follow-ups |
| `0 3 * * *` | `php /home/USER/yellowfirst/artisan summary:daily` | Generate yesterday's metrics roll-up |

**Reliability caveat (per accepted trade-off):** cPanel shared hosting may kill cron jobs that
run >5 minutes or use too much memory. The daily agent is designed to:

- Process tenants sequentially with state checkpointing — if killed mid-run, it resumes from
  the last completed tenant on next invocation.
- Log every step to `storage/logs/agent-YYYY-MM-DD.log` so you can see exactly where it died.
- Send Karna a daily email summary at 8:30 AM with "agent ran successfully for X tenants" or
  "agent failed at step Y for tenant Z."

If reliability becomes a problem, the migration path is documented in `docs/10-cpanel-deploy.md`.

## Data flow: a single day in the life

1. **08:00** — Cron fires `daily_agent.py`.
2. Agent loads `docs/01-signals.md` and `docs/02-icp-profiles.md`.
3. For each active tenant:
   a. Pulls tenant's ICP definition.
   b. Searches for ~50 candidate companies matching ICP filters (industry, size, geography).
   c. For each candidate, runs all enabled signal detectors. Computes total score.
   d. Picks top 5 small + top 3 mid + top 1 enterprise.
   e. For each chosen company, identifies CXO-level prospects via public LinkedIn pages + web
      search. Detects email pattern from public sources.
   f. For each prospect, generates personalized draft email + 3 follow-up drafts via Anthropic API.
   g. Writes everything to MySQL: `companies`, `signals_detected`, `prospects`, `drafts`,
      `scheduled_sends` (follow-ups marked "pending approval").
4. **08:30** — Karna receives email: "9 new prospects ready for review."
5. **08:35** — Karna opens dashboard, reviews, approves drafts (or rejects with reason).
6. **08:40 onwards** — Approved drafts go to `scheduled_sends` queue. The `*/15 * * * *` cron
   picks them up and sends via SMTP.
7. **Day 3, 5, 7** — Follow-up drafts auto-send if no reply detected.
8. **Reply detection** — `check_replies.py` runs every 30 min, marks threads as replied,
   cancels pending follow-ups.

## Multi-tenant isolation

- Every table that holds tenant data has a `tenant_id` column with a foreign key to `tenants`.
- Laravel global scope on every relevant model: `static::addGlobalScope(new TenantScope)`.
- This means `Company::all()` only ever returns companies for the *current* tenant — even if
  a developer forgets to filter manually.
- The Python agent is given a tenant_id by the cron command (one tenant at a time) and scopes
  all writes to that tenant.
- File uploads (CSVs) are stored in `storage/app/tenants/{tenant_id}/csvs/` — physically
  separated on disk.

This is the rule that protects Karna's connections from being visible to Jassi or anyone else.
There is a test suite (`tests/Feature/TenantIsolationTest.php`) that *will* run on every commit
to verify isolation. Tenant isolation is the one thing we never compromise.

## Where things live

| Concern | Lives in |
|---|---|
| User-editable signal/ICP/cadence config | `docs/*.md` (YAML frontmatter) |
| Database schema | `database/migrations/` + documented in `docs/11-database-schema.md` |
| Business logic | `app/Services/` |
| HTTP endpoints | `app/Http/Controllers/` |
| Vue page components | `resources/js/Pages/` |
| Reusable Vue components | `resources/js/Components/` |
| Theming / colors / fonts | `resources/css/tokens.css` (single file = full rebrand) |
| Agent logic | `agent/` |
| Cron job definitions | `docs/10-cpanel-deploy.md` |
| Secrets | `.env` (NEVER commit; `.env.example` for reference) |

## What we are *not* doing

To keep scope sane and the system maintainable:

- **No Kubernetes / Docker in production.** Deploys via Git pull on cPanel.
- **No microservices.** Single Laravel monolith + single Python script.
- **No real-time websockets.** Dashboard polls every 30 seconds.
- **No mobile native app.** Mobile is responsive web only.
- **No third-party paid APIs in MVP.** Per accepted trade-off.
- **No automatic LinkedIn scraping of logged-in pages.** Only public pages, only respectfully.
- **No A/B testing infrastructure in MVP.** Templates are tweaked manually based on reply rates.

These are conscious choices, not oversights. We can revisit any of them post-MVP.
