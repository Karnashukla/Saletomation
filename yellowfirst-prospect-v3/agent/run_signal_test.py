"""
Test harness: run signal detection against an Apollo CSV and show results.

Usage:
    python -m agent.run_signal_test path/to/export.csv [--today YYYY-MM-DD] [--verbose]

This is a CLI tool, not a service. It exists so Karna (and anyone else) can
verify the signal detection logic against real data without needing to set up
the database. Once the database is connected, the same detection code runs
inside the Laravel agent worker — the data flows through the same dataclasses.

The --today flag is critical for testing: detection is sensitive to "now,"
so to get reproducible results across runs we let the caller pin the date.

Example output:
    Loaded 11308 connections, 1247 unique companies from CSV.
    Running funding_round detector...

    Top 10 by recency:
      [CRITICAL] Acme Corp           Series B raised ($25M)         12 days ago
      [CRITICAL] Beta Inc            Series A raised ($8M)          21 days ago
      [HIGH]     Gamma Health        Series C raised ($45M)         67 days ago
      ...

    Summary: 47 signals detected (5 critical, 18 high, 24 medium)
    Errors:  3 (run with --verbose to see)
"""
from __future__ import annotations

import argparse
import sys
from collections import Counter
from datetime import date, datetime
from pathlib import Path

# Make 'agent' package importable when running this file directly
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from agent.signals.funding_round import FundingRoundDetector  # noqa: E402
from agent.sources.apollo_csv import parse_apollo_csv  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Run signal detection against an Apollo-style CSV export."
    )
    parser.add_argument("csv_path", help="Path to the Apollo CSV export")
    parser.add_argument(
        "--today",
        help="Reference date for recency calculations (YYYY-MM-DD). Defaults to today.",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Print all errors and details, not just summary.",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=20,
        help="Max number of signals to print (default 20).",
    )
    args = parser.parse_args()

    today = (
        datetime.strptime(args.today, "%Y-%m-%d").date()
        if args.today
        else date.today()
    )

    print(f"Reference date: {today}")
    print(f"Loading CSV: {args.csv_path}")
    parse_result = parse_apollo_csv(args.csv_path, source_label=Path(args.csv_path).name)

    print(f"  → {len(parse_result.connections):,} connection rows")
    print(f"  → {len(parse_result.companies):,} unique companies (deduplicated by domain)")
    if parse_result.errors:
        print(f"  → {len(parse_result.errors)} parse errors")
        if args.verbose:
            for err in parse_result.errors[:20]:
                print(f"     - {err}")

    print()
    print("Running funding_round detector...")
    detector = FundingRoundDetector(today=today)

    all_signals = []  # list of (company, signal) tuples
    for company in parse_result.companies:
        for signal in detector.detect(company):
            all_signals.append((company, signal))

    # Sort by event_date descending (most recent first)
    all_signals.sort(
        key=lambda pair: pair[1].event_date or date.min,
        reverse=True,
    )

    if not all_signals:
        print("  No funding_round signals detected.")
        if detector.errors:
            print(f"  Detector logged {len(detector.errors)} errors.")
            if args.verbose:
                for err in detector.errors:
                    print(f"     - {err}")
        return 0

    # Strength tally
    strength_counts = Counter(sig.strength for _, sig in all_signals)

    print()
    print(f"Top {min(args.limit, len(all_signals))} signals by recency:")
    print()
    print(f"  {'STRENGTH':<10} {'COMPANY':<35} {'SIGNAL':<40} {'AGE'}")
    print(f"  {'-' * 9:<10} {'-' * 34:<35} {'-' * 39:<40} {'-' * 12}")

    for company, signal in all_signals[: args.limit]:
        days_ago = signal.evidence.get("days_ago", "?")
        age_str = f"{days_ago} days ago" if isinstance(days_ago, int) else "?"
        company_name = (company.name or company.domain)[:34]
        signal_label = signal.label[:39]
        print(
            f"  [{signal.strength.upper():<8}] "
            f"{company_name:<35} {signal_label:<40} {age_str}"
        )

    if len(all_signals) > args.limit:
        print(f"  ... and {len(all_signals) - args.limit} more")

    print()
    print(
        f"Summary: {len(all_signals)} signals detected "
        f"({strength_counts.get('critical', 0)} critical, "
        f"{strength_counts.get('high', 0)} high, "
        f"{strength_counts.get('medium', 0)} medium)"
    )
    if detector.errors:
        print(f"Detector errors: {len(detector.errors)}")
        if args.verbose:
            for err in detector.errors[:30]:
                print(f"   - {err}")
        else:
            print("   (run with --verbose to see)")

    return 0


if __name__ == "__main__":
    sys.exit(main())
