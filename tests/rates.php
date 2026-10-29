<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The rate table: percents typed by the admin, the stored JSON, which rate a line takes, and
 * a malformed table reading as none.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\tax\Parts;
use mindstellar\tax\Percent;
use mindstellar\tax\Rates;

harness_section('percent');
pin('18 is 1800 bp', 1800, Percent::parse('18'));
pin('12.5 is 1250', 1250, Percent::parse('12.5'));
pin('0,25 with a comma is 25', 25, Percent::parse('0,25'));
pin('"18 %" is 1800', 1800, Percent::parse('18 %'));
pin('100 is the most', 10000, Percent::parse('100'));
pin('over 100 is refused', null, Percent::parse('100.01'));
pin('three decimals are refused', null, Percent::parse('1.125'));
pin('a negative is refused', null, Percent::parse('-5'));
pin('words are refused', null, Percent::parse('eighteen'));
pin('back again', array('18', '12.5', '0.25', '0'), array(Percent::fromBp(1800), Percent::fromBp(1250), Percent::fromBp(25), Percent::fromBp(0)));
pin('a part of a rate: 1800 x 5000 / 1e6 is 9', '9', Percent::format(1800 * 5000, 1000000));
pin('25 x 5000 / 1e6 is 0.125', '0.125', Percent::format(25 * 5000, 1000000));

harness_section('the stored table');
$json  = '{"default_bp":1800,"by_country":{"DE":1900,"GB":2000},"by_category":{"12":{"bp":500,"code":"6109"},"30":{"bp":0}}}';
$rates = Rates::fromJson($json);
pin('reads', array(1800, array('DE' => 1900, 'GB' => 2000), array(12 => array('bp' => 500, 'code' => '6109'), 30 => array('bp' => 0, 'code' => null))), array($rates->defaultBp, $rates->byCountry, $rates->byCategory));
pin('and writes back the same', $json, $rates->toJson());
pin('an empty table writes objects, not lists', '{"default_bp":0,"by_country":{},"by_category":{}}', (new Rates(0))->toJson());
foreach (array(
    'nothing'               => '',
    'not JSON'              => '{default_bp:18',
    'a list'                => '[1800]',
    'no default'            => '{"by_country":{}}',
    'a string default'      => '{"default_bp":"1800"}',
    'a fraction'            => '{"default_bp":18.5}',
    'over 100%'             => '{"default_bp":10001}',
    'a negative'            => '{"default_bp":-1}',
    'a lower-case country'  => '{"default_bp":0,"by_country":{"de":1900}}',
    'a three-letter one'    => '{"default_bp":0,"by_country":{"DEU":1900}}',
    'a bad country rate'    => '{"default_bp":0,"by_country":{"DE":"19"}}',
    'a category id of 0'    => '{"default_bp":0,"by_category":{"0":{"bp":5}}}',
    'a named category'      => '{"default_bp":0,"by_category":{"tees":{"bp":5}}}',
    'a category with no bp' => '{"default_bp":0,"by_category":{"12":{"code":"6109"}}}',
    'a bare category rate'  => '{"default_bp":0,"by_category":{"12":500}}',
    'markup in a code'      => '{"default_bp":0,"by_category":{"12":{"bp":5,"code":"<b>"}}}',
    'by_country a string'   => '{"default_bp":0,"by_country":"DE"}',
) as $name => $bad) {
    pin('malformed, so no table: ' . $name, null, Rates::fromJson($bad));
}
pin('nor anything but a string', null, Rates::fromJson(array('default_bp' => 1800)));

harness_section('which rate a line takes');
$chainTee = array(12, 4, 1); // Tees, under Clothing, under Shop
pin('its category\'s', array('bp' => 500, 'code' => '6109', 'source' => 'category'), $rates->forLine($chainTee, 'DE'));
pin('a subcategory takes its parent\'s', array('bp' => 500, 'code' => '6109', 'source' => 'category'), $rates->forLine(array(99, 12, 4), 'DE'));
pin('its own wins over the parent\'s, even at 0%', array('bp' => 0, 'code' => null, 'source' => 'category'), $rates->forLine(array(30, 12), 'DE'));
pin('no category rate: the buyer country\'s', array('bp' => 1900, 'code' => null, 'source' => 'country'), $rates->forLine(array(77, 4), 'DE'));
pin('no country rate either: the default', array('bp' => 1800, 'code' => null, 'source' => 'default'), $rates->forLine(array(77), 'FR'));
pin('no country at all: the default', array('bp' => 1800, 'code' => null, 'source' => 'default'), $rates->forLine(array(), ''));
check('it knows when a category rate exists', $rates->hasCategoryRates() && !(new Rates(1800, array('DE' => 1900)))->hasCategoryRates());

harness_section('from the admin form');
[$form, $error] = Rates::fromForm('18', array(array('code' => ' de ', 'rate' => '19'), array('code' => '', 'rate' => '')), array(array('id' => '12', 'rate' => '5', 'code' => '6109'), array('id' => '', 'rate' => '', 'code' => '')));
pin('blank rows are skipped, codes upper-cased', array(null, '{"default_bp":1800,"by_country":{"DE":1900},"by_category":{"12":{"bp":500,"code":"6109"}}}'), array($error, $form->toJson()));
pin('an empty default is 0%', 0, Rates::fromForm('', array(), array())[0]->defaultBp);
foreach (array(
    'a bad default'           => array('18.5.1', array(), array()),
    'a bad country code'      => array('0', array(array('code' => 'Germany', 'rate' => '19')), array()),
    'a bad country rate'      => array('0', array(array('code' => 'DE', 'rate' => '190')), array()),
    'a country rate missing'  => array('0', array(array('code' => 'DE', 'rate' => '')), array()),
    'a country twice'         => array('0', array(array('code' => 'DE', 'rate' => '19'), array('code' => 'de', 'rate' => '7')), array()),
    'no category chosen'      => array('0', array(), array(array('id' => '', 'rate' => '5', 'code' => ''))),
    'a bad category rate'     => array('0', array(), array(array('id' => '3', 'rate' => 'five', 'code' => ''))),
    'a bad tax code'          => array('0', array(), array(array('id' => '3', 'rate' => '5', 'code' => 'a"b'))),
    'a category twice'        => array('0', array(), array(array('id' => '3', 'rate' => '5', 'code' => ''), array('id' => '3', 'rate' => '12', 'code' => ''))),
) as $name => [$d, $c, $k]) {
    [$table, $why] = Rates::fromForm($d, $c, $k);
    check('refused with a reason: ' . $name, $table === null && is_string($why) && $why !== '', (string) $why);
}

harness_section('the refused cell');
foreach (array(
    'a bad country code' => array(array('0', array(array('code' => 'DE', 'rate' => '1'), array('code' => 'Germany', 'rate' => '19')), array()), array('table' => 'country', 'row' => 1, 'column' => 'code')),
    'a bad country rate' => array(array('0', array(array('code' => 'FR', 'rate' => 'abc')), array()), array('table' => 'country', 'row' => 0, 'column' => 'rate')),
    'a country twice'    => array(array('0', array(array('code' => 'DE', 'rate' => '19'), array('code' => 'de', 'rate' => '7')), array()), array('table' => 'country', 'row' => 1, 'column' => 'code')),
    'no category'        => array(array('0', array(), array(array('id' => '', 'rate' => '5', 'code' => ''))), array('table' => 'category', 'row' => 0, 'column' => 'id')),
    'a bad category rate' => array(array('0', array(), array(array('id' => '3', 'rate' => 'five', 'code' => ''))), array('table' => 'category', 'row' => 0, 'column' => 'rate')),
    'a bad tax code'     => array(array('0', array(), array(array('id' => '3', 'rate' => '5', 'code' => 'a"b'))), array('table' => 'category', 'row' => 0, 'column' => 'code')),
    'a bad default'      => array(array('x', array(), array()), null),
) as $name => [$args, $cell]) {
    pin('names the cell: ' . $name, $cell, Rates::fromForm(...$args)[2]);
}
pin('a good table names none', null, Rates::fromForm('1', array(), array())[2]);

harness_section('the table after a refused save');
class Params
{
    public static array $p = array();

    public static function getParamString(string $k): string
    {
        return (string) (self::$p[$k] ?? '');
    }

    public static function getParamArray(string $k): array
    {
        return (array) (self::$p[$k] ?? array());
    }
}
class Category
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function listEnabled(): array
    {
        return array();
    }
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}
function osc_get_preference($k, $s)
{
    return '';
}
function osc_admin_section_heading($t, $k = '')
{
    echo '<h3>' . $t . '</h3>';
}
function osc_admin_field(array $f)
{
    $attrs = '';
    foreach ($f['attrs'] ?? array() as $k => $v) {
        $attrs .= ' ' . $k . '="' . htmlspecialchars((string) $v) . '"';
    }
    // Core's contract: an 'error' marks the control and prints the message under it.
    $error = (string) ($f['error'] ?? '');
    if ($error !== '') {
        $attrs .= ' aria-invalid="true" aria-describedby="' . $f['id'] . '-error"';
    }
    echo '<input name="' . $f['name'] . '" id="' . $f['id'] . '" value="' . htmlspecialchars((string) $f['value']) . '"' . $attrs . '>';
    if ($error !== '') {
        echo '<p class="field-error" id="' . $f['id'] . '-error">' . htmlspecialchars($error) . '</p>';
    }
}
$draw = static function (): string {
    ob_start();
    \mindstellar\tax\RatesField::render(array('values' => array('rates' => '{"default_bp":0,"by_country":{"DE":1900},"by_category":{}}')));

    return (string) ob_get_clean();
};
Params::$p = array('tax_default' => '0', 'tax_country_code' => array('FR', ''), 'tax_country_rate' => array('abc', ''));
\mindstellar\tax\RatesField::collect();
$page = $draw();
check('the typed row is drawn, not the saved one', str_contains($page, 'value="FR"') && str_contains($page, 'value="abc"') && !str_contains($page, 'value="DE"'), $page);
check('the wrong cell is marked and described', preg_match('/id="tax-country-rate-0"[^>]*aria-invalid="true"/', $page) === 1 && str_contains($page, 'aria-describedby="tax-country-rate-0-error"'), $page);
check('the message sits under the cell', str_contains($page, 'class="field-error" id="tax-country-rate-0-error"') && str_contains($page, 'The rate for FR must be a percent'), $page);
check('the other cells are not marked', substr_count($page, 'aria-invalid') === 1, $page);
Params::$p = array('tax_default' => '0', 'tax_country_code' => array('FR', ''), 'tax_country_rate' => array('20', ''));
\mindstellar\tax\RatesField::collect();
$page = $draw();
check('after a good save nothing is marked and the saved rows show', !str_contains($page, 'aria-invalid') && !str_contains($page, 'field-error') && str_contains($page, 'value="DE"'), $page);

$marked = static fn (string $page, string $id): bool => preg_match('/id="' . $id . '"[^>]*aria-invalid="true" aria-describedby="' . $id . '-error"/', $page) === 1
    && substr_count($page, 'aria-invalid') === 1;
Params::$p = array('tax_default' => '0', 'tax_category_id' => array('12', ''), 'tax_category_rate' => array('abc', ''), 'tax_category_code' => array('6109', ''));
\mindstellar\tax\RatesField::collect();
$page = $draw();
check('a refused category row keeps the typed category, rate and code', preg_match('/id="tax-category-id-0" value="12"/', $page) === 1 && str_contains($page, 'value="abc"') && str_contains($page, 'value="6109"'), $page);
check('its rate cell is marked', $marked($page, 'tax-category-rate-0'), $page);
check('with the message under it', str_contains($page, 'id="tax-category-rate-0-error">Each category rate must be a percent'), $page);
Params::$p = array('tax_default' => '0', 'tax_category_id' => array('', ''), 'tax_category_rate' => array('5', ''), 'tax_category_code' => array('', ''));
\mindstellar\tax\RatesField::collect();
$page = $draw();
check('a rate with no category marks the category cell', $marked($page, 'tax-category-id-0') && str_contains($page, 'id="tax-category-id-0-error">Choose a category'), $page);
check('and keeps the typed rate', preg_match('/id="tax-category-rate-0" value="5"/', $page) === 1, $page);
$drawStored = static function (): string {
    ob_start();
    \mindstellar\tax\RatesField::render(array('values' => array('rates' => '{"default_bp":1800,"by_country":{},"by_category":{}}')));

    return (string) ob_get_clean();
};
Params::$p = array('tax_default' => 'x', 'tax_country_code' => array('FR', ''), 'tax_country_rate' => array('20', ''));
\mindstellar\tax\RatesField::collect();
$page = $drawStored();
check('a refused default keeps the typed default and rows', preg_match('/id="tax-default" value="x"/', $page) === 1 && str_contains($page, 'value="FR"'), $page);
Params::$p = array('tax_default' => '5', 'tax_country_code' => array(''), 'tax_country_rate' => array(''));
\mindstellar\tax\RatesField::collect();
$page = $drawStored();
check('after a good save the stored default shows', preg_match('/id="tax-default" value="18"/', $page) === 1, $page);
Params::$p = array('tax_default' => '0', 'tax_country_code' => array('FR', '', ''), 'tax_country_rate' => array('abc', '', ''));
\mindstellar\tax\RatesField::collect();
$page = $draw();
check('posted blank rows are not drawn twice: one typed row and three blanks', str_contains($page, 'id="tax-country-rate-3"') && !str_contains($page, 'id="tax-country-rate-4"'), $page);

harness_section('parts');
pin('one part named after the tax', array(array('id' => 'gst', 'label' => 'GST', 'share' => 10000)), Parts::single('GST'));
pin('a name with no letters or digits gets the id "tax"', 'tax', Parts::single('%%')[0]['id']);
pin('an empty name reads as Tax', 'Tax', Parts::single('  ')[0]['label']);
$default = Parts::single('VAT');
$two     = array(array('id' => 'a', 'label' => 'A', 'share' => 6000), array('id' => 'b', 'label' => 'B', 'share' => 4000));
pin('a well-formed answer is taken', $two, Parts::normalise($two, $default));
foreach (array(
    'not a list'         => 'x',
    'empty'              => array(),
    'shares under 10000' => array(array('id' => 'a', 'label' => 'A', 'share' => 5000)),
    'shares over'        => array(array('id' => 'a', 'label' => 'A', 'share' => 6000), array('id' => 'b', 'label' => 'B', 'share' => 6000)),
    'a share as text'    => array(array('id' => 'a', 'label' => 'A', 'share' => '10000')),
    'a bad id'           => array(array('id' => 'A-1', 'label' => 'A', 'share' => 10000)),
    'the same id twice'  => array(array('id' => 'a', 'label' => 'A', 'share' => 5000), array('id' => 'a', 'label' => 'B', 'share' => 5000)),
    'no label'           => array(array('id' => 'a', 'label' => ' ', 'share' => 10000)),
    'five parts'         => array_fill(0, 5, array('id' => 'a', 'label' => 'A', 'share' => 2000)),
) as $name => $bad) {
    pin('anything else keeps the single part: ' . $name, $default, Parts::normalise($bad, $default));
}

exit(harness_result());
