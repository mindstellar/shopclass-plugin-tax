<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * index.php on a core without shop mode stays off and says so; on one with it, it hooks
 * the cart and the receipt.
 */

require __DIR__ . '/lib/bootstrap.php';

$GLOBALS['hooks'] = array();
function osc_register_plugin($path, $fn)
{
}
function osc_plugin_path($file)
{
    return 'tax/index.php';
}
function osc_add_hook($hook, $fn, $priority = 5)
{
    $GLOBALS['hooks'][$hook][] = $fn;
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

harness_section('an older core');
require __DIR__ . '/../index.php';
pin('only the uninstall hook and a notice', array('tax/index.php_uninstall', 'admin_page_header'), array_keys($GLOBALS['hooks']));
pin('no cart or receipt filter', array(), $GLOBALS['filters']);
ob_start();
($GLOBALS['hooks']['admin_page_header'][0])();
check('the notice says why', str_contains((string) ob_get_clean(), 'needs Shopclass 7.1 or later with shop mode'));

harness_section('a core with shop mode');
eval('namespace mindstellar\billing; final class CartPricer { public const PROBLEM_POINTERS = array(); }');
eval('namespace mindstellar\billing\event; final class Line {
    public function __construct(public string $id, public string $label, public int $amount, public string $currency, public array $meta = array()) {}
}');
eval('namespace mindstellar\billing\event; final class CartTaxLines {
    public array $lines = array();
    public function __construct(private array $cart) {}
    public function cartArray(): array { return $this->cart; }
    public function rawLines() { return $this->lines; }
    public function addLine(Line $line): void { $this->lines[] = $line->id; }
}');
eval('namespace mindstellar\billing\event; final class CheckoutStarting {}');
$GLOBALS['pages'] = array();
function osc_register_settings_page($id, $spec)
{
    $GLOBALS['pages'][$id] = $spec;
}
function osc_db_select($sql, $params = array())
{
    return array();
}
$GLOBALS['hooks'] = array();
require __DIR__ . '/../index.php';
pin('the cart and receipt filters', array('cart_tax_lines', 'billing_receipt_details'), array_keys($GLOBALS['filters']));
pin('and the settings page', array('tax'), array_keys($GLOBALS['pages']));
pin('with its groups', array('Prices', 'Seller', 'Buyer', 'Rates', 'Invoice'), array_column($GLOBALS['pages']['tax']['groups'], 'title'));
pin('no admin notice', false, isset($GLOBALS['hooks']['admin_page_header']));
pin('no checkout field or document hook before checkout fields came', false, isset($GLOBALS['hooks']['cart_checkout_before']));

harness_section('the tax listener on that core');
function osc_settings_value($page, $name)
{
    return array('rates' => '{"default_bp":1800,"by_country":{},"by_category":{}}', 'tax_label' => 'VAT', 'seller_country' => 'DE')[$name] ?? '';
}
$event = new mindstellar\billing\event\CartTaxLines(array('currency' => 'EUR', 'lines' => array(array('line' => 0, 'item_id' => 1, 'qty' => 1, 'amount' => m(100)))));
(new mindstellar\tax\Listener())->onTaxLines($event);
pin('adds its tax line and calls nothing the core lacks', array('tax.vat.18'), $event->lines);

exit(harness_result());
