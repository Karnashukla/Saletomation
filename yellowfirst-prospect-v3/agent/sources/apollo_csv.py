"""
Apollo / Lusha CSV parser.

Reads the export format from Karna's CSV (Export_Contacts_2026-01-26.csv).

The format has 56 columns mixing person-level data (name, email, title) with
company-level data (revenue, funding, IPO status). One row = one person, but
multiple rows can share the same company.

This parser groups by company domain and produces:
  - CompanyInput objects (one per unique domain) — for signal detection
  - ConnectionRow objects (one per CSV row) — for the warm-intro graph

Why parse to CompanyInput instead of using a DataFrame?
  - Signal detectors are dataclass-typed (CompanyInput) — caller knows what's there
  - Removes pandas dependency from the hot path; CSV parsing is a setup step
  - Makes the source of company data swappable (CSV today, API tomorrow)

Why we deduplicate by domain:
  - Karna might have 50 contacts at a single company in one CSV
  - We want ONE signal-detection pass per company, not 50
  - Connections still preserve all 50 person-rows for warm-intro purposes
"""
from __future__ import annotations

import csv
import re
from dataclasses import dataclass, field
from datetime import date, datetime
from pathlib import Path
from typing import Iterator

from agent.lib.detector_base import CompanyInput


@dataclass
class ConnectionRow:
    """One row from the CSV — preserved for the warm-intro graph."""
    first_name: str
    last_name: str
    title: str
    work_email: str | None
    work_email_confidence: str | None  # 'A+', 'A', 'B', etc.
    direct_email: str | None
    direct_email_confidence: str | None
    linkedin_url: str | None
    seniority: str | None
    departments: str | None
    company_name: str
    company_domain: str
    location_country: str | None
    location_city: str | None
    raw: dict = field(default_factory=dict)  # Original row for audit


# ---- Funding amount parsing ---------------------------------------------

# Apollo writes funding as strings like "$1B", "$15.2M", "$250M - $500M",
# or empty. We normalize to integer USD.
_AMOUNT_PATTERN = re.compile(
    r"\$?\s*([\d,]+(?:\.\d+)?)\s*([KMBkmb]?)",
)


def parse_amount_usd(s: str | None) -> int | None:
    """Parse strings like '$15.2M', '$1B', '$250K' into integer USD.

    Range strings like '$250M - $500M' return the low end of the range.
    Empty/None/garbage returns None.
    """
    if not s or not s.strip():
        return None
    match = _AMOUNT_PATTERN.search(s)
    if not match:
        return None
    raw_num = match.group(1).replace(",", "")
    suffix = match.group(2).upper()
    try:
        num = float(raw_num)
    except ValueError:
        return None
    multiplier = {"K": 1_000, "M": 1_000_000, "B": 1_000_000_000, "": 1}[suffix]
    return int(num * multiplier)


# ---- Employee count parsing ---------------------------------------------

def parse_employee_range(s: str | None) -> tuple[int | None, str | None]:
    """Apollo writes ranges like '51-200' or '10001+'. Return (midpoint, original).

    We use the midpoint as a single-value approximation — good enough for
    sizing decisions, exact value isn't meaningful from this source anyway.
    """
    if not s or not s.strip():
        return None, None
    raw = s.strip()
    if "-" in raw:
        try:
            low, high = raw.split("-", 1)
            low_n = int(low.strip().replace(",", ""))
            high_n = int(high.strip().replace(",", ""))
            return (low_n + high_n) // 2, raw
        except ValueError:
            return None, raw
    if raw.endswith("+"):
        try:
            return int(raw[:-1].replace(",", "")), raw
        except ValueError:
            return None, raw
    try:
        return int(raw.replace(",", "")), raw
    except ValueError:
        return None, raw


# ---- Date parsing -------------------------------------------------------

def parse_date(s: str | None) -> date | None:
    """Parse YYYY-MM-DD or ISO format dates from CSV."""
    if not s or not s.strip():
        return None
    for fmt in ("%Y-%m-%d", "%m/%d/%Y", "%d/%m/%Y"):
        try:
            return datetime.strptime(s.strip(), fmt).date()
        except ValueError:
            continue
    # Try ISO datetime format
    try:
        return datetime.fromisoformat(s.replace("Z", "+00:00")).date()
    except ValueError:
        return None


# ---- The main parser ----------------------------------------------------

@dataclass
class ParseResult:
    companies: list[CompanyInput]
    connections: list[ConnectionRow]
    errors: list[str]


def parse_apollo_csv(path: str | Path, source_label: str = "csv") -> ParseResult:
    """Parse an Apollo-style CSV into companies + connections.

    Returns:
        ParseResult with deduplicated companies (one per domain) and the full
        list of connection rows (preserving all duplicates).
    """
    path = Path(path)
    if not path.exists():
        return ParseResult([], [], [f"File not found: {path}"])

    companies_by_domain: dict[str, CompanyInput] = {}
    connections: list[ConnectionRow] = []
    errors: list[str] = []

    with open(path, "r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)

        for row_num, row in enumerate(reader, start=2):  # row 1 is the header
            try:
                # Connection row — keep one per CSV row
                conn = ConnectionRow(
                    first_name=row.get("First Name", "").strip(),
                    last_name=row.get("Last Name", "").strip(),
                    title=row.get("Job Title", "").strip(),
                    work_email=row.get("Work Email", "").strip() or None,
                    work_email_confidence=row.get("Work Email Confidence", "").strip() or None,
                    direct_email=row.get("Direct Email", "").strip() or None,
                    direct_email_confidence=row.get("Direct Email Confidence", "").strip() or None,
                    linkedin_url=row.get("LinkedIn URL", "").strip() or None,
                    seniority=row.get("Seniority", "").strip() or None,
                    departments=row.get("Departments", "").strip() or None,
                    company_name=row.get("Company Name", "").strip(),
                    company_domain=row.get("Company Domain", "").strip().lower(),
                    location_country=row.get("Country", "").strip() or None,
                    location_city=row.get("City", "").strip() or None,
                    raw=row,
                )
                connections.append(conn)

                # Company input — dedupe by domain
                domain = conn.company_domain
                if not domain:
                    errors.append(f"Row {row_num}: missing company domain (skipping company-level extraction)")
                    continue

                if domain in companies_by_domain:
                    continue  # Already extracted from a previous row

                emp_count, emp_range = parse_employee_range(row.get("Company Number of Employees"))
                ipo_status = row.get("IPO Status", "").strip().lower()

                companies_by_domain[domain] = CompanyInput(
                    domain=domain,
                    name=conn.company_name,
                    industry=row.get("Company Main Industry", "").strip() or None,
                    employee_count=emp_count,
                    employee_count_range=emp_range,
                    headquarters_country=row.get("Company Country", "").strip() or None,
                    headquarters_city=row.get("Company City", "").strip() or None,
                    description=row.get("Company Description", "").strip() or None,
                    funding_total_usd=parse_amount_usd(row.get("Total Funding Amount")),
                    last_funding_amount_usd=parse_amount_usd(row.get("Last Round/Event Amount")),
                    last_funding_round=row.get("Last Round/Event Type", "").strip() or None,
                    last_funding_date=parse_date(row.get("Last Round/Event Date")),
                    is_public=ipo_status not in ("", "false", "no"),
                    ipo_date=parse_date(row.get("IPO Date")),
                    metadata={
                        "source": source_label,
                        "subindustry": row.get("Company Sub Industry", "").strip() or None,
                        "company_linkedin_url": row.get("Company linkedin URL", "").strip() or None,
                        "company_website": row.get("Company Website", "").strip() or None,
                        "intent_topics": row.get("Company Intent Topics", "").strip() or None,
                        "intent_level": row.get("Company Intent Level", "").strip() or None,
                    },
                )
            except Exception as exc:
                errors.append(f"Row {row_num}: parse error: {exc}")

    return ParseResult(
        companies=list(companies_by_domain.values()),
        connections=connections,
        errors=errors,
    )
