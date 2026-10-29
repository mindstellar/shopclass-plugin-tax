<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The invoice laid over core's receipt details: title, the seller's tax number, place of
 * supply, a summary per rate with its codes, the discount note, and what a tax_invoice
 * listener may change.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\tax\Config;
use mindstellar\tax\Invoice;

$money  = static fn (int $micros): string => number_format($micros / 1000000, 2) . ' INR';
$config = new Config(true, null, 'GST', false, 'IN', 'Delhi', '07AAACM1234A1Z5', 'GSTIN', 'Tax invoice', '');
$taxes  = array(
    array('kind' => 'tax', 'title' => 'GST 5%', 'unit' => m(23.81), 'amount' => 0, 'meta' => array('bp' => 500, 'part' => 'gst', 'base' => m(476.19), 'included' => true, 'lines' => array(array('code' => '6109'), array('code' => '6110'), array('code' => '6109')))),
    array('kind' => 'tax', 'title' => 'GST 18%', 'unit' => m(15.25), 'amount' => 0, 'meta' => array('bp' => 1800, 'part' => 'gst', 'base' => m(84.75), 'included' => true, 'lines' => array(array('code' => null)))),
);
$address = array('name' => 'Asha', 'state' => 'Maharashtra', 'country' => 'IN');
$core    = array('title' => 'Receipt', 'extra' => array(), 'summary' => array(), 'footer' => '', 'lines' => array('kept'));

harness_section('built from the order');
$invoice = Invoice::build($config, $taxes, $address, false);
pin('title, tax number and place of supply', array('Tax invoice', 'GSTIN', '07AAACM1234A1Z5', 'Maharashtra, IN'), array($invoice['title'], $invoice['tax_id_label'], $invoice['tax_id'], $invoice['place_of_supply']));
pin('a rate row per tax line, codes once each', array('label' => 'GST 5%', 'bp' => 500, 'part' => 'gst', 'base' => m(476.19), 'tax' => m(23.81), 'included' => true, 'codes' => array('6109', '6110')), $invoice['rates'][0]);
pin('an empty footer reads as a thank-you, so core\'s "not a tax invoice" line never shows', 'Thank you for your order.', $invoice['footer']);

$shown = Invoice::apply($core, $invoice, $money);
pin('the receipt takes the title and footer', array('Tax invoice', 'Thank you for your order.'), array($shown['title'], $shown['footer']));
pin('the seller block gains the tax number and place of supply', array(array('label' => 'GSTIN', 'value' => '07AAACM1234A1Z5'), array('label' => 'Place of supply', 'value' => 'Maharashtra, IN')), $shown['extra']);
pin('the summary: taxable value per rate, not the tax again, then codes per rate', array(
    array('label' => 'Taxable value at GST 5%', 'value' => '476.19 INR'),
    array('label' => 'Taxable value at GST 18%', 'value' => '84.75 INR'),
    array('label' => 'Tax code at 5%', 'value' => '6109, 6110'),
), $shown['summary']);
pin('nothing else of core\'s changes', array('kept'), $shown['lines']);

harness_section('discounts, missing parts');
$shown = Invoice::apply($core, Invoice::build($config, $taxes, $address, true), $money);
pin('a discounted order says tax was worked out before it', array('label' => 'Note', 'value' => 'Tax is worked out on the prices before any discount.'), end($shown['summary']));
$bare  = new Config(false, null);
$added = array_replace_recursive($taxes[1], array('amount' => m(15.25), 'meta' => array('included' => false)));
$shown = Invoice::apply($core, Invoice::build($bare, array($added), array(), false), $money);
pin('no tax number and no address: no rows for them', array(), $shown['extra']);
pin('additive tax: the summary gives its taxable value', array('label' => 'Taxable value at GST 18%', 'value' => '84.75 INR'), $shown['summary'][0]);
pin('defaults for the title', 'Tax invoice', $shown['title']);

harness_section('a tax_invoice listener');
$invoice                 = Invoice::build($config, $taxes, $address, false);
$invoice['code_label']   = 'HSN';
$invoice['tax_id_label'] = 'GSTIN of supplier';
$invoice['extra'][]      = array('label' => 'State code', 'value' => '07');
$invoice['summary'][]    = array('label' => 'Total tax', 'value' => '39.06 INR');
$invoice['extra'][]      = array('label' => 'dropped', 'value' => 5);
$shown                   = Invoice::apply($core, $invoice, $money);
pin('may rename the labels and add rows after the plugin\'s own; a malformed row is dropped', array(
    array('GSTIN of supplier', 'Place of supply', 'State code'),
    array('Taxable value at GST 5%', 'Taxable value at GST 18%', 'HSN at 5%', 'Total tax'),
), array(array_column($shown['extra'], 'label'), array_column($shown['summary'], 'label')));
$core['extra'] = array(array('label' => 'From another plugin', 'value' => 'x'));
pin('rows another receipt listener set first are kept', 'From another plugin', Invoice::apply($core, $invoice, $money)['extra'][0]['label']);

exit(harness_result());
