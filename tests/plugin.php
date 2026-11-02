<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The cart_tax_lines listener with core's cart shape and a stand-in category tree: which rate
 * each line takes, shipping, the address, and the three extension filters.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\tax\Config;
use mindstellar\tax\Context;
use mindstellar\tax\Plugin;
use mindstellar\tax\Rates;

$rates  = new Rates(1800, array('DE' => 1900), array(4 => array('bp' => 500, 'code' => '6109')));
$config = static fn (array $over = array()): Config => new Config(
    $over['inclusive'] ?? false,
    array_key_exists('rates', $over) ? $over['rates'] : $rates,
    'GST',
    $over['shipping'] ?? false,
    'IN',
    'Delhi'
);
// Item 1 is in Tees (12) under Clothing (4); item 2 in Books (20).
$asked  = array();
$chains = static function (array $ids) use (&$asked): array {
    $asked[] = $ids;

    return array_intersect_key(array(12 => array(12, 4), 20 => array(20)), array_flip($ids));
};
$cart = static fn (array $over = array()): array => $over + array(
    'lines'          => array(
        array('line' => 0, 'item_id' => 1, 'category_id' => 12, 'title' => 'Tee', 'qty' => 2, 'unit' => m(250), 'amount' => m(500)),
        array('line' => 1, 'item_id' => 2, 'category_id' => 20, 'title' => 'Book', 'qty' => 1, 'unit' => m(100), 'amount' => m(100)),
    ),
    'address'        => array(),
    'currency'       => 'INR',
    'user_id'        => 5,
    'subtotal'       => m(600),
    'shipping_lines' => array(array('id' => 'shipping.standard', 'label' => 'Standard', 'amount' => m(50), 'currency' => 'INR', 'meta' => array())),
);
$byId = static function (array $lines): array {
    $out = array();
    foreach ($lines as $line) {
        $out[$line['id']] = $line['amount'];
    }

    return $out;
};

harness_section('rates per line');
$out = Plugin::lines(array(), $cart(), $config(), $chains);
pin('the tee takes its parent category\'s 5%, the book the seller country\'s default 18%', array('tax.gst.5' => m(25), 'tax.gst.18' => m(18)), $byId($out));
pin('lines carry the cart currency', array('INR', 'INR'), array_column($out, 'currency'));
pin('the category code is on the line', '6109', $out[0]['meta']['lines'][0]['code']);
pin('the category tree was asked once, for both items', array(array(12, 20)), $asked);

$asked = array();
$out   = Plugin::lines(array(), $cart(array('address' => array('country' => 'de'))), $config(), $chains);
pin('a German buyer: the book takes the German rate, the tee still its category\'s', array('tax.gst.5' => m(25), 'tax.gst.19' => m(19)), $byId($out));

$asked = array();
Plugin::lines(array(), $cart(), $config(array('rates' => new Rates(1800))), $chains);
pin('no category rates: the tree is not asked at all', array(), $asked);

harness_section('inclusive');
$out = Plugin::lines(array(), $cart(), $config(array('inclusive' => true)), $chains);
// 500 / 1.05 = 476.19 -> 23.81; 100 / 1.18 = 84.75 -> 15.25.
pin('inclusive: tax already in the prices, marked so', array(array('tax.gst.5' => m(23.81), 'tax.gst.18' => m(15.25)), array(true, true)), array($byId($out), array_column(array_column($out, 'meta'), 'included')));

harness_section('discounts');
// The task's case: ₹1,098 at 18% included, ₹150 off, so tax is 18/118 of ₹948.
$one  = new Rates(1800);
$shop = $cart(array(
    'lines'          => array(array('line' => 0, 'item_id' => 1, 'category_id' => 0, 'title' => 'Lamp', 'qty' => 1, 'unit' => m(1098), 'amount' => m(1098))),
    'subtotal'       => m(1098),
    'shipping_lines' => array(),
    'discount_lines' => array(array('id' => 'discount.first', 'label' => 'First order', 'amount' => -m(150), 'currency' => 'INR', 'meta' => array())),
));
$out = Plugin::lines(array(), $shop, $config(array('inclusive' => true, 'rates' => $one)), $chains);
pin('included tax is on the price after the discount', array('tax.gst.18' => m(144.61)), $byId($out));
pin('the line says the discount was taken off first', true, $out[0]['meta']['after_discount'] ?? null);
$out = Plugin::lines(array(), $shop, $config(array('rates' => $one)), $chains);
pin('added tax too', array('tax.gst.18' => m(170.64)), $byId($out));
// 500 + 100 + 50 shipping, 60 off the order: a tenth of each item, nothing off the shipping.
$out = Plugin::lines(array(), $cart(array('discount_lines' => array(array('amount' => -m(60))))), $config(array('shipping' => true)), $chains);
pin('an order discount is shared over the items by value, not the shipping', array('tax.gst.5' => m(22.50), 'tax.gst.18' => m(25.20)), $byId($out));
$out = Plugin::lines(array(), $cart(array('discount_lines' => array(array('amount' => -m(60))))), $config(), $chains);
pin('untaxed shipping takes no share either', array('tax.gst.5' => m(22.50), 'tax.gst.18' => m(16.20)), $byId($out));
$freeShip = array('discount_lines' => array(array('id' => 'promo.ship', 'amount' => -m(50), 'meta' => array('applies_to' => 'shipping'))));
$out = Plugin::lines(array(), $cart($freeShip), $config(array('shipping' => true)), $chains);
pin('a free delivery discount comes off the taxed shipping only', array('tax.gst.5' => m(25), 'tax.gst.18' => m(18)), $byId($out));
$out = Plugin::lines(array(), $cart($freeShip), $config(), $chains);
pin('and leaves the items alone when shipping is not taxed', array('tax.gst.5' => m(25), 'tax.gst.18' => m(18)), $byId($out));
pin('a discount off untaxed shipping changes no tax, so no mark', false, isset($out[0]['meta']['after_discount']));
// The 8010 cases: a 499 tee, 18% included, 79 delivery not taxed.
$tee = static fn (array $off): array => $cart(array(
    'lines'          => array(array('line' => 0, 'item_id' => 1, 'category_id' => 0, 'title' => 'Tee', 'qty' => 1, 'unit' => m(499), 'amount' => m(499))),
    'subtotal'       => m(499),
    'shipping_lines' => array(array('id' => 'shipping.standard', 'label' => 'Standard', 'amount' => m(79))),
    'discount_lines' => $off,
));
$incl = $config(array('inclusive' => true, 'rates' => $one));
$out  = Plugin::lines(array(), $tee(array(array('amount' => -m(79), 'meta' => array('applies_to' => 'shipping')))), $incl, $chains);
pin('free delivery on a 499 tee: tax on the full 499', array('tax.gst.18' => m(76.12)), $byId($out));
$out = Plugin::lines(array(), $tee(array(array('amount' => -m(100)))), $incl, $chains);
pin('100 off a 499 tee with paid delivery: tax on 399', array(m(60.86), m(338.14)), array($out[0]['amount'], $out[0]['meta']['base']));
$out = Plugin::lines(array(), $cart(array('discount_lines' => array(array('amount' => -m(33.333333))))), $config(array('inclusive' => true)), $chains);
check('an odd discount still gives tax in whole paise', array_filter($out, static fn (array $l): bool => $l['amount'] % 10000 !== 0) === array());
$out = Plugin::lines(array(), $cart(array('discount_lines' => array(array('amount' => -m(9000))))), $config(), $chains);
pin('a discount bigger than the cart leaves nothing to tax', array(), $byId($out));
pin('no discount: no after_discount mark', false, isset(Plugin::lines(array(), $cart(), $config(), $chains)[0]['meta']['after_discount']));

harness_section('shipping');
pin('not taxed unless the setting is on', 2, count(Plugin::lines(array(), $cart(), $config(), $chains)));
$out = Plugin::lines(array(), $cart(), $config(array('shipping' => true)), $chains);
pin('on: at the country rate, else the default (18% of 50 joins the 18% line)', array('tax.gst.5' => m(25), 'tax.gst.18' => m(27)), $byId($out));
pin('as a shipping entry, with the shipping line\'s id', array('kind' => 'shipping', 'line' => null, 'item_id' => null, 'code' => null, 'base' => m(50), 'tax' => m(9), 'ref' => 'shipping.standard'), $out[1]['meta']['lines'][1]);
$free = $cart(array('shipping_lines' => array(array('id' => 'shipping.standard', 'label' => 'Standard', 'amount' => 0))));
pin('free delivery is not taxed', array('tax.gst.5' => m(25), 'tax.gst.18' => m(18)), $byId(Plugin::lines(array(), $free, $config(array('shipping' => true)), $chains)));

harness_section('what is kept and what gives no lines');
$other = array(array('id' => 'tax.levy', 'label' => 'Levy', 'amount' => m(1)));
pin('another listener\'s lines stay first', 'tax.levy', Plugin::lines($other, $cart(), $config(), $chains)[0]['id']);
pin('a malformed or missing table adds nothing', $other, Plugin::lines($other, $cart(), $config(array('rates' => null)), $chains));
pin('an empty cart adds nothing', array(), Plugin::lines(array(), $cart(array('lines' => array(), 'shipping_lines' => array())), $config(), $chains));
pin('a line with no amount adds nothing', array(), Plugin::lines(array(), $cart(array('lines' => array(array('item_id' => 9, 'amount' => 0)), 'shipping_lines' => array())), $config(), $chains));
pin('the hook passes anything but arrays through', 'x', Plugin::taxLines('x', array()));

harness_section('extension: tax_line_rate');
$seen = array();
osc_add_filter('tax_line_rate', static function ($rate, $line, $ctx) use (&$seen) {
    $seen[] = array($line['kind'], $line['item_id'], $line['category_id'], $rate['source'], $ctx['state']);

    return $line['item_id'] === 2 ? array('bp' => 1200, 'code' => '4901') : $rate;
});
$out = Plugin::lines(array(), $cart(), $config(array('shipping' => true)), $chains);
pin('it sees each line with its category, the table\'s rate and the context', array(array('item', 1, 12, 'category', 'Delhi'), array('item', 2, 20, 'default', 'Delhi'), array('shipping', null, 0, 'default', 'Delhi')), $seen);
pin('and may change a line\'s rate and code', array('tax.gst.5' => m(25), 'tax.gst.12' => m(12), 'tax.gst.18' => m(9)), $byId($out));
pin('the code it gave is on the line', '4901', $out[1]['meta']['lines'][0]['code']);
reset_filters();
osc_add_filter('tax_line_rate', static fn ($rate) => array('bp' => 500, 'code' => str_repeat('a', 50)));
pin('a code from the filter is cut to 40 characters', str_repeat('a', 40), Plugin::lines(array(), $cart(), $config(), $chains)[0]['meta']['lines'][0]['code']);
reset_filters();
foreach (array('a string' => 'x', 'a bp as text' => array('bp' => '1200'), 'over 100%' => array('bp' => 10001), 'a negative' => array('bp' => -1)) as $name => $bad) {
    osc_add_filter('tax_line_rate', static fn () => $bad);
    pin('an answer that is not a rate is ignored: ' . $name, array('tax.gst.5' => m(25), 'tax.gst.18' => m(18)), $byId(Plugin::lines(array(), $cart(), $config(), $chains)));
    reset_filters();
}

harness_section('extension: tax_parts');
$ctxSeen = null;
osc_add_filter('tax_parts', static function ($parts, $ctx) use (&$ctxSeen) {
    $ctxSeen = $ctx;

    return $ctx['same_state']
        ? array(array('id' => 'state', 'label' => 'State tax', 'share' => 5000), array('id' => 'local', 'label' => 'Local tax', 'share' => 5000))
        : $parts;
});
$out = Plugin::lines(array(), $cart(array('address' => array('state' => ' delhi ', 'country' => 'IN', 'postcode' => '110017'))), $config(), $chains);
pin('a buyer in the seller\'s state, by the filter: two halves per rate', array('tax.state.2.5' => m(12.5), 'tax.local.2.5' => m(12.5), 'tax.state.9' => m(9), 'tax.local.9' => m(9)), $byId($out));
pin('the context it saw', array('seller_country' => 'IN', 'seller_state' => 'Delhi', 'country' => 'IN', 'state' => 'delhi', 'postcode' => '110017', 'same_country' => true, 'same_state' => true, 'inclusive' => false, 'currency' => 'INR', 'user_id' => 5), $ctxSeen);
$out = Plugin::lines(array(), $cart(array('address' => array('state' => 'Maharashtra', 'country' => 'IN'))), $config(), $chains);
pin('another state: one part', array('tax.gst.5', 'tax.gst.18'), array_column($out, 'id'));
$out = Plugin::lines(array(), $cart(), $config(), $chains);
pin('no address yet: the seller\'s state is assumed', 4, count($out));
reset_filters();
osc_add_filter('tax_parts', static fn () => array(array('id' => 'a', 'label' => 'A', 'share' => 9000)));
pin('parts that do not sum to the whole are ignored', array('tax.gst.5', 'tax.gst.18'), array_column(Plugin::lines(array(), $cart(), $config(), $chains), 'id'));
reset_filters();

harness_section('context');
$ctx = Context::build($config(), array('country' => 'India', 'state' => 'Tamil  NADU'), 'inr', 0);
pin('a country that is not a code falls back to the seller\'s', 'IN', $ctx['country']);
pin('states compare without case or extra spaces', true, Context::fold('Tamil  NADU') === Context::fold(' tamil nadu'));
pin('a different state is not the same', false, $ctx['same_state']);
pin('a seller with no state never matches a buyer\'s', false, Context::build(new Config(false, null), array('state' => 'Delhi'), 'INR', 0)['same_state']);

exit(harness_result());
