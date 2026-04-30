---
# ============================================================================
# ICP PROFILES - Per-tenant Ideal Customer Profile config.
# Each tenant has one or more named ICPs. The daily agent picks companies
# matching at least one active ICP for that tenant.
# ============================================================================

version: 1
last_updated: 2026-04-28

# ----------------------------------------------------------------------------
# YELLOWFIRST PROFILES
# Targeting Karna's "AI-first execution model" - replacing traditional product
# teams with two humans + agent mesh. Strongest fit: post-Series A / pre-Series C
# startups feeling the pain of scaling product/eng teams.
# ----------------------------------------------------------------------------

profiles:

  - id: yf_series_a_to_c_saas
    tenant_slug: yellowfirst
    name: Series A-C B2B SaaS
    description: |
      The bullseye - funded SaaS companies hiring engineers/PMs but feeling
      headcount pressure. Yellowfirst's "two people instead of twenty" pitch
      lands hardest here.
    active: true
    weight: 1.5    # Score multiplier - this profile gets priority

    industries:
      include:
        - "B2B SaaS"
        - "Developer Tools"
        - "Vertical SaaS"
        - "API / Infrastructure"
        - "DevOps"
        - "AI / ML Tools"
      exclude:
        - "Web3 / Crypto"   # Karna - flag if this should change
        - "Gambling"

    geography:
      include:
        - "United States"
        - "Canada"
        - "United Kingdom"
        - "Germany"
        - "Netherlands"
        - "Australia"
      exclude: []
      preferred:    # 1.2x multiplier
        - "United States"

    company_size:
      buckets: [small, mid]    # employee count from size_buckets in 01-signals.md
      employee_count_min: 30
      employee_count_max: 800

    funding:
      stages: ["Series A", "Series B", "Series C"]
      min_total_raised_usd: 5000000
      max_years_since_last_raise: 2

    target_titles:
      primary:
        - "CTO"
        - "Chief Technology Officer"
        - "VP Engineering"
        - "VP of Engineering"
        - "Head of Engineering"
        - "Chief Product Officer"
        - "VP Product"
        - "Head of Product"
      secondary:
        - "Founder"
        - "Co-Founder"
        - "CEO"      # Only if company size <100
        - "Director of Engineering"
        - "Director of Product"
        - "Head of AI"
        - "Head of Platform"

    signal_emphasis:    # Boost specific signals for this profile
      hiring_in_target_function: 1.3
      funding_round: 1.5
      leadership_change: 1.2
      product_launch: 1.1

    pitch_angle: |
      Series A-C SaaS companies are scaling fast and facing the classic squeeze:
      ship faster but headcount is expensive and slow. Yellowfirst's pitch:
      replace 20-person product orgs with 2 humans + AI agent mesh. Resonates
      with CTOs who've seen AI-assisted dev work and want to operationalize it.

  - id: yf_post_series_c_scaleup
    tenant_slug: yellowfirst
    name: Late-stage / Pre-IPO Scaleups
    description: |
      Scaleups (Series C+, ~500-2000 employees) under cost pressure. Different
      pitch than Series A-C: not "build instead of hire," but "redesign
      execution to compress time-to-ship for new products and SKUs."
    active: true
    weight: 1.2

    industries:
      include:
        - "B2B SaaS"
        - "Enterprise Software"
        - "FinTech"
        - "HealthTech"
        - "MarTech"
      exclude: []

    geography:
      include: ["United States", "United Kingdom", "Canada"]
      preferred: ["United States"]

    company_size:
      buckets: [mid, enterprise]
      employee_count_min: 500
      employee_count_max: 3000

    funding:
      stages: ["Series C", "Series D", "Series E", "Pre-IPO"]
      min_total_raised_usd: 50000000

    target_titles:
      primary:
        - "Chief Technology Officer"
        - "Chief Product Officer"
        - "Chief Innovation Officer"
        - "VP Engineering"
        - "VP Product"
        - "Head of New Products"
        - "Head of Innovation"
      secondary:
        - "VP Platform"
        - "Director of New Initiatives"
        - "GM"

    signal_emphasis:
      ipo_activity: 1.5
      product_launch: 1.4
      leadership_change: 1.3
      headcount_growth: 0.8    # Less interesting at scaleup stage

    pitch_angle: |
      Scaleups don't need help with their main product - that's running fine.
      They need help with NEW product lines, NEW geographies, NEW customer
      segments, where committing 20 engineers feels too expensive but they
      can't go to market without execution. Yellowfirst as the "new-bet"
      execution arm.

  - id: yf_enterprise_innovation
    tenant_slug: yellowfirst
    name: Enterprise Innovation / Digital Teams
    description: |
      Fortune 1000 / large enterprise innovation arms, digital transformation
      groups, and Chief Digital Officer orgs. One per day per the spec.
    active: true
    weight: 1.0

    industries:
      include:
        - "Financial Services"
        - "Insurance"
        - "Healthcare"
        - "Retail"
        - "Manufacturing"
        - "Telecommunications"
        - "Logistics"
      exclude:
        - "Defense"       # Compliance complexity - flag if changes
        - "Government"

    geography:
      include: ["United States", "United Kingdom", "Canada", "Australia"]
      preferred: ["United States"]

    company_size:
      buckets: [enterprise]
      employee_count_min: 5000

    target_titles:
      primary:
        - "Chief Digital Officer"
        - "Chief Innovation Officer"
        - "Chief Data Officer"
        - "VP Digital"
        - "VP Innovation"
        - "Head of Digital Transformation"
        - "Head of Innovation Lab"
      secondary:
        - "Director Digital"
        - "Director Innovation"
        - "Director Data"
        - "VP Data"
        - "VP AI/ML"

    signal_emphasis:
      product_launch: 1.3
      conference_buzz: 1.5    # Enterprise innovation execs are public-facing
      leadership_change: 1.4
      technology_modernization: 1.5    # Enable this signal for enterprise

    pitch_angle: |
      Enterprise innovation teams have budget but lack speed - traditional
      vendor cycles and internal IT governance kill momentum. Yellowfirst
      as the "fast lane" - 6-week prototype-to-production via the AI-first
      model, then transition to internal team or scaled vendor.

# ----------------------------------------------------------------------------
# Industry tags reference (used in `industries.include`)
# Edit `docs/03-filters.md` to add new industries.
# ----------------------------------------------------------------------------
---

# ICP Profiles

This file defines the Ideal Customer Profiles per tenant. Each profile is a set of filters
that the daily agent uses to find candidate companies, plus signal weight overrides that
prioritize different opportunity types.

## How profiles are used

When the daily agent runs for tenant Yellowfirst, it:

1. Loads all profiles where `tenant_slug: yellowfirst` and `active: true`.
2. For each profile, queries data sources for companies matching the filters.
3. Combines candidate lists, deduplicates, scores each company using the global signal
   config (from `01-signals.md`) **multiplied by profile-specific `signal_emphasis` overrides**.
4. Picks the top 5 small + 3 mid + 1 enterprise per the daily quota.
5. For each chosen company, finds prospects matching `target_titles` (primary first, then
   secondary if no primary found).

## Yellowfirst's three profiles, why each exists

**Profile 1: Series A-C SaaS (weight 1.5)** — your highest-conversion segment. CTOs who've
seen Cursor/Claude Code/Copilot work and now want to apply that to *team structure*, not
just individual productivity. Funding signals are weighted up because newly-funded companies
have budget to try new execution models.

**Profile 2: Late-stage Scaleups (weight 1.2)** — different pitch entirely. Their main
product is fine; they need help with *new bets*. The signal emphasis on product launches
and IPO activity is intentional — these companies are launching new SKUs and need execution
arms that don't require a full hiring round.

**Profile 3: Enterprise Innovation (weight 1.0)** — the "1 enterprise per day" slot. Lower
weight because conversion is slower and harder, but deal sizes are 5-10x larger. Conference
participation is heavily weighted because these execs are publicly visible — easier to
identify and message.

## How to edit

### To add a new industry to an existing profile
Add the industry to `industries.include`. Industry tags must match those in `docs/03-filters.md`.
If you add a new industry that doesn't exist there yet, add it to the filters file too.

### To create a new profile (e.g. for Bluefamily tenant)
Append a new entry under `profiles:`. Required fields: `id`, `tenant_slug`, `name`, `active`,
`industries`, `geography`, `company_size`, `target_titles`. Everything else is optional with
sensible defaults.

### To temporarily disable a profile
Set `active: false`. Useful for testing — e.g. disable Profile 3 for a week to see if focusing
on Profile 1 + 2 produces better daily lists.

### To bias the agent toward certain signals for a profile
Use `signal_emphasis`. Values >1.0 boost, <1.0 reduce. The signal IDs must match `01-signals.md`.

## Open questions for Karna

1. **Should Profile 1 exclude companies that already use AI dev tools?** If their LinkedIn says
   "we use Cursor" or they have a public AI engineering blog, are they less likely to need
   Yellowfirst (they've solved it themselves) or more likely (they get the value prop)? Your call.
2. **Geographic priorities right?** I included US/UK/Canada/Germany/Netherlands/Australia for
   Profile 1. Should we add India, Singapore? Different time zones for outreach matter.
3. **Title list completeness?** I covered CTO/VP Eng/CPO/VP Product/Head of X. Per your spec
   you also wanted VP Digital, VP Data, Chief Data Officer — those are in Profile 3. Should
   they also be in Profile 1 secondary? Your call based on what you've seen close.
