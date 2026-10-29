<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin against core's real cart pricer, checkout and receipt on a scratch database: a
 * quote carries included tax that leaves the total alone, the order stores it with amount 0,
 * the receipt prints as a tax invoice, exclusive tax adds to the total, and core accepts the
 * settings page and its rate table.
 *
 * Needs core's scratch MySQL (see core's tests/lib/scratchdb.php) and a core with shop mode;
 * on any other core it skips. Usage:
 *   SHOPCLASS_CORE=/path/to/core DRIFT_DB_PORT=33061 php tests/integration/billing.php
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/shopclass', '/');
$pricer = $core . '/oc-includes/osclass/classes/billing/CartPricer.php';
if (!is_file($pricer) || !str_contains((string) file_get_contents($pricer), 'PROBLEM_POINTERS')) {
    echo "SKIP  core at $core has no shop-mode cart contract\nRESULT: 0 passed, 0 failed\n";
    exit(getenv('REQUIRE_CORE') === '1' ? 1 : 0);
}

require_once $core . '/tests/lib/harness.php';
require_once $core . '/tests/lib/api-admin-kit.php';

$admin = api_admin_boot('osc_tax_plugin');
$p     = DB_TABLE_PREFIX;
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDatabase.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CartPricer;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use mindstellar\listing\VariantStore;
use mindstellar\settings\SettingsPageRegistry;
use mindstellar\tax\Plugin;
use mindstellar\tax\RatesField;

$locale = seed_locale($admin);
seed_currency($admin, 'INR', 'Indian Rupee');
seed_country($admin, 'IN', 'India');
$clothing = seed_category($admin, 'Clothing', null, $locale);
$tees     = seed_category($admin, 'Tees', $clothing, $locale);
$books    = seed_category($admin, 'Books', null, $locale);
$seller   = seed_user($admin, 'seller', 'seller@example.test');
$buyer    = seed_user($admin, 'buyer', 'buyer@example.test');
$m        = static fn (float $units): int => (int) round($units * 1000000);
foreach (array('enabled_users' => '1', 'language' => 'en_US', 'currency' => 'INR', 'billing_enabled' => '1', 'billing_currency' => 'INR', 'billing_shipping_flat' => (string) $m(50)) as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

final class TaxFakeGateway implements PaymentGateway
{
    public function getId(): string
    {
        return 'fake';
    }

    public function getName(): string
    {
        return 'Fake';
    }

    public function getSupportedCurrencies(): array
    {
        return array('INR');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        return CheckoutIntent::redirect('https://pay.example.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('none');
    }
}
PaymentGatewayRegistry::getInstance()->register(new TaxFakeGateway());

$tee  = seed_item($admin, $tees, $seller, 'Tee', 499.0);
$book = seed_item($admin, $books, $seller, 'Book', 118.0);
foreach (array($tee, $book) as $id) {
    $admin->query("UPDATE {$p}t_item SET fk_c_currency_code = 'INR' WHERE pk_i_id = $id");
}
$teeV  = VariantStore::saveDefault($tee, array('i_price' => $m(499), 'i_stock' => null));
$bookV = VariantStore::saveDefault($book, array('i_price' => $m(118), 'i_stock' => null));
$cart  = array(array('variant' => $teeV, 'qty' => 1), array('variant' => $bookV, 'qty' => 1));

harness_section('the plugin loads on this core');
require dirname(__DIR__, 2) . '/index.php';
check('the settings page is registered', SettingsPageRegistry::getInstance()->fields(Plugin::PAGE) !== array());
pin('prices include tax until the admin says otherwise', true, (bool) osc_settings_value(Plugin::PAGE, 'prices_include_tax'));

$pricer = new CartPricer();
$quote  = static fn (array $ctx = array()) => $pricer->quote($cart, $ctx);
pin('no rate table yet: no tax', array(), $quote()->extraLines('tax'));

harness_section('the rate table from the settings form');
$fields = SettingsPageRegistry::getInstance()->fields(Plugin::PAGE);
foreach (array(
    'tax_default'       => '18',
    'tax_country_code'  => array('de', ''),
    'tax_country_rate'  => array('19', ''),
    'tax_category_id'   => array((string) $clothing, ''),
    'tax_category_rate' => array('5', ''),
    'tax_category_code' => array('6109', ''),
) as $k => $v) {
    Params::setParam($k, $v);
}
$json = osc_settings_sanitize($fields['rates']);
pin('core collects the rows into the table', '{"default_bp":1800,"by_country":{"DE":1900},"by_category":{"' . $clothing . '":{"bp":500,"code":"6109"}}}', $json);
pin('and it validates', null, osc_settings_validate($fields['rates'], $json));
Params::setParam('tax_country_rate', array('190', ''));
$kept = osc_settings_sanitize($fields['rates']);
check('a bad row is refused with a reason, so nothing is saved', osc_settings_validate($fields['rates'], $kept) !== null && $kept === '{}');
Params::setParam('tax_country_rate', array('19', ''));
osc_settings_sanitize($fields['rates']);
ob_start();
RatesField::render(array('values' => array('rates' => '{"default_bp":1800,"by_country":{"DE":1900},"by_category":{}}')));
$form = (string) ob_get_clean();
check('the table draws the stored rows and blank ones', str_contains($form, 'name="tax_default"') && str_contains($form, 'value="DE"') && substr_count($form, 'name="tax_country_code[]"') === 4 && str_contains($form, 'Clothing › Tees'), $form);

osc_set_preference('rates', $json, Plugin::PAGE);
foreach (array('seller_country' => 'IN', 'seller_state' => 'Delhi', 'seller_tax_id' => '07AAACM1234A1Z5', 'tax_label' => 'GST', 'tax_id_label' => 'GSTIN') as $k => $v) {
    osc_set_preference($k, $v, Plugin::PAGE);
}
osc_reset_preferences();

harness_section('an inclusive quote');
$q = null;
$n = harness_query_count(static function () use ($quote, &$q) {
    $q = $quote(array('address' => CartPricer::quoteAddress('IN', 'Maharashtra', '400001')));
});
pin('with category rates the quote still makes 3 queries', 3, $n);
// The tee is in Tees, under Clothing at 5%: 499 holds 23.76. The book is at the default 18%: 118 holds 18.
pin('one tax line per rate, the subcategory taking its parent\'s', array('tax.gst.5' => $m(23.76), 'tax.gst.18' => $m(18)), array_column($q->extraLines('tax'), 'amount', 'id'));
pin('marked included', array(true, true), array_column($q->extraLines('tax'), 'included'));
pin('tax_included holds them; tax and the total do not', array($m(41.76), 0, $m(499 + 118 + 50)), array($q->totals()['tax_included'], $q->totals()['tax'], $q->total()));

harness_section('the order and its invoice');
$address = array('name' => 'Asha', 'phone' => '9800000000', 'line1' => '1 Marine Drive', 'line2' => '', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'postcode' => '400001', 'country' => 'IN');
$order   = (new GoodsCheckout())->place($buyer, $cart, $address, 'standard', 'fake')['order'];
$lines   = OrderLineStore::ofOrder($order->getId());
$taxes   = array_values(array_filter($lines, static fn ($l) => $l['kind'] === 'tax'));
pin('tax lines are stored with amount 0 and the tax as their unit', array(array(0, $m(23.76)), array(0, $m(18))), array_map(static fn ($l) => array($l['amount'], $l['unit']), $taxes));
pin('with the rate and taxable value in their meta', array(500, $m(475.24), true), array($taxes[0]['meta']['bp'], $taxes[0]['meta']['base'], $taxes[0]['meta']['included']));
pin('so the lines still add up to the total', OrderStore::find($order->getId())->getAmount(), (int) array_sum(array_column($lines, 'amount')));

Billing::markPaid(OrderStore::find($order->getId()), 'ext-tax-1');
ob_start();
Receipts::render(OrderStore::find($order->getId()), 'https://example.test/', 'Back');
$html = (string) ob_get_clean();
check('the receipt is a tax invoice', str_contains($html, 'Tax invoice #'), $html);
check('with the seller\'s GSTIN and the place of supply', str_contains($html, 'GSTIN: 07AAACM1234A1Z5') && str_contains($html, 'Place of supply: Maharashtra, IN'));
check('a summary per rate with the taxable value and the code', str_contains($html, 'Taxable value at GST 5%') && str_contains($html, '475.24 INR') && str_contains($html, 'Tax code at 5%') && str_contains($html, '6109'));
pin('the tax amount shows once, on its line, not again in the summary', 1, substr_count($html, '23.76 INR (included)'));
check('and not core\'s "not a tax invoice" line', !str_contains($html, 'not a tax invoice') && str_contains($html, 'Thank you for your order.'));

osc_set_preference('seller_tax_id', '07<b>X', Plugin::PAGE);
osc_reset_preferences();
ob_start();
Receipts::render(OrderStore::find($order->getId()), 'https://example.test/', 'Back');
$html = (string) ob_get_clean();
check('what the admin typed is printed escaped', str_contains($html, '07&lt;b&gt;X') && !str_contains($html, '07<b>X'));

harness_section('exclusive tax');
osc_set_preference('prices_include_tax', '0', Plugin::PAGE, 'BOOLEAN');
osc_set_preference('tax_shipping', '1', Plugin::PAGE, 'BOOLEAN');
osc_reset_preferences();
$q = $quote(array('address' => CartPricer::quoteAddress('DE', '', '')));
// A German buyer: the tee keeps its category's 5% (24.95); the book and the 50 of delivery take 19% (22.42 + 9.50).
pin('added on top, delivery taxed too', array('tax.gst.5' => $m(24.95), 'tax.gst.19' => $m(31.92)), array_column($q->extraLines('tax'), 'amount', 'id'));
pin('the total includes it', array($m(56.87), 0, $m(499 + 118 + 50 + 56.87)), array($q->totals()['tax'], $q->totals()['tax_included'], $q->total()));
$placed = (new GoodsCheckout())->place($buyer, $cart, array('country' => 'DE', 'state' => 'Berlin', 'postcode' => '10115') + $address, 'standard', 'fake')['order'];
pin('the order charges it', $m(499 + 118 + 50 + 56.87), OrderStore::find($placed->getId())->getAmount());

harness_section('uninstall');
Plugin::uninstall();
pin('removes the settings', 0, (int) $admin->query('SELECT COUNT(*) c FROM ' . $p . "t_preference WHERE s_section = 'tax'")->fetch_assoc()['c']);

exit(harness_result());
