"""
Base class for signal detectors.

Every signal detector inherits from `SignalDetector` and implements `detect()`.
This gives us a uniform interface — the agent runner can iterate over all
registered detectors without knowing what each one does internally.

Design notes:
  - Detectors are PURE functions of their input. They don't know about the
    database, the LLM, or the network. The runner passes them data and they
    return SignalEvent objects. This makes them trivially testable.
  - A single detector can return ZERO, ONE, or MANY signals for a given
    company. (e.g. "hiring_surge" might fire once per hiring round.)
  - Detectors must NEVER raise on bad data. If they can't decide, they return
    an empty list. Errors go in `detector.errors` for the runner to log.
"""
from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from datetime import date, datetime, timezone
from typing import Any


@dataclass
class CompanyInput:
    """The structured company data that detectors receive.

    This shape is deliberately flat and source-agnostic. The runner is
    responsible for normalizing whatever raw source (CSV, API, scrape)
    into this shape before invoking detectors.
    """
    domain: str
    name: str
    industry: str | None = None
    employee_count: int | None = None
    employee_count_range: str | None = None  # e.g. "51-200" from Apollo
    headquarters_country: str | None = None
    headquarters_city: str | None = None
    description: str | None = None
    # Funding fields (populated from CSV or scraped sources)
    funding_total_usd: int | None = None
    last_funding_amount_usd: int | None = None
    last_funding_round: str | None = None  # "Series A", "Seed", etc.
    last_funding_date: date | None = None
    is_public: bool = False
    ipo_date: date | None = None
    # Free-form for source-specific extras
    metadata: dict[str, Any] = field(default_factory=dict)


@dataclass
class SignalEvent:
    """The output of a detector — one signal that fired.

    weight is the BASE weight from the signal config (docs/01-signals.md).
    The runner multiplies this by ICP weight to get the final score contribution.
    """
    signal_id: str            # Matches docs/01-signals.md, e.g. "funding_round"
    strength: str             # 'low' | 'medium' | 'high' | 'critical'
    weight: int               # 1-10 base weight
    label: str                # Human-readable headline ("Series A raised")
    evidence: dict[str, Any]  # Structured evidence — must be JSON-serializable
    event_date: date | None = None  # When the event happened
    detected_at: datetime = field(default_factory=lambda: datetime.now(timezone.utc))


class SignalDetector(ABC):
    """Abstract base for all signal detectors."""

    # Subclasses must set these
    signal_id: str = ""
    base_weight: int = 0
    enabled: bool = True

    def __init__(self) -> None:
        self.errors: list[str] = []

    @abstractmethod
    def detect(self, company: CompanyInput) -> list[SignalEvent]:
        """Return all signals this detector finds for the given company.

        Must return [] (not None) if no signals fire. Must not raise — log
        errors to self.errors instead.
        """
        ...

    def __repr__(self) -> str:
        return f"<{self.__class__.__name__} signal={self.signal_id} weight={self.base_weight}>"
