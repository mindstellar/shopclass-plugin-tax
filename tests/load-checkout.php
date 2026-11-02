<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * index.php on a core with checkout fields and line tax hooks the checkout and the invoice too.
 */

require __DIR__ . '/lib/bootstrap.php';

$GLOBALS['hooks'] = array();
$GLOBALS['pages'] = array();
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
function osc_register_settings_page($id, $spec)
{
    $GLOBALS['pages'][$id] = $spec;
}
function osc_db_select($sql, $params = array())
{
    return array();
}
eval('namespace mindstellar\billing; final class CartPricer { public const PROBLEM_POINTERS = array(); }');
eval('namespace mindstellar\billing\event; final class CartTaxLines {}');
eval('namespace mindstellar\billing\event; final class CheckoutFields {}');
eval('namespace mindstellar\billing\event; final class DocumentComposing {}');
eval('namespace mindstellar\billing\event; final class CheckoutStarting { public function field(string $id): string { return ""; } }');

harness_section('a core with checkout fields');
require __DIR__ . '/../index.php';
pin('the cart, receipt, checkout field and document filters', array('cart_tax_lines', 'billing_receipt_details', 'checkout_fields', 'billing_document_compose'), array_keys($GLOBALS['filters']));
pin('and the checkout check', array('tax/index.php_uninstall', 'tax/index.php_configure', 'cart_checkout_before'), array_keys($GLOBALS['hooks']));
pin('the settings gain a Buyer group', array('Prices', 'Seller', 'Buyer', 'Rates', 'Invoice'), array_column($GLOBALS['pages']['tax']['groups'], 'title'));
pin('asking for the buyer\'s tax number is off by default', 'off', $GLOBALS['pages']['tax']['groups'][2]['fields'][0]['default']);

exit(harness_result());
