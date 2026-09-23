# Custom Simple Kuma Changes

This repository is a maintained private fork of Simple Kuma. Upstream remains a useful source of fixes and improvements, but upstream changes must be reviewed before being merged.

## Upstream policy

`.github/workflows/sync-upstream.yml` periodically fetches `kumatrk/initialrelease` and opens or updates a pull request from `upstream-sync` into `main`. It must never merge or push upstream commits directly into `main`.

The repositories have unrelated Git histories because Kuma releases were imported as zip-parity snapshots. `.github/upstream-base` records the last upstream product snapshot incorporated into this fork. The workflow applies the file delta between that baseline and the latest upstream commit, then advances the baseline inside the review PR. Do not replace this with `git merge --allow-unrelated-histories`.

## Event classification

Inbound events retain their canonical `conversions.event_key`. Reporting behavior is derived centrally by `SimpleKuma\Tracking\ConversionEventClassifier` rather than stored in a redundant database column.

- `funnel`: stored and reportable; not a primary conversion and no revenue.
- `sale`: primary conversion; contributes revenue and can use the offer payout fallback.
- `revenue_only`: explicit payout contributes revenue; does not increase primary conversions.
- `optin`: preserves Kuma's existing opt-in aliases/reporting; not a sale or sales revenue.
- `generic_conversion`: backward-compatible behavior for unknown or missing event keys.

| Event key | Classification | Conversion | Revenue |
| --- | --- | --- | --- |
| `order_form_impression` | Funnel | No | No |
| `add_payment_info` | Funnel | No | No |
| `purchase` | Sale | Yes | Yes |
| `upsell` | Revenue Only | No | Yes, when payout is supplied |
| no or unknown key | Generic Conversion | Yes | Existing generic rules |

## Payout behavior

- Funnel and opt-in events always credit zero payout and never use the offer default.
- Sales use inbound payout or the offer's configured payout.
- Revenue-only events use inbound payout or zero; they never inherit the front-end offer payout.
- Transaction `value` remains separate from credited affiliate payout.

## Reporting and API

Conversions and CVR include sales and generic conversions only. Revenue includes sales, generic conversions, and revenue-only events. Profit, ROI, EPC, charts, dashboard totals, campaign tables, and summary rebuilds use these corrected values.

Campaign Stats includes an Event Breakdown. Conversion Log exposes event classifications and filters. Click Lookup presents chronological event history and classification-aware totals.

REST conversion/event responses add `event_type`, `event_classification`, `counts_as_conversion`, and `counts_as_revenue`. Campaign statistics expose `event_breakdown`.

## Deduplication

Unique transaction or event identifiers permit multiple legitimate events on one click. Repeated network events remain deduplicated through existing `txid` and `event_id` rules. Campaign-level multiple-conversion behavior remains available for integrations without reliable identifiers.

## Outbound integrations

Reporting classification and outbound mapping are separate. Funnel events remain eligible for custom postbacks and Meta CAPI delivery.

- `order_form_impression` → `InitiateCheckout`
- `add_payment_info` → `AddPaymentInfo`
- `purchase` → `Purchase`
- `upsell` → `Upsell` or another deliberate custom event

An explicit known funnel event without a mapping is sent as a custom event instead of silently falling back to Meta Purchase. A postback without an event key retains configured default Meta behavior.

## High-risk upstream areas

Review any upstream change touching:

- postback ingestion, conversions, `event_key`, payout fallback, or deduplication
- `ConversionEventClassifier`, `ConversionTracker`, or `DailySummaryUpdater`
- campaign/dashboard/chart/pre-aggregated statistics
- Conversion Log, Click Lookup, or REST serialization
- Meta CAPI, Google Ads, or custom outbound postbacks

A clean Git merge does not prove that an upstream change preserves these semantics.

## Verification

```sh
php tests/ConversionEventClassifierTest.php
find src public views tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/js/campaign-stats.js
```

For historical event rows, rebuild affected summaries:

```sh
php scripts/update-daily-summaries.php --from=YYYY-MM-DD --to=YYYY-MM-DD
```
