<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The tax maths against hand-worked cases: inclusive and exclusive, rounding half up to the
 * currency's smallest unit once per line, parts that sum to the whole, and no drift over a
 * full cart.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\tax\Calculator;
use mindstellar\tax\Currency;
use mindstellar\tax\Parts;

$inr  = Currency::step('INR');
$incl = new Calculator(true, $inr);
$excl = new Calculator(false, $inr);

harness_section('inclusive');
// 499 / 1.18 = 422.8813..., so the base is 422.88 and the tax 76.12.
pin('499 at 18% holds 76.12 of tax on a base of 422.88', array('base' => m(422.88), 'tax' => m(76.12)), $incl->line(m(499), 1800));
pin('118 at 18% holds exactly 18', array('base' => m(100), 'tax' => m(18)), $incl->line(m(118), 1800));
pin('base and tax always add up to the price', m(999.99), array_sum($incl->line(m(999.99), 1250)));
// 0.05 / 1.05 = 0.047619 -> base 0.05, tax 0.00; 1.00 / 1.05 = 0.952380 -> 0.95, tax 0.05.
pin('a price too small to hold a paisa of tax holds none', array('base' => m(0.05), 'tax' => 0), $incl->line(m(0.05), 500));
pin('1 at 5% holds 0.05', array('base' => m(0.95), 'tax' => m(0.05)), $incl->line(m(1), 500));

harness_section('exclusive');
pin('1 at 18% adds 0.18', array('base' => m(1), 'tax' => m(0.18)), $excl->line(m(1), 1800));
// 0.99 x 0.18 = 0.1782 -> 0.18.
pin('0.99 at 18% rounds 0.1782 up to 0.18', m(0.18), $excl->line(m(0.99), 1800)['tax']);
// 0.25 x 0.18 = 0.045: exactly half a paisa, which rounds up, not to even.
pin('a half paisa rounds up', m(0.05), $excl->line(m(0.25), 1800)['tax']);
pin('just under a half rounds down', m(0.04), $excl->line(m(0.24), 1800)['tax']);
// 3 x 0.99 = 2.97 x 0.18 = 0.5346 -> 0.53; per unit it would be 3 x 0.18 = 0.54.
pin('a line of three is rounded once, not per unit', m(0.53), $excl->line(m(2.97), 1800)['tax']);
pin('0% adds nothing', array('base' => m(10), 'tax' => 0), $excl->line(m(10), 0));
pin('a rate over 100% is held at 100%', m(10), $excl->line(m(10), 25000)['tax']);
pin('a negative amount is no amount', array('base' => 0, 'tax' => 0), $excl->line(-m(10), 1800));

harness_section('other currencies');
$jpy = new Calculator(false, Currency::step('JPY'));
pin('yen are rounded to whole yen: 1234 at 10% is 123', m(123), $jpy->line(m(1234), 1000)['tax']);
pin('1235 at 10% is 124 (half up)', m(124), $jpy->line(m(1235), 1000)['tax']);
$kwd = new Calculator(false, Currency::step('kwd'));
pin('dinars keep three decimals: 1.235 at 5% is 0.062', 62000, $kwd->line(1235000, 500)['tax']);
pin('USD has two', 10000, Currency::step('USD'));

harness_section('large amounts');
$big = 9_000_000_000_000_000; // 9 billion units in micros, near PHP_INT_MAX / 1000
pin('exclusive does not overflow', 1_620_000_000_000_000, $excl->line($big, 1800)['tax']);
$huge = $incl->line($big, 1800);
check('inclusive does not overflow, and still adds up', $huge['base'] + $huge['tax'] === $big && $huge['base'] === 7_627_118_644_070_000, describe($huge));

harness_section('parts');
pin('halves of an even tax', array(m(9), m(9)), $excl->split(m(18), array(5000, 5000)));
pin('halves of an odd one: the extra paisa goes to the first', array(m(0.13), m(0.12)), $excl->split(m(0.25), array(5000, 5000)));
pin('one part is the whole tax', array(m(76.12)), $excl->split(m(76.12), array(10000)));
pin('thirds by largest remainder', array(m(0.34), m(0.33), m(0.33)), $excl->split(m(1), array(3334, 3333, 3333)));
pin('a remainder below one step goes to the first part', array(m(0.12) + 3, m(0.12)), $excl->split(m(0.24) + 3, array(5000, 5000)));
pin('uneven shares: 70/30 of 0.99', array(m(0.69), m(0.30)), $excl->split(m(0.99), array(7000, 3000)));
foreach (array(1, 7, 99, 101, 12345, 99999) as $paise) {
    $tax = $paise * $inr;
    check('split parts sum to the whole: ' . $paise . ' paise', array_sum($excl->split($tax, array(5000, 5000))) === $tax && array_sum($excl->split($tax, array(2500, 2500, 5000))) === $tax);
}

harness_section('lines for a cart');
$gst   = Parts::single('GST');
$lines = $incl->lines(array(
    array('kind' => 'item', 'line' => 0, 'item_id' => 7, 'code' => '6109', 'amount' => m(499), 'bp' => 1800),
    array('kind' => 'item', 'line' => 1, 'item_id' => 8, 'code' => null, 'amount' => m(100), 'bp' => 500),
    array('kind' => 'item', 'line' => 2, 'item_id' => 9, 'code' => null, 'amount' => m(118), 'bp' => 1800),
    array('kind' => 'item', 'line' => 3, 'item_id' => 10, 'code' => null, 'amount' => m(50), 'bp' => 0),
), $gst);
pin('one line per rate, lowest rate first, none for 0%', array('tax.gst.5', 'tax.gst.18'), array_column($lines, 'id'));
pin('labelled with the tax name and percent', array('GST 5%', 'GST 18%'), array_column($lines, 'label'));
pin('the 18% line sums its items: 76.12 + 18', m(94.12), $lines[1]['amount']);
pin('and their bases: 422.88 + 100', m(522.88), $lines[1]['meta']['base']);
pin('marked included', array(true, true), array_column(array_column($lines, 'meta'), 'included'));
pin('the meta lists each line with its code', array(array('kind' => 'item', 'line' => 0, 'item_id' => 7, 'code' => '6109', 'base' => m(422.88), 'tax' => m(76.12)), array('kind' => 'item', 'line' => 2, 'item_id' => 9, 'code' => null, 'base' => m(100), 'tax' => m(18))), $lines[1]['meta']['lines']);
pin('and the rate and part', array(1800, 'gst', 10000), array($lines[1]['meta']['bp'], $lines[1]['meta']['part'], $lines[1]['meta']['share']));
pin('additive lines say so', false, $excl->lines(array(array('amount' => m(1), 'bp' => 1800)), $gst)[0]['meta']['included']);

$halves = array(array('id' => 'a', 'label' => 'State tax', 'share' => 5000), array('id' => 'b', 'label' => 'City tax', 'share' => 5000));
$split  = $excl->lines(array(array('amount' => m(0.25), 'bp' => 1800), array('amount' => m(0.25), 'bp' => 1800), array('amount' => m(10), 'bp' => 1250)), $halves);
pin('split parts: one line per rate and part, labelled with the part\'s percent', array('tax.a.6.25' => 'State tax 6.25%', 'tax.b.6.25' => 'City tax 6.25%', 'tax.a.9' => 'State tax 9%', 'tax.b.9' => 'City tax 9%'), array_combine(array_column($split, 'id'), array_column($split, 'label')));
pin('each line split on its own, so 0.05 + 0.05 becomes 0.06 + 0.04', array(m(0.06), m(0.04)), array($split[2]['amount'], $split[3]['amount']));
pin('the parts of a rate share its base', array(m(0.5), m(0.5)), array($split[2]['meta']['base'], $split[3]['meta']['base']));

harness_section('no drift over a full cart');
mt_srand(20261018);
$cart = array();
for ($i = 0; $i < 50; $i++) {
    $cart[] = array('kind' => 'item', 'line' => $i, 'item_id' => $i + 1, 'amount' => mt_rand(1, 2_000_000) * $inr, 'bp' => array(0, 500, 1200, 1800, 2800, 1250)[$i % 6]);
}
foreach (array('inclusive' => $incl, 'exclusive' => $excl) as $mode => $calc) {
    $expected = 0;
    $bases    = 0;
    foreach ($cart as $entry) {
        $one       = $calc->line($entry['amount'], $entry['bp']);
        $expected += $one['tax'];
        $bases    += $entry['bp'] > 0 ? $one['base'] : 0;
    }
    foreach (array('one part' => $gst, 'two parts' => $halves) as $name => $parts) {
        $out = $calc->lines($cart, $parts);
        pin($mode . ', ' . $name . ': the lines sum to the per-line taxes to the micro', $expected, (int) array_sum(array_column($out, 'amount')));
        check($mode . ', ' . $name . ': every amount is whole paise', array_filter($out, static fn ($l) => $l['amount'] % 10000 !== 0) === array());
    }
    pin($mode . ': the bases sum too', $bases, (int) array_sum(array_column(array_column($calc->lines($cart, $gst), 'meta'), 'base')));
}
$sum = 0;
foreach ($cart as $entry) {
    $one  = $incl->line($entry['amount'], $entry['bp']);
    $sum += $one['base'] + $one['tax'] - $entry['amount'];
}
pin('inclusive: base + tax is the price on every line', 0, $sum);

harness_section('step floor');
pin('a step of 0 counts as 1', array('base' => 7, 'tax' => 1), (new Calculator(false, 0))->line(7, 1800));
pin('a negative step counts as 1', array('base' => 7, 'tax' => 1), (new Calculator(false, -5))->line(7, 1800));

exit(harness_result());
