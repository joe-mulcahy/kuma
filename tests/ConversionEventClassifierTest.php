<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleKuma\Tracking\ConversionEventClassifier as C;
use SimpleKuma\Tracking\MetaCapiEventResolver;

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}; expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
};

foreach (['order_form_impression', 'orderformimpression', 'initial_order_form_impression', 'checkout', 'checkout_view', 'initiate_checkout', 'initiatecheckout', 'payment_info', 'add_payment_info', 'addpaymentinfo', 'paymentinfo'] as $key) {
    $assertSame(C::FUNNEL, C::classify($key), "{$key} classification");
}
foreach (['purchase', 'initial_purchase', 'sale', 'conversion', 'ftd'] as $key) {
    $assertSame(C::SALE, C::classify($key), "{$key} classification");
}
foreach (['upsell', 'upsell_purchase', 'rebill', 'renewal'] as $key) {
    $assertSame(C::REVENUE_ONLY, C::classify($key), "{$key} classification");
}
foreach (['optin', 'opt-in', 'email', 'lead', 'subscribe'] as $key) {
    $assertSame(C::OPTIN, C::classify($key), "{$key} classification");
}
$assertSame(C::GENERIC_CONVERSION, C::classify(null), 'missing key compatibility');
$assertSame(C::GENERIC_CONVERSION, C::classify('network_custom'), 'unknown key compatibility');

$events = [
    ['order_form_impression', null, null], ['add_payment_info', null, null],
    ['purchase', 34.0, 49.0], ['upsell', 22.0, 39.0],
    ['order_form_impression', null, null],
];
$conversions = 0;
$revenue = 0.0;
foreach ($events as [$key, $payout, $value]) {
    $conversions += C::countsAsConversion($key) ? 1 : 0;
    $revenue += C::creditedPayout($key, $payout, $value, 51.48);
}
$assertSame(1, $conversions, 'multi-event funnel conversion count');
$assertSame(56.0, $revenue, 'multi-event funnel revenue');
$assertSame(51.48, C::creditedPayout('purchase', null, null, 51.48), 'sale fallback payout');
$assertSame(0.0, C::creditedPayout('upsell', null, null, 51.48), 'upsell has no front-end fallback');
$assertSame(0.0, C::creditedPayout('checkout', null, null, 51.48), 'funnel has no fallback');
$assertSame(30.0, C::creditedPayout(null, 30.0, null, 51.48), 'generic postback compatibility');

$meta = MetaCapiEventResolver::resolveEventName('add_payment_info', [], 'Purchase');
$assertSame('add_payment_info', $meta['event_name'], 'unmapped funnel must not become Purchase');
$mapped = MetaCapiEventResolver::resolveEventName('add_payment_info', ['add_payment_info' => 'AddPaymentInfo'], 'Purchase');
$assertSame('AddPaymentInfo', $mapped['event_name'], 'explicit Meta mapping');

echo "ConversionEventClassifierTest: OK\n";
