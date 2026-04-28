# Database Schema Reference

MySQL 8 schema for Yellowfirst Sales Prospecting. Every table is multi-tenant via `tenant_id`.

This document is the **human-readable reference**. The authoritative schema lives in
`database/migrations/`. If they ever drift, the migrations win and this doc gets updated.

## Table list

| Table | Purpose | Tenant-scoped? |
|---|---|---|
| `tenants` | Top-level org (Yellowfirst, Bluefamily, etc.) | No (it IS the tenant) |
| `users` | People who log in | Yes (belongs to tenant) |
| `teams` | Sub-groups within a tenant | Yes |
| `team_user` | Many-to-many: which users are in which teams | Yes |
| `roles` + `permissions` + `role_user` | RBAC (Spatie permissions package) | Yes |
| `icp_profiles` | Per-tenant Ideal Customer Profile config | Yes |
| `companies` | Companies the agent has researched | Yes |
| `signals_detected` | Which signals fired for which company | Yes |
| `prospects` | People at companies (CXOs, VPs, etc.) | Yes |
| `csv_imports` | Uploaded LinkedIn CSV file metadata | Yes |
| `connections` | Rows from imported CSVs (network graph) | Yes |
| `connection_owners` | Who owns each connection (you / friend / teammate) | Yes |
| `drafts` | AI-generated email drafts | Yes |
| `scheduled_sends` | Outbound queue (initial + follow-ups) | Yes |
| `email_threads` | Sent emails + reply tracking | Yes |
| `pipeline_stages` | Configurable stages (Lead, Qualified, Negotiation, Won, Lost) | Yes |
| `pipeline_entries` | Individual deals in the pipeline | Yes |
| `business_units` | BUs (US, EU, India, APAC, AU) | Yes |
| `rejection_reasons` | Why drafts were rejected (taxonomy + free-text) | Yes |
| `agent_runs` | Audit log of every daily agent invocation | Yes |
| `audit_log` | All sensitive actions (sends, deletes, role changes) | Yes |

## Core tables

### `tenants`
The top-level isolation boundary. Each tenant is a customer (Yellowfirst, Bluefamily, etc.).

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(255) | Display name |
| slug | varchar(100) unique | URL-safe (e.g. "yellowfirst") |
| domain | varchar(255) nullable | Custom domain for white-label |
| theme_config | json | Colors, fonts, logo URL — overrides `tokens.css` defaults |
| timezone | varchar(50) | For "8 AM daily" — each tenant gets their own 8 AM |
| daily_quota_small | tinyint | Default 5 |
| daily_quota_mid | tinyint | Default 3 |
| daily_quota_enterprise | tinyint | Default 1 |
| smtp_config | json encrypted | OAuth tokens for Gmail/Outlook or SMTP creds |
| status | enum | active, suspended, trial |
| created_at / updated_at | timestamp | |

### `users`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| name | varchar(255) | |
| email | varchar(255) | Unique within tenant |
| password | varchar(255) | bcrypt |
| email_verified_at | timestamp nullable | |
| linkedin_url | varchar(500) nullable | For matching to imported connections |
| timezone | varchar(50) nullable | Override tenant default |
| last_login_at | timestamp nullable | |
| created_at / updated_at | timestamp | |

Standard Laravel users table + tenant_id + linkedin_url.

### `companies`
The companies the agent researches.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| name | varchar(255) | |
| domain | varchar(255) | Primary website domain — used for email pattern matching |
| linkedin_url | varchar(500) nullable | |
| crunchbase_url | varchar(500) nullable | |
| industry | varchar(100) | |
| size_bucket | enum | small / mid / enterprise |
| employee_count | int nullable | LinkedIn estimate |
| headquarters_country | varchar(100) | |
| headquarters_city | varchar(100) | |
| business_unit_id | bigint FK nullable | Which BU this company falls under for the tenant |
| description | text | Short company description |
| funding_total_usd | bigint nullable | |
| last_funding_round | varchar(50) nullable | "Series B" |
| last_funding_date | date nullable | |
| score | int | Last computed signal score |
| score_computed_at | timestamp | |
| first_seen_at | timestamp | When agent first added it |
| last_researched_at | timestamp | Last full re-research |
| status | enum | active, archived, blacklisted |
| metadata | json | Anything the agent learned that doesn't fit columns |

**Indexes:** `(tenant_id, score DESC)` for daily list queries. `(tenant_id, domain)` unique.

### `signals_detected`
Which signals fired for which company. Append-only log — we keep history to learn over time.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| company_id | bigint FK | |
| signal_id | varchar(50) | e.g. "funding_round" — matches id in 01-signals.md |
| weight_at_detection | int | Snapshot — so historical scores don't change when we re-tune weights |
| evidence | json | URL, headline, date, snippet — what convinced the agent |
| detected_at | timestamp | When the agent saw this signal |
| event_date | date nullable | When the *event itself* happened (e.g. funding announcement date) |

**Indexes:** `(tenant_id, company_id)`, `(tenant_id, signal_id, detected_at)`.

### `prospects`
People at companies. The CXO-level humans we want to email.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| company_id | bigint FK | |
| first_name | varchar(100) | |
| last_name | varchar(100) | |
| title | varchar(255) | |
| function | varchar(50) | engineering, product, data, design, executive |
| seniority | enum | c_level, vp, director, head_of |
| linkedin_url | varchar(500) nullable | |
| email | varchar(255) nullable | Generated from pattern OR found publicly |
| email_pattern_source | enum | public_page, llm_inferred, csv_provided, manually_entered |
| email_confidence | tinyint | 0-100 |
| connection_id | bigint FK nullable | If this prospect is in a CSV (warm intro available) |
| connection_owner_user_id | bigint FK nullable | Who has the warm connection |
| status | enum | new, draft_ready, sent, replied, rejected, won, lost |
| metadata | json | |
| created_at / updated_at | timestamp | |

### `connections` and `connection_owners`
The CSV warm-intro graph. Critical for phase 2.

`csv_imports`:
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| uploaded_by_user_id | bigint FK | |
| owner_label | varchar(255) | "Karna's connections" / "Reez's connections" / "Team CSV" |
| owner_type | enum | self, friend, teammate, other |
| filename | varchar(255) | |
| storage_path | varchar(500) | tenants/{id}/csvs/... |
| row_count | int | |
| status | enum | uploading, parsing, ready, failed |
| error_message | text nullable | |
| created_at | timestamp | |

`connections`:
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| csv_import_id | bigint FK | Which file this came from |
| first_name, last_name | varchar | |
| company_name | varchar(255) | As recorded in CSV — may differ from canonical company |
| company_id | bigint FK nullable | Resolved canonical company (if found) |
| title | varchar(255) | |
| linkedin_url | varchar(500) | |
| email | varchar(255) nullable | LinkedIn export sometimes includes |
| connected_on | date nullable | |
| degree | tinyint | 1, 2, 3 — connection degree |
| location | varchar(255) | |
| signal_score | int default 0 | If this connection's company has signals |
| metadata | json | |

`connection_owners` (resolves "who owns this connection?"):
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| connection_id | bigint FK | |
| owner_label | varchar(255) | "Karna" / "Reez" / "Team" |
| can_introduce | bool | Whether this owner can warm-intro |

This lets us answer: "Show me Reez's connections at companies with high signals that I want to
meet" → joins `connections` × `connection_owners` × `companies` × `signals_detected`.

### `drafts` and `scheduled_sends`
The email engine.

`drafts`:
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| prospect_id | bigint FK | |
| sequence_position | tinyint | 0 = initial, 1 = follow-up day 3, 2 = day 5, 3 = day 7 |
| subject | varchar(255) | |
| body_text | longtext | Plain text version |
| body_html | longtext nullable | If using HTML emails |
| signal_evidence_used | json | Which signals informed this draft (for transparency) |
| status | enum | pending_approval, approved, sent, rejected, cancelled |
| approved_at / approved_by_user_id | timestamp / FK | |
| rejected_at / rejection_reason_id | timestamp / FK | |
| rejection_notes | text | Free-text reason |
| created_at / updated_at | timestamp | |

`scheduled_sends`:
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| draft_id | bigint FK | |
| send_at | timestamp | The cron picks rows where `send_at <= NOW()` |
| status | enum | pending, sending, sent, failed, cancelled |
| sent_at | timestamp nullable | |
| failure_reason | text nullable | |
| smtp_message_id | varchar(255) nullable | For threading replies |

**Indexes:** `(send_at, status)` for the every-15-min cron.

### `email_threads`
Tracks sent emails and their replies. This is what the reply-detection cron updates.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| prospect_id | bigint FK | |
| thread_external_id | varchar(255) | Gmail/Outlook thread ID |
| last_message_at | timestamp | |
| reply_received | bool | True = cancel pending follow-ups |
| reply_received_at | timestamp nullable | |
| reply_summary | text | LLM-summarized: positive / neutral / negative / unsubscribe |
| metadata | json | |

### `pipeline_stages` and `pipeline_entries`
Tenant-configurable CRM stages.

`pipeline_stages` (defaults: Lead → Qualified → Demo → Proposal → Negotiation → Won / Lost):
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| name | varchar(100) | |
| sort_order | tinyint | |
| is_terminal | bool | Won/Lost flags |
| terminal_outcome | enum | won, lost, null |

`pipeline_entries`:
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| prospect_id | bigint FK | |
| stage_id | bigint FK | |
| business_unit_id | bigint FK nullable | |
| owner_user_id | bigint FK | Whose deal this is |
| value_usd | decimal(12,2) nullable | Estimated deal size |
| expected_close_date | date nullable | |
| stage_changed_at | timestamp | |
| won_lost_at | timestamp nullable | |
| notes | text | |

Pipeline charts on the dashboard query against this with grouping by stage / BU / time.

### `rejection_reasons`
The taxonomy you wanted: "wrong connection / wrong company / already approached / wrong prospect / other"

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK nullable | NULL = global default |
| code | varchar(50) | wrong_company, wrong_person, already_customer, etc. |
| label | varchar(255) | Display label |
| sort_order | tinyint | |
| affects_future_research | bool | If true, agent learns to avoid this pattern |

### `agent_runs`
Audit log so we can see what the agent did and didn't do.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK | |
| started_at | timestamp | |
| ended_at | timestamp nullable | |
| status | enum | running, completed, failed, killed |
| companies_evaluated | int | |
| companies_added | int | |
| signals_detected | int | |
| prospects_added | int | |
| drafts_generated | int | |
| llm_tokens_used | int | For cost tracking |
| log_path | varchar(500) | Path to detailed log file |
| error_message | text nullable | |

## Relationships diagram (text form)

```
tenants ──┬─ users ──── team_user ─── teams
          │                              │
          │                              └─── pipeline_entries.owner_user_id
          │
          ├─ icp_profiles
          ├─ business_units
          ├─ companies ─── signals_detected
          │     │
          │     └─ prospects ─── drafts ─── scheduled_sends
          │              │           │
          │              │           └──── email_threads
          │              │
          │              └─ connection_id ──► connections ─── connection_owners
          │                                         │
          │                                         └── csv_imports
          │
          ├─ pipeline_stages ─── pipeline_entries
          ├─ rejection_reasons
          ├─ agent_runs
          └─ audit_log
```

## Schema design rules

1. **Every multi-tenant table has `tenant_id` as the second column** (after `id`).
2. **Every multi-tenant table has an index starting with `tenant_id`** for query performance.
3. **Foreign keys are `RESTRICT` on delete by default** — no cascading deletes that could
   silently nuke data. Tenant deletion is a separate, explicit, irreversible operation that
   archives data first.
4. **`json` columns for flexibility**, but only for things we *don't* query on. If we need to
   filter or aggregate by it, it gets its own column.
5. **Soft deletes (`deleted_at`)** on `users`, `companies`, `prospects`, `csv_imports` — so a
   user accidentally deleting something can be recovered.
6. **No raw passwords or API keys in any table** — `smtp_config` is encrypted with Laravel's
   built-in encryption; secrets in `.env`.
7. **Timestamps in UTC** in storage. Display in user's timezone via Laravel's Carbon.

## Open questions on schema

1. **Connection deduplication?** If Karna and Reez both have John Doe as a connection, do we
   store John twice (once per CSV import) or merge them? Recommendation: store twice in
   `connections` (preserving CSV provenance) but show a "shared connection" indicator in UI.
2. **Email pattern caching?** We could add a `domain_email_patterns` table that caches the
   detected pattern per domain, so we don't redetect for every prospect at the same company.
   Recommended.
3. **Historical signal weights?** When you tune `signals.weight` in the MD config, do
   already-scored companies get re-scored with new weights? Recommendation: no — `signals_detected.weight_at_detection`
   freezes the historical weight. The dashboard can show "if rescored today" as a separate
   metric.
