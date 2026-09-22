<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Single source of truth for the reporting meaning of an inbound event key.
 *
 * Classification is derived from conversions.event_key so existing installs do
 * not need a redundant classification column. Unknown and missing keys retain
 * Kuma's historical generic-conversion behaviour.
 */
final class ConversionEventClassifier
{
    public const FUNNEL = 'funnel';
    public const SALE = 'sale';
    public const REVENUE_ONLY = 'revenue_only';
    public const OPTIN = 'optin';
    public const GENERIC_CONVERSION = 'generic_conversion';

    private const FUNNEL_KEYS = [
        'orderformimpression',
        'initialorderformimpression',
        'checkout',
        'checkoutview',
        'initiatecheckout',
        'paymentinfo',
        'addpaymentinfo',
    ];

    private const SALE_KEYS = [
        'purchase',
        'initialpurchase',
        'sale',
        'conversion',
        'ftd',
    ];

    private const REVENUE_ONLY_KEYS = [
        'upsell',
        'upsellpurchase',
        'rebill',
        'renewal',
    ];

    public static function classify(?string $eventKey): string
    {
        if (ConversionOptInClassifier::isOptIn($eventKey)) {
            return self::OPTIN;
        }

        $key = self::comparisonKey($eventKey);
        if ($key !== '' && in_array($key, self::FUNNEL_KEYS, true)) {
            return self::FUNNEL;
        }
        if ($key !== '' && in_array($key, self::SALE_KEYS, true)) {
            return self::SALE;
        }
        if ($key !== '' && in_array($key, self::REVENUE_ONLY_KEYS, true)) {
            return self::REVENUE_ONLY;
        }

        return self::GENERIC_CONVERSION;
    }

    public static function countsAsConversion(?string $eventKey): bool
    {
        return in_array(self::classify($eventKey), [self::SALE, self::GENERIC_CONVERSION], true);
    }

    public static function countsAsRevenue(?string $eventKey): bool
    {
        return in_array(self::classify($eventKey), [self::SALE, self::REVENUE_ONLY, self::GENERIC_CONVERSION], true);
    }

    /**
     * Resolve credited affiliate revenue without confusing transaction value
     * with commission for explicitly classified events.
     */
    public static function creditedPayout(
        ?string $eventKey,
        ?float $inboundPayout,
        ?float $transactionValue,
        float $offerDefaultPayout
    ): float {
        return match (self::classify($eventKey)) {
            self::FUNNEL, self::OPTIN => 0.0,
            self::REVENUE_ONLY => $inboundPayout ?? 0.0,
            self::SALE => $inboundPayout ?? $offerDefaultPayout,
            default => $inboundPayout ?? $transactionValue ?? $offerDefaultPayout,
        };
    }

    public static function label(string $classification): string
    {
        return match ($classification) {
            self::FUNNEL => 'Funnel',
            self::SALE => 'Sale',
            self::REVENUE_ONLY => 'Revenue Only',
            self::OPTIN => 'Opt-in',
            default => 'Generic Conversion',
        };
    }

    /** Fixed-list SQL predicate; $eventKeyExpression must be a trusted column expression. */
    public static function sqlClassificationExpression(string $eventKeyExpression): string
    {
        $normalized = "LOWER(REPLACE(REPLACE(COALESCE({$eventKeyExpression}, ''), '_', ''), '-', ''))";
        $optins = self::sqlList(array_map([self::class, 'comparisonKey'], ConversionOptInClassifier::KEYS));
        $funnel = self::sqlList(self::FUNNEL_KEYS);
        $sales = self::sqlList(self::SALE_KEYS);
        $revenueOnly = self::sqlList(self::REVENUE_ONLY_KEYS);

        return "CASE
            WHEN {$normalized} IN ({$optins}) THEN '" . self::OPTIN . "'
            WHEN {$normalized} IN ({$funnel}) THEN '" . self::FUNNEL . "'
            WHEN {$normalized} IN ({$sales}) THEN '" . self::SALE . "'
            WHEN {$normalized} IN ({$revenueOnly}) THEN '" . self::REVENUE_ONLY . "'
            ELSE '" . self::GENERIC_CONVERSION . "'
        END";
    }

    public static function sqlCountsAsConversion(string $eventKeyExpression): string
    {
        $classification = self::sqlClassificationExpression($eventKeyExpression);
        return "({$classification}) IN ('" . self::SALE . "','" . self::GENERIC_CONVERSION . "')";
    }

    public static function sqlCountsAsRevenue(string $eventKeyExpression): string
    {
        $classification = self::sqlClassificationExpression($eventKeyExpression);
        return "({$classification}) IN ('" . self::SALE . "','" . self::REVENUE_ONLY . "','" . self::GENERIC_CONVERSION . "')";
    }

    private static function comparisonKey(?string $key): string
    {
        return str_replace(['_', '-'], '', strtolower(trim((string) $key)));
    }

    /** @param list<string> $values */
    private static function sqlList(array $values): string
    {
        return implode(',', array_map(
            static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'",
            array_values(array_unique($values))
        ));
    }
}
