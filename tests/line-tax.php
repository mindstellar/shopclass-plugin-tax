<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The tax of each item and shipping line, split from the order's tax lines: for every tax line the
 * parts naming it add up to its amount, as core checks before it keeps them. Then the listener on a
 * stand-in core that takes line tax.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\tax\Calculator;
use mindstellar\tax\Config;
use mindstellar\tax\Plugin;
use mindstellar\tax\Rates;

$rates  = new Rates(1800, array('DE' => 1900), array(4 => array('bp' => 500, 'code' => '6109')));
$config = static fn (array $over = array()): Config => new Config($over['inclusive'] ?? false, $over['rates'] ?? $rates, 'GST', $over['shipping'] ?? false, 'IN', 'Delhi');
$chains = static fn (array $ids): array => array_intersect_key(array(12 => array(12, 4), 20 => array(20)), array_flip($ids));
$cart   = static fn (array $over = array()): array => $over + array(
    'lines'          => array(
        array('line' => 0, 'item_id' => 1, 'category_id' => 12, 'title' => 'Tee', 'qty' => 2, 'unit' => m(249.5), 'amount' => m(499)),
        array('line' => 1, 'item_id' => 2, 'category_id' => 20, 'title' => 'Book', 'qty' => 1, 'unit' => m(118), 'amount' => m(118)),
        array('line' => 3, 'item_id' => 3, 'category_id' => 20, 'title' => 'Map', 'qty' => 3, 'unit' => m(33.33), 'amount' => m(99.99)),
    ),
    'address'        => array(),
    'currency'       => 'INR',
    'user_id'        => 5,
    'shipping_lines' => array(array('id' => 'shipping.standard', 'label' => 'Standard', 'amount' => m(49.99), 'currency' => 'INR', 'meta' => array())),
);
$halves = static fn () => array(array('id' => 'cgst', 'label' => 'CGST', 'share' => 5000), array('id' => 'sgst', 'label' => 'SGST', 'share' => 5000));

/** What core checks: per tax line, the parts naming it sum to its amount with the same included flag. */
function sums_match(array $taxLines, array $perLine): bool
{
    $sums = array();
    foreach (array_merge(array_values($perLine['item']), array_values($perLine['shipping'])) as $tax) {
        foreach ($tax['parts'] as $part) {
            $sums[$part['tax']] = ($sums[$part['tax']] ?? 0) + $part['amount'];
        }
        foreach ($taxLines as $line) {
            if (in_array($line['id'], array_column($tax['parts'], 'tax'), true) && $line['meta']['included'] !== $tax['included']) {
                return false;
            }
        }
    }
    foreach ($taxLines as $line) {
        if (($sums[$line['id']] ?? 0) !== $line['amount']) {
            return false;
        }
        unset($sums[$line['id']]);
    }

    return $sums === array();
}

harness_section('inclusive, one part');
$lines = Plugin::lines(array(), $cart(), $config(array('inclusive' => true)), $chains);
$per   = Calculator::perLine($lines);
check('the parts sum to every tax line', sums_match($lines, $per), json_encode($per));
// The tee is at 5%: 499 holds 23.76 on a base of 475.24.
pin('the tee\'s own tax', array('included' => true, 'base' => m(475.24), 'code' => '6109', 'parts' => array(array('tax' => 'tax.gst.5', 'label' => 'GST 5%', 'bp' => 500, 'amount' => m(23.76)))), $per['item'][0]);
pin('each item line by its cart position', array(0, 1, 3), array_keys($per['item']));
pin('untaxed shipping has none', array(), $per['shipping']);

harness_section('on top, taxed shipping');
$lines = Plugin::lines(array(), $cart(), $config(array('shipping' => true)), $chains);
$per   = Calculator::perLine($lines);
check('the parts sum to every tax line', sums_match($lines, $per), json_encode($per));
pin('the shipping line by its id, at the default 18%', array('included' => false, 'base' => m(49.99), 'code' => null, 'parts' => array(array('tax' => 'tax.gst.18', 'label' => 'GST 18%', 'bp' => 1800, 'amount' => m(9)))), $per['shipping']['shipping.standard'] ?? null);
pin('the shipping entry in the tax line meta carries the id', 'shipping.standard', $lines[1]['meta']['lines'][2]['ref'] ?? null);

harness_section('two parts');
osc_add_filter('tax_parts', $halves);
foreach (array('inclusive' => true, 'on top' => false) as $name => $inclusive) {
    $lines = Plugin::lines(array(), $cart(), $config(array('inclusive' => $inclusive, 'shipping' => true)), $chains);
    $per   = Calculator::perLine($lines);
    check($name . ': the parts sum to every tax line', sums_match($lines, $per), json_encode($per));
    pin($name . ': the book pays half of 18% to each', array(array('tax.cgst.9', 'CGST 9%', 900), array('tax.sgst.9', 'SGST 9%', 900)), array_map(static fn ($p) => array($p['tax'], $p['label'], $p['bp']), $per['item'][1]['parts']));
}
// 0.25 at 18% on top is 0.05 of tax, split 0.03 + 0.02 by the line, as the tax lines are.
$lines = Plugin::lines(array(), $cart(array('lines' => array(array('line' => 0, 'item_id' => 2, 'category_id' => 20, 'amount' => m(0.25))), 'shipping_lines' => array())), $config(), $chains);
$per   = Calculator::perLine($lines);
pin('an odd paisa goes to the first part, on the line and on the tax lines', array(m(0.03), m(0.02)), array_column($per['item'][0]['parts'], 'amount'));
check('and still sums', sums_match($lines, $per));
reset_filters();
osc_add_filter('tax_parts', static fn () => array(array('id' => 'a', 'label' => 'A', 'share' => 3334), array('id' => 'b', 'label' => 'B', 'share' => 3333), array('id' => 'c', 'label' => 'C', 'share' => 3333)));
$lines = Plugin::lines(array(), $cart(), $config(array('shipping' => true)), $chains);
check('three uneven parts still sum', sums_match(Plugin::lines(array(), $cart(), $config(array('shipping' => true)), $chains), Calculator::perLine($lines)));
pin('a part\'s rate is rounded to whole basis points: a third of 18%', 600, Calculator::perLine($lines)['item'][1]['parts'][0]['bp']);
reset_filters();

harness_section('discounts');
$off = array('discount_lines' => array(array('amount' => -m(61.17)), array('amount' => -m(20), 'meta' => array('applies_to' => 'shipping'))));
foreach (array('inclusive' => true, 'on top' => false) as $name => $inclusive) {
    $lines = Plugin::lines(array(), $cart($off), $config(array('inclusive' => $inclusive, 'shipping' => true)), $chains);
    $per   = Calculator::perLine($lines);
    check($name . ': after a discount the parts sum to every tax line', sums_match($lines, $per), json_encode($per));
    pin($name . ': and the bases are what is left after it', array_sum(array_column(array_column($lines, 'meta'), 'base')), array_sum(array_column(array_merge($per['item'], $per['shipping']), 'base')));
}

harness_section('a full cart');
mt_srand(20261102);
$big = array();
for ($i = 0; $i < 50; $i++) {
    $big[] = array('line' => $i, 'item_id' => $i + 1, 'category_id' => array(12, 20)[$i % 2], 'qty' => 1, 'amount' => mt_rand(1, 500_000) * 100);
}
osc_add_filter('tax_parts', $halves);
foreach (array(true, false) as $inclusive) {
    $lines = Plugin::lines(array(), $cart(array('lines' => $big, 'discount_lines' => array(array('amount' => -m(123.45))))), $config(array('inclusive' => $inclusive, 'shipping' => true)), $chains);
    check(($inclusive ? 'inclusive' : 'on top') . ': 50 lines with odd amounts sum to the micro', sums_match($lines, Calculator::perLine($lines)));
}
reset_filters();

harness_section('what gives no line tax');
pin('no tax lines, no line tax', array('item' => array(), 'shipping' => array()), Calculator::perLine(array()));
pin('tax lines of another plugin (no meta lines) are skipped', array('item' => array(), 'shipping' => array()), Calculator::perLine(array(array('id' => 'tax.levy', 'label' => 'Levy', 'amount' => m(1), 'meta' => array()))));

harness_section('the listener on a core that takes line tax');
eval('namespace mindstellar\billing; final class LineTax {
    public int $amount;
    public function __construct(public bool $included, public int $base, public array $parts, public ?string $code = null) {
        if ($parts === array() || count($parts) > 4) { throw new \InvalidArgumentException("parts"); }
        $this->amount = array_sum(array_column($parts, "amount"));
    }
}');
eval('namespace mindstellar\billing\event; final class Line {
    public const MAX_PER_EVENT = 20;
    public function __construct(public string $id, public string $label, public int $amount, public string $currency, public array $meta = array()) {}
}');
eval('namespace mindstellar\billing\event; final class CartTaxLines {
    public array $lines = array();
    public array $set = array();
    public function __construct(private array $cart) {}
    public function cartArray(): array { return $this->cart; }
    public function rawLines() { return $this->lines; }
    public function addLine(Line $line): void {
        if (count($this->lines) >= Line::MAX_PER_EVENT) { throw new \InvalidArgumentException("full"); }
        $this->lines[] = array("id" => $line->id, "amount" => $line->amount, "meta" => $line->meta);
    }
    public function setLineTax(string $kind, int|string $ref, \mindstellar\billing\LineTax $tax): void { $this->set[] = array($kind, $ref, $tax); }
}');
$GLOBALS['settings'] = array('prices_include_tax' => true, 'tax_shipping' => true, 'tax_label' => 'GST', 'seller_country' => 'IN', 'rates' => (new Rates(1800))->toJson());
function osc_settings_value($page, $name)
{
    return $GLOBALS['settings'][$name] ?? '';
}
$event = new mindstellar\billing\event\CartTaxLines($cart(array('lines' => array_map(static fn ($l) => $l + array('category_id' => 20), array_slice($cart()['lines'], 1)))));
(new mindstellar\tax\Listener())->onTaxLines($event);
pin('the tax lines are added', array('tax.gst.18'), array_column($event->lines, 'id'));
pin('and each line\'s tax is set by kind and ref', array(array('item', 1), array('item', 3), array('shipping', 'shipping.standard')), array_map(static fn ($s) => array($s[0], $s[1]), $event->set));
pin('summing to the tax line', $event->lines[0]['amount'], array_sum(array_map(static fn ($s) => $s[2]->amount, $event->set)));

$GLOBALS['filters'] = array();
osc_add_filter('tax_parts', static fn () => array(array('id' => 'a', 'label' => 'A', 'share' => 2500), array('id' => 'b', 'label' => 'B', 'share' => 2500), array('id' => 'c', 'label' => 'C', 'share' => 2500), array('id' => 'd', 'label' => 'D', 'share' => 2500)));
osc_add_filter('tax_line_rate', static fn ($rate, $line) => array('bp' => 100 * (1 + ($line['line'] ?? 9) % 6), 'code' => null));
$six   = array();
for ($i = 0; $i < 6; $i++) {
    $six[] = array('line' => $i, 'item_id' => $i + 1, 'category_id' => 20, 'qty' => 1, 'amount' => m(100));
}
$event = new mindstellar\billing\event\CartTaxLines($cart(array('lines' => $six, 'shipping_lines' => array())));
(new mindstellar\tax\Listener())->onTaxLines($event);
pin('six rates of four parts: the event holds the first 20 tax lines, no error', 20, count($event->lines));
pin('and no line tax, which could not add up', array(), $event->set);
reset_filters();

exit(harness_result());
