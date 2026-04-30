---
# ============================================================================
# SIGNAL DEFINITIONS - This YAML block is parsed by the daily agent on startup.
# Edit values, save, restart agent. To disable a signal, set `enabled: false`.
# To add a new signal, append to the `signals:` list following the same shape.
# ============================================================================

version: 1
last_updated: 2026-04-28
maintainer: karna@yellowfirst.com

# Score thresholds — companies must hit at least this score to enter the daily list.
thresholds:
  small:      40    # 5 companies/day from this bucket
  mid:        50    # 3 companies/day
  enterprise: 60    # 1 company/day
  # Maximum age in days for a signal to count as "fresh"
  freshness_max_days: 120

# How many companies to pull each morning (5 + 3 + 1 = 9 per Karna's spec)
daily_quota:
  small: 5
  mid: 3
  enterprise: 1

# Company size buckets (employee count)
size_buckets:
  small:      { min: 10,    max: 200    }
  mid:        { min: 201,   max: 2000   }
  enterprise: { min: 2001,  max: 999999 }

# ----------------------------------------------------------------------------
# SIGNALS - each one becomes a detector module in agent/signals/
# Higher weight = stronger buying signal = more likely to surface the company.
# ----------------------------------------------------------------------------
signals:

  - id: funding_round
    name: New Funding Round
    description: Company raised Series A/B/C/D, seed extension, or growth round
    weight: 10
    sources:
      - llm_web_search
      - crunchbase_public_pages
      - techcrunch_rss
      - news_api_free_tier
    detection_window_days: 90
    enabled: true
    keywords: ["raises", "funding", "Series A", "Series B", "Series C", "Series D",
               "seed round", "growth round", "led by", "term sheet"]

  - id: acquisition
    name: Acquisition (acquired or acquiring)
    description: Company was acquired, or acquired another company
    weight: 9
    sources: [llm_web_search, techcrunch_rss, news_api_free_tier]
    detection_window_days: 180
    enabled: true
    keywords: ["acquires", "acquired by", "acquisition", "merger", "M&A"]

  - id: hiring_surge
    name: Active Hiring (general)
    description: Company has 10+ open roles posted in the last 30 days
    weight: 7
    sources: [linkedin_jobs_public, company_career_pages, llm_web_search]
    detection_window_days: 30
    enabled: true
    threshold:
      min_open_roles: 10

  - id: hiring_in_target_function
    name: Hiring for Target Function (engineering/product/data/AI)
    description: |
      Company hiring specifically for engineering, product, AI/ML, data, or design roles —
      indicates expansion in functions Yellowfirst can serve.
    weight: 9
    sources: [linkedin_jobs_public, company_career_pages]
    detection_window_days: 60
    enabled: true
    target_functions:
      - "Engineering"
      - "Product"
      - "AI/ML"
      - "Data"
      - "Design"
      - "DevOps"
    threshold:
      min_open_roles_in_function: 3

  - id: headcount_growth
    name: Headcount Growth
    description: LinkedIn headcount up >15% in last 6 months
    weight: 7
    sources: [linkedin_company_public_pages]
    detection_window_days: 180
    enabled: true
    threshold:
      min_growth_pct: 15

  - id: product_launch
    name: Product Launch / New Feature / Roadmap Reveal
    description: Public product launch, major feature release, or shared roadmap
    weight: 8
    sources: [llm_web_search, producthunt_rss, company_blog_rss, news_api_free_tier]
    detection_window_days: 90
    enabled: true
    keywords: ["launches", "introduces", "announces", "now available",
               "general availability", "GA", "roadmap", "v2", "rebrand"]

  - id: ipo_activity
    name: IPO Activity
    description: Company filed S-1, going public, or just IPO'd
    weight: 9
    sources: [llm_web_search, sec_edgar_public, news_api_free_tier]
    detection_window_days: 180
    enabled: true
    keywords: ["IPO", "S-1", "going public", "direct listing", "DPO"]

  - id: conference_buzz
    name: Conference Participation / Speaking
    description: Exec is speaking at or sponsoring a major industry conference
    weight: 5
    sources: [llm_web_search, conference_speaker_pages]
    detection_window_days: 60
    enabled: true

  - id: leadership_change
    name: New Executive Hire (CXO/VP)
    description: New CTO, CIO, CPO, VP Eng, VP Product joined (new exec = new agenda)
    weight: 8
    sources: [linkedin_company_public_pages, llm_web_search]
    detection_window_days: 90
    enabled: true
    target_titles:
      - "CTO"
      - "CIO"
      - "CPO"
      - "Chief Data Officer"
      - "Chief Innovation Officer"
      - "VP Engineering"
      - "VP Product"
      - "VP Data"
      - "Head of AI"

  - id: social_growth
    name: Social Media Growth (consumer brands)
    description: Instagram/Facebook/X follower count up >25% in 90 days
    weight: 4
    sources: [public_social_profiles]
    detection_window_days: 90
    enabled: true
    threshold:
      min_growth_pct: 25
    applies_to_industries: ["DTC", "E-commerce", "Consumer", "Retail", "Media"]

  - id: search_traction
    name: Search / AI Mention Traction
    description: Significant uptick in Google search volume or LLM mentions
    weight: 5
    sources: [google_trends_public, llm_web_search]
    detection_window_days: 60
    enabled: true

  - id: ecommerce_growth
    name: E-commerce User Growth
    description: Reported active user / GMV growth in last quarter
    weight: 6
    sources: [company_blog_rss, news_api_free_tier, earnings_summaries]
    detection_window_days: 120
    enabled: true
    applies_to_industries: ["E-commerce", "Marketplace", "DTC"]

# ----------------------------------------------------------------------------
# AI-suggested signals worth considering (currently disabled, enable to test)
# ----------------------------------------------------------------------------
  - id: layoffs_then_rebuild
    name: Post-Layoff Rebuild
    description: Company had layoffs 3-9 months ago and is now hiring again — high need for execution speed
    weight: 7
    sources: [llm_web_search, layoffs_fyi_rss]
    detection_window_days: 270
    enabled: false   # turn on after baseline data collected

  - id: technology_modernization
    name: Stack Modernization Mention
    description: Public mention of replatforming, modernization, AI adoption, "rewriting in X"
    weight: 6
    sources: [company_blog_rss, engineering_blog_rss, llm_web_search]
    detection_window_days: 120
    enabled: false

  - id: regulatory_pressure
    name: Regulatory / Compliance Pressure
    description: New regulation in their industry creating compliance/build pressure (HIPAA, GDPR, SOC2, AI Act)
    weight: 5
    sources: [llm_web_search, news_api_free_tier]
    detection_window_days: 180
    enabled: false

  - id: competitor_moves
    name: Competitor Just Did Something
    description: A direct competitor of theirs just shipped a feature, raised, or acquired — pressure signal
    weight: 4
    sources: [llm_web_search]
    detection_window_days: 60
    enabled: false
---

# Signal Definitions

This file is **both human documentation and machine config**. The YAML block above is parsed by
the daily agent. The prose below explains intent, examples, and how to tune.

## How signals are scored

When the agent considers a company, it runs every enabled signal detector against that company.
Each match contributes its `weight` to the company's total score, with a freshness multiplier:

```
company_score = Σ (signal.weight × freshness_multiplier(days_since_event))
```

Freshness multiplier decays linearly from 1.0 (event happened today) to 0.0 (event >
`freshness_max_days` ago). A company must clear the bucket threshold (`small: 40`, `mid: 50`,
`enterprise: 60`) to be eligible for the daily list.

## How to tune

### To make the agent more selective
Increase the bucket thresholds in `thresholds:`. Fewer companies will qualify each day.

### To make the agent prioritize specific signal types
Increase the `weight` for those signals. Weights are relative — there's no fixed scale.
Reasonable range: 1–10. We use 10 for funding (strongest single signal) and 4 for social growth
(weakest unless combined with others).

### To add a brand-new signal
1. Append a new entry to the `signals:` list in the YAML above with all required fields:
   `id`, `name`, `description`, `weight`, `sources`, `detection_window_days`, `enabled`.
2. Create a Python module at `agent/signals/{id}.py` implementing the detection logic
   (template provided in `agent/signals/_template.py`).
3. Restart the agent: `php artisan agent:restart` (this re-reads the config).

### To disable a signal temporarily
Set `enabled: false`. No code change, no restart required if hot-reload is on (default in dev,
manual restart in production).

### To narrow signals to specific industries
Add `applies_to_industries: [...]` — the signal will only score for companies tagged with one
of those industries. Useful for `social_growth` (only matters for consumer brands) and
`ecommerce_growth` (only matters for e-commerce).

## Yellowfirst-specific tuning notes (Karna's defaults)

Yellowfirst's ICP is engineering/product leaders at startups and mid-market who could replace
traditional product teams with the AI-first execution model. The signals are weighted to surface:

- **Funding (weight 10)** — newly funded companies are scaling teams, prime moment to pitch
  "two people instead of twenty."
- **Hiring in target function (weight 9)** — they're feeling the pain of needing more execution.
- **Leadership change (weight 8)** — new CTO/CPO is evaluating tooling and team structure.
- **Product launch (weight 8)** — execution speed is top of mind.

Re-tune these numbers after 30 days of agent runs based on which signals predicted real opportunities.

## Source reliability notes

| Source | Reliability | Cost | Notes |
|---|---|---|---|
| `llm_web_search` | Medium-High | API tokens only | General-purpose, hit-or-miss on niche signals |
| `techcrunch_rss` | High for funding/acquisition | Free | Strong US/EU coverage, weak APAC |
| `crunchbase_public_pages` | High for funding | Free (rate-limited) | Public pages only — no API key |
| `linkedin_jobs_public` | High for hiring | Free (rate-limited) | Public jobs pages, no scraping logged-in pages |
| `linkedin_company_public_pages` | Medium | Free (rate-limited) | Public "About" tab data only |
| `news_api_free_tier` | Medium | Free 100/day | Aggregator — broad but shallow |
| `producthunt_rss` | High for product launches | Free | B2C/SaaS bias |
| `sec_edgar_public` | Highest | Free | US public company filings only |
| `google_trends_public` | Medium | Free | Search interest only — proxy for traction |

All sources used here are public, ToS-respecting, and rate-limited responsibly. No paid API
dependencies (per Karna's decision).

## Open questions for Karna to decide

1. **Geographic weighting?** Right now signals are global. Should we add geo weights — e.g.
   "US-based companies score 1.5x" if your sales motion is US-first?
2. **Industry weighting?** Should certain industries (FinTech, HealthTech) get a base bonus
   because Yellowfirst converts better there?
3. **Negative signals?** Should we add anti-signals that *reduce* score — e.g. "company just
   did layoffs in last 30 days" (vs. layoffs 3-9mo ago which is a positive)? Recency matters.
