<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The buyer's tax number: normalised, format-checked only, and open to a country plugin through
 * `tax_buyer_id`. Then the checkout listeners on a stand-in core.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\tax\BuyerId;
use mindstellar\tax\Config;
use mindstellar\tax\Listener;

$ctx = array('seller_country' => 'DE', 'country' => 'DE', 'state' => '', 'same_country' => true);

harness_section('normalise');
pin('spaces, dots and dashes go, letters are upper-cased', 'DE123456789', BuyerId::normalise(' de 123.456-789 '));
pin('nothing typed is nothing', '', BuyerId::normalise('  '));

harness_section('check');
pin('a plain number is valid', array('valid' => true, 'value' => 'DE123456789', 'message' => '', 'label' => 'VAT number', 'ask' => true), BuyerId::check('de 123 456 789', $ctx, 'VAT number'));
pin('nothing typed is valid: the field is optional', true, BuyerId::check('', $ctx, 'VAT number')['valid']);
pin('4 characters are enough', true, BuyerId::check('AB12', $ctx, 'Tax number')['valid']);
pin('3 are not', false, BuyerId::check('AB1', $ctx, 'Tax number')['valid']);
pin('21 are too many', false, BuyerId::check(str_repeat('A', 21), $ctx, 'Tax number')['valid']);
pin('other characters are not allowed', false, BuyerId::check('DE12<b>34', $ctx, 'Tax number')['valid']);
check('a refusal says what is wanted', str_contains(BuyerId::check('x', $ctx, 'Tax number')['message'], '4 to 20'));

harness_section('the tax_buyer_id filter');
$seen = null;
osc_add_filter('tax_buyer_id', static function ($check, $raw, $context) use (&$seen) {
    $seen = array($check['value'], $raw, $context['country']);
    if (($context['country'] ?? '') !== 'IN') {
        return $check;
    }
    $value = strtoupper(str_replace(' ', '', (string) $raw));

    return array('label' => 'GSTIN', 'valid' => strlen($value) === 15, 'value' => $value, 'message' => strlen($value) === 15 ? '' : 'Enter a GSTIN.') + $check;
});
BuyerId::check(' de-1234 ', $ctx, 'Tax number');
pin('it sees the check, what was typed and the context', array('DE1234', ' de-1234 ', 'DE'), $seen);
$in = array('country' => 'IN') + $ctx;
pin('a country plugin may relabel and refuse', array('valid' => false, 'value' => '27AAPFU0939F1Z', 'message' => 'Enter a GSTIN.', 'label' => 'GSTIN', 'ask' => true), BuyerId::check('27aapfu0939f1z', $in, 'Tax number'));
pin('and accept its own format', true, BuyerId::check('27AAPFU0939F1ZV', $in, 'Tax number')['valid']);
reset_filters();
osc_add_filter('tax_buyer_id', static fn () => 'nonsense');
pin('an answer that is not a check is ignored', true, BuyerId::check('DE1234', $ctx, 'Tax number')['valid']);
reset_filters();
osc_add_filter('tax_buyer_id', static fn ($check) => array('valid' => 'yes', 'value' => array(), 'label' => '', 'ask' => 0) + $check);
pin('bad keys keep their defaults', array('valid' => true, 'value' => 'DE1234', 'message' => '', 'label' => 'Tax number', 'ask' => true), BuyerId::check('DE1234', $ctx, 'Tax number'));
reset_filters();
osc_add_filter('tax_buyer_id', static fn ($check) => array('valid' => false, 'message' => '') + $check);
check('a refusal with no message gets the default one', BuyerId::check('DE1234', $ctx, 'Tax number')['message'] !== '');
reset_filters();
osc_add_filter('tax_buyer_id', static fn ($check) => array('ask' => false) + $check);
pin('a plugin may stop the field being asked', false, BuyerId::check('', $ctx, 'Tax number')['ask']);
reset_filters();

harness_section('the checkout listeners on a stand-in core');
eval('namespace mindstellar\billing\event; final class CheckoutFields {
    public array $fields = array();
    public function __construct(private array $cart) {}
    public function cartArray(): array { return $this->cart; }
    public function addField(string $id, string $label, bool $required = false, int $max = 64, string $help = "", bool $invoice = true, string $autocomplete = ""): void {
        $this->fields[] = compact("id", "label", "required", "max", "help", "invoice", "autocomplete");
    }
}');
eval('namespace mindstellar\billing\event; final class CheckoutStarting {
    public array $refusals = array();
    public function __construct(private array $cart) {}
    public function cartArray(): array { return $this->cart; }
    public function field(string $id): string { return (string) ($this->cart["fields"][$id] ?? ""); }
    public function setField(string $id, string $value): void {
        if (!array_key_exists($id, $this->cart["fields"] ?? array())) { throw new \InvalidArgumentException("no field"); }
        $this->cart["fields"][$id] = $value;
    }
    public function refuse(string $pointer, string $code, string $message): void { $this->refusals[] = array($pointer, $code, $message); }
}');
$GLOBALS['settings'] = array('seller_country' => 'DE', 'ask_buyer_tax_id' => 'off', 'buyer_tax_id_label' => 'VAT number');
function osc_settings_value($page, $name)
{
    return $GLOBALS['settings'][$name] ?? '';
}
$cart     = array('address' => array('country' => 'DE'), 'currency' => 'EUR', 'user_id' => 3, 'lines' => array());
$listener = new Listener();
$fields   = new mindstellar\billing\event\CheckoutFields($cart);
$listener->onFields($fields);
pin('off by default: no field', array(), $fields->fields);
$start = new mindstellar\billing\event\CheckoutStarting($cart + array('fields' => array('tax_id' => 'x')));
$listener->onStarting($start);
pin('and nothing checked', array(), $start->refusals);

$GLOBALS['settings']['ask_buyer_tax_id'] = 'optional';
$fields = new mindstellar\billing\event\CheckoutFields($cart);
$listener->onFields($fields);
pin('optional: one field, on the invoice', array(array('id' => 'tax_id', 'label' => 'VAT number', 'required' => false, 'max' => BuyerId::MAX_INPUT, 'help' => $fields->fields[0]['help'] ?? '', 'invoice' => true, 'autocomplete' => '')), $fields->fields);
$GLOBALS['settings']['buyer_tax_id_label'] = '';
$fields = new mindstellar\billing\event\CheckoutFields($cart);
$listener->onFields($fields);
pin('a blank label setting falls back to Tax number', 'Tax number', $fields->fields[0]['label']);

$start = new mindstellar\billing\event\CheckoutStarting($cart + array('fields' => array('tax_id' => 'de 123.456.789')));
$listener->onStarting($start);
pin('a good number is stored normalised', array(array(), 'DE123456789'), array($start->refusals, $start->field('tax_id')));
$start = new mindstellar\billing\event\CheckoutStarting($cart + array('fields' => array('tax_id' => 'D!')));
$listener->onStarting($start);
pin('a bad one is refused at its field', array('/fields/tax_id', 'invalid'), array_slice($start->refusals[0] ?? array(), 0, 2));
$start = new mindstellar\billing\event\CheckoutStarting($cart + array('fields' => array()));
$listener->onStarting($start);
pin('an empty one is fine', array(), $start->refusals);

osc_add_filter('tax_buyer_id', static fn ($check) => array('ask' => false) + $check);
$fields = new mindstellar\billing\event\CheckoutFields($cart);
$listener->onFields($fields);
pin('a country plugin that says not to ask hides the field', array(), $fields->fields);
reset_filters();

exit(harness_result());
