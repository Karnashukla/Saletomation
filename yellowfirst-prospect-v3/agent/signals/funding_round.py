"""
funding_round signal detector.

Fires when a company has raised funding within the recency window. The
"freshness" of the funding determines the signal strength:

  ≤ 30 days  → critical (the company is actively deploying capital RIGHT NOW)
  ≤ 90 days  → high     (still in the post-funding scaling phase)
  ≤ 180 days → medium   (recent enough to be relevant)
  > 180 days → no signal (capital is no longer "new")

Why these thresholds? The post-funding scaling pain that makes Yellowfirst
relevant peaks in the first 3-6 months. After that, the company has either
hired the team they planned or pivoted — either way, the "I just raised, I
need to scale fast" mental state has cooled.

Round type also matters:
  - Series A through C are bullseye for Yellowfirst's ICP
  - Seed is too early (they're hiring foundational team, not scaling)
  - Series D+ usually means they're past the "scrappy execution" phase
  - IPO/acquisition announcements get their own signals (different detector)

Round name vocabulary (from real Apollo CSV data):
  Apollo writes things like "Seed Round", "Pre Seed Round", "Series A",
  "Series G", "Funding Round", "Non Equity Assistance", "Corporate Round",
  "Secondary Market", "Initial Coin Offering". We normalize to lowercase
  and strip the trailing " round" before matching.

Evidence schema (what gets stored in signals_detected.evidence JSON):
  {
    "round_type": "Series A",
    "amount_usd": 12000000,
    "amount_display": "$12M",
    "funding_date": "2026-03-04",
    "days_ago": 56,
    "source": "csv:Apollo Export 2026-01-26"
  }
"""
from __future__ import annotations

import re
from datetime import date

from agent.lib.detector_base import CompanyInput, SignalDetector, SignalEvent


# Recency thresholds in days
CRITICAL_THRESHOLD_DAYS = 30
HIGH_THRESHOLD_DAYS = 90
MEDIUM_THRESHOLD_DAYS = 180

# Bullseye Series rounds for Yellowfirst's primary ICP
BULLSEYE_SERIES = {"a", "b", "c"}
# Series D and beyond — relevant for late-stage scaleup ICP
SCALEUP_SERIES = {"d", "e", "f", "g", "h", "i", "j", "k"}

# Other rounds that should still fire (with explicit fit labels)
OTHER_RELEVANT_ROUNDS = {
    "growth": "scaleup",
    "private equity": "scaleup",
    "venture - series unknown": "unknown",
    "venture": "unknown",
    "funding": "unknown",   # Apollo's catch-all "Funding Round"
    "corporate": "unknown",  # Corporate VC round
}

# Rounds we explicitly skip (still log so we can audit, but no signal)
IGNORED_ROUNDS = {
    "seed",
    "pre seed",
    "pre-seed",
    "angel",
    "convertible note",
    "debt financing",
    "grant",
    "non equity assistance",   # Apollo's spelling
    "non-equity assistance",
    "post ipo equity",
    "post-ipo equity",
    "post ipo debt",
    "post-ipo debt",
    "post-ipo secondary",
    "secondary market",        # Existing investors selling — not new capital
    "initial coin offering",   # Crypto, out of scope
    "ico",
    "product crowdfunding",
    "equity crowdfunding",
}


def _normalize_round(raw: str) -> str:
    """Lowercase, collapse whitespace, strip trailing ' round'.

    "Seed Round" → "seed"
    "Pre Seed Round" → "pre seed"
    "Series A1" → "series a1"
    "Initial Coin Offering" → "initial coin offering"
    """
    s = raw.strip().lower()
    s = re.sub(r"\s+", " ", s)
    if s.endswith(" round"):
        s = s[: -len(" round")]
    return s


_SERIES_PATTERN = re.compile(r"^series\s+([a-z])(?:\d+)?$")


class FundingRoundDetector(SignalDetector):
    signal_id = "funding_round"
    base_weight = 10  # Highest base weight — fresh capital is the strongest single signal

    def __init__(self, today: date | None = None):
        """`today` is injectable for deterministic tests."""
        super().__init__()
        self._today = today or date.today()

    def detect(self, company: CompanyInput) -> list[SignalEvent]:
        # Need a funding date to compute recency. Without it, we can't decide.
        if company.last_funding_date is None:
            return []

        if company.last_funding_round is None:
            self.errors.append(
                f"{company.domain}: has funding date but no round type — likely incomplete source data"
            )
            return []

        normalized = _normalize_round(company.last_funding_round)

        # Skip explicitly ignored rounds
        if normalized in IGNORED_ROUNDS:
            return []

        # Match Series A/B/C/.../Z first
        round_fit = None
        label_prefix = None
        series_match = _SERIES_PATTERN.match(normalized)
        if series_match:
            letter = series_match.group(1)
            if letter in BULLSEYE_SERIES:
                round_fit = "bullseye"
            elif letter in SCALEUP_SERIES:
                round_fit = "scaleup"
            else:
                round_fit = "unknown"
            label_prefix = f"Series {letter.upper()} raised"
        elif normalized in OTHER_RELEVANT_ROUNDS:
            round_fit = OTHER_RELEVANT_ROUNDS[normalized]
            label_prefix = f"{company.last_funding_round.strip()} closed"

        if round_fit is None:
            # Genuinely unknown round type — log for review but don't fire
            self.errors.append(
                f"{company.domain}: unknown round type '{company.last_funding_round}'"
            )
            return []

        # Compute recency
        days_ago = (self._today - company.last_funding_date).days
        if days_ago < 0:
            self.errors.append(
                f"{company.domain}: funding date {company.last_funding_date} is in the future"
            )
            return []

        if days_ago > MEDIUM_THRESHOLD_DAYS:
            return []  # Too stale to be a useful signal

        if days_ago <= CRITICAL_THRESHOLD_DAYS:
            strength = "critical"
        elif days_ago <= HIGH_THRESHOLD_DAYS:
            strength = "high"
        else:
            strength = "medium"

        # Format amount for human-readable label
        amount = company.last_funding_amount_usd or company.funding_total_usd
        amount_display = self._format_amount_usd(amount) if amount else None

        label = label_prefix
        if amount_display:
            label = f"{label} ({amount_display})"

        evidence = {
            "round_type": company.last_funding_round,
            "round_normalized": normalized,
            "round_fit": round_fit,
            "amount_usd": amount,
            "amount_display": amount_display,
            "funding_date": company.last_funding_date.isoformat(),
            "days_ago": days_ago,
            "source": company.metadata.get("source", "unknown"),
        }

        return [
            SignalEvent(
                signal_id=self.signal_id,
                strength=strength,
                weight=self.base_weight,
                label=label,
                evidence=evidence,
                event_date=company.last_funding_date,
            )
        ]

    @staticmethod
    def _format_amount_usd(amount: int) -> str:
        """Format an integer USD amount as $X.XM / $XM / $XB."""
        if amount >= 1_000_000_000:
            return f"${amount / 1_000_000_000:.1f}B".replace(".0B", "B")
        if amount >= 1_000_000:
            return f"${amount / 1_000_000:.1f}M".replace(".0M", "M")
        if amount >= 1_000:
            return f"${amount // 1_000}K"
        return f"${amount}"

