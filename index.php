<?php
/*
Plugin Name: Tax
Plugin URI: https://github.com/mindstellar/shopclass-plugin-tax
Description: Tax on every cart and order, for any country: a default rate, rates by buyer country and by category, prices with or without tax, and the receipt as a tax invoice. Other plugins can change the rate of a line, split a tax into parts and add to the invoice.
Version: 0.1.2
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: tax
Requires Shopclass: 7.1.0
Tested up to: 7.1
Requires PHP: 8.2
Support URI: https://github.com/mindstellar/shopclass-plugin-tax/issues
Modes: shop
*/

/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\tax\Listener;
use mindstellar\tax\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\tax\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// The tax and receipt events came with Shopclass 7.1's shop mode.
if (!class_exists('mindstellar\\billing\\CartPricer')
    || !defined('mindstellar\\billing\\CartPricer::PROBLEM_POINTERS')
    || !class_exists('mindstellar\\billing\\event\\CartTaxLines')
    || !function_exists('osc_register_settings_page')
    || !function_exists('osc_db_select')
) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Tax needs Shopclass 7.1 or later with shop mode and is switched off. Update Shopclass to use it.', 'tax'))
            . '</div>';
    }, 10);

    return;
}

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Plugin::PAGE));
});

osc_add_filter('cart_tax_lines', array(Listener::class, 'onTaxLines'), 10);
osc_add_filter('billing_receipt_details', array(Listener::class, 'onReceipt'), 10);
