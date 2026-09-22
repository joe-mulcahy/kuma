## Upstream Simple Kuma update

This pull request was prepared automatically but **must be reviewed and merged manually**.

Before merging:

- Read `CUSTOMIZATIONS.md`.
- Review changes to postback ingestion, conversions, event keys, payout fallback, deduplication, statistics, summaries, APIs, and outbound integrations especially carefully.
- Confirm funnel and revenue-only events retain their customized reporting semantics.
- Run the event-classification test and the full PHP/JavaScript checks.
- Verify that unmapped funnel events cannot become false Meta Purchase events.

The workflow intentionally has no path that pushes upstream commits directly to `main`.
