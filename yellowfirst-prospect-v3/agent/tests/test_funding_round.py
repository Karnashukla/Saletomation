"""
Unit tests for FundingRoundDetector.

Run from repo root:
    python -m pytest agent/tests/ -v

Or with stdlib only:
    python -m unittest agent.tests.test_funding_round
"""
from __future__ import annotations

import sys
import unittest
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent.parent))

from agent.lib.detector_base import CompanyInput
from agent.signals.funding_round import FundingRoundDetector


class TestFundingRoundDetector(unittest.TestCase):
    def setUp(self) -> None:
        self.today = date(2026, 4, 28)
        self.detector = FundingRoundDetector(today=self.today)

    def _company(self, **overrides) -> CompanyInput:
        defaults = dict(
            domain="acme.com",
            name="Acme Corp",
            industry="B2B SaaS",
            employee_count=80,
            last_funding_round="Series A",
            last_funding_amount_usd=12_000_000,
            last_funding_date=date(2026, 3, 4),
        )
        defaults.update(overrides)
        return CompanyInput(**defaults)

    # ---- Recency thresholds ----

    def test_critical_when_within_30_days(self):
        company = self._company(last_funding_date=date(2026, 4, 15))  # 13 days ago
        signals = self.detector.detect(company)
        self.assertEqual(len(signals), 1)
        self.assertEqual(signals[0].strength, "critical")

    def test_high_when_31_to_90_days(self):
        company = self._company(last_funding_date=date(2026, 2, 14))  # 73 days ago
        signals = self.detector.detect(company)
        self.assertEqual(len(signals), 1)
        self.assertEqual(signals[0].strength, "high")

    def test_medium_when_91_to_180_days(self):
        company = self._company(last_funding_date=date(2025, 12, 1))  # 148 days ago
        signals = self.detector.detect(company)
        self.assertEqual(len(signals), 1)
        self.assertEqual(signals[0].strength, "medium")

    def test_no_signal_when_older_than_180_days(self):
        company = self._company(last_funding_date=date(2025, 9, 1))  # 239 days ago
        signals = self.detector.detect(company)
        self.assertEqual(signals, [])

    def test_boundary_30_days_is_critical(self):
        company = self._company(last_funding_date=date(2026, 3, 29))  # exactly 30 days
        signals = self.detector.detect(company)
        self.assertEqual(signals[0].strength, "critical")

    def test_boundary_31_days_is_high(self):
        company = self._company(last_funding_date=date(2026, 3, 28))  # exactly 31 days
        signals = self.detector.detect(company)
        self.assertEqual(signals[0].strength, "high")

    # ---- Ignored round types ----

    def test_seed_round_is_ignored(self):
        company = self._company(last_funding_round="Seed")
        signals = self.detector.detect(company)
        self.assertEqual(signals, [])

    def test_pre_seed_is_ignored(self):
        company = self._company(last_funding_round="Pre-Seed")
        signals = self.detector.detect(company)
        self.assertEqual(signals, [])

    def test_debt_financing_is_ignored(self):
        company = self._company(last_funding_round="Debt Financing")
        signals = self.detector.detect(company)
        self.assertEqual(signals, [])

    # ---- Round type variations ----

    def test_series_a_lowercase_matches(self):
        company = self._company(last_funding_round="series a")
        signals = self.detector.detect(company)
        self.assertEqual(len(signals), 1)

    def test_series_b_matches(self):
        company = self._company(
            last_funding_round="Series B",
            last_funding_amount_usd=25_000_000,
        )
        signals = self.detector.detect(company)
        self.assertEqual(len(signals), 1)
        self.assertIn("Series B", signals[0].label)
        self.assertEqual(signals[0].evidence["round_fit"], "bullseye")

    def test_series_d_is_scaleup_fit(self):
        company = self._company(last_funding_round="Series D")
        signals = self.detector.detect(company)
        self.assertEqual(signals[0].evidence["round_fit"], "scaleup")

    def test_unknown_round_logs_error(self):
        company = self._company(last_funding_round="Crowdfunding")
        signals = self.detector.detect(company)
        self.assertEqual(signals, [])
        self.assertEqual(len(self.detector.errors), 1)
        self.assertIn("Crowdfunding", self.detector.errors[0])

    # ---- Missing data handling ----

    def test_no_funding_date_returns_empty(self):
        company = self._company(last_funding_date=None)
        signals = self.detector.detect(company)
        self.assertEqual(signals, [])
        self.assertEqual(self.detector.errors, [])  # not an error, just missing

    def test_funding_date_but_no_round_logs_error(self):
        company = self._company(last_funding_round=None)
        signals = self.detector.detect(company)
        self.assertEqual(signals, [])
        self.assertEqual(len(self.detector.errors), 1)

    def test_future_funding_date_logs_error(self):
        company = self._company(last_funding_date=date(2027, 1, 1))
        signals = self.detector.detect(company)
        self.assertEqual(signals, [])
        self.assertEqual(len(self.detector.errors), 1)
        self.assertIn("future", self.detector.errors[0])

    # ---- Amount formatting ----

    def test_amount_formatted_in_label_millions(self):
        company = self._company(last_funding_amount_usd=12_000_000)
        signals = self.detector.detect(company)
        self.assertIn("$12M", signals[0].label)

    def test_amount_formatted_in_label_billions(self):
        company = self._company(last_funding_amount_usd=1_500_000_000)
        signals = self.detector.detect(company)
        self.assertIn("$1.5B", signals[0].label)

    def test_amount_optional_label_without_amount(self):
        company = self._company(
            last_funding_amount_usd=None,
            funding_total_usd=None,
        )
        signals = self.detector.detect(company)
        self.assertEqual(signals[0].label, "Series A raised")  # No amount appended

    def test_falls_back_to_total_funding_when_round_amount_missing(self):
        # Apollo CSV often has total funding but not last-round amount
        company = self._company(
            last_funding_amount_usd=None,
            funding_total_usd=20_000_000,
        )
        signals = self.detector.detect(company)
        self.assertIn("$20M", signals[0].label)

    # ---- Evidence shape ----

    def test_evidence_includes_required_keys(self):
        company = self._company()
        signals = self.detector.detect(company)
        evidence = signals[0].evidence
        for key in ("round_type", "amount_usd", "funding_date", "days_ago", "source"):
            self.assertIn(key, evidence, f"evidence missing '{key}'")

    def test_evidence_is_json_serializable(self):
        import json

        company = self._company()
        signals = self.detector.detect(company)
        # Must not raise
        json.dumps(signals[0].evidence)

    # ---- Signal ID and weight ----

    def test_signal_id_matches_config(self):
        company = self._company()
        signals = self.detector.detect(company)
        self.assertEqual(signals[0].signal_id, "funding_round")

    def test_base_weight_is_10(self):
        company = self._company()
        signals = self.detector.detect(company)
        self.assertEqual(signals[0].weight, 10)


if __name__ == "__main__":
    unittest.main()
