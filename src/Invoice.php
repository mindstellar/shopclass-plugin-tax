<?php

/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\tax;

/**
 * What the receipt page shows when an order carries this plugin's tax: the invoice title, the
 * seller's tax number, the place of supply, and a summary of the taxable value and tax per
 * rate. Plain text throughout; core's template escapes it.
 */
final class Invoice
{
    /**
     * The invoice the `tax_invoice` filter receives.
     *
     * @param array<int,array<string,mixed>> $taxLines the order's tax lines from this plugin
     * @param array<string,mixed>            $address  the order's delivery address
     *
     * @return array<string,mixed>
     */
    public static function build(Config $config, array $taxLines, array $address, bool $hasDiscount): array
    {
        $rates = array();
        foreach ($taxLines as $line) {
            $meta  = is_array($line['meta'] ?? null) ? $line['meta'] : array();
            // Tax worked out before the discount (an older core) is the case the note explains.
            $hasDiscount = $hasDiscount && ($meta['after_discount'] ?? false) !== true;
            $codes = array();
            foreach (is_array($meta['lines'] ?? null) ? $meta['lines'] : array() as $entry) {
                if (is_array($entry) && is_string($entry['code'] ?? null) && $entry['code'] !== '') {
                    $codes[$entry['code']] = true;
                }
            }
            $rates[] = array(
                'label'    => (string) ($line['title'] ?? ''),
                'bp'       => (int) ($meta['bp'] ?? 0),
                'part'     => (string) ($meta['part'] ?? ''),
                'base'     => (int) ($meta['base'] ?? 0),
                'tax'      => (int) ($line['unit'] ?? 0),
                'included' => ($meta['included'] ?? false) === true,
                'codes'    => array_map('strval', array_keys($codes)),
            );
        }
        $place = array();
        foreach (array('state', 'country') as $key) {
            if (is_string($address[$key] ?? null) && trim($address[$key]) !== '') {
                $place[] = trim($address[$key]);
            }
        }

        return array(
            'title'           => $config->invoiceTitle !== '' ? $config->invoiceTitle : __('Tax invoice', 'tax'),
            'tax_id_label'    => $config->taxIdLabel !== '' ? $config->taxIdLabel : __('Tax number', 'tax'),
            'tax_id'          => $config->sellerTaxId,
            'place_label'     => __('Place of supply', 'tax'),
            'place_of_supply' => implode(', ', $place),
            'code_label'      => __('Tax code', 'tax'),
            'rates'           => $rates,
            'discounted'      => $hasDiscount,
            'extra'           => array(),
            'summary'         => array(),
            'footer'          => $config->invoiceFooter !== '' ? $config->invoiceFooter : __('Thank you for your order.', 'tax'),
        );
    }

    /**
     * The receipt details with the invoice laid over them. `extra` and `summary` rows a filter
     * added come after the plugin's own.
     *
     * @param array<string,mixed>  $details from core's `billing_receipt_details`
     * @param array<string,mixed>  $invoice from build(), after the `tax_invoice` filter
     * @param callable(int):string $money   formats micros in the order's currency
     *
     * @return array<string,mixed>
     */
    public static function apply(array $details, array $invoice, callable $money): array
    {
        $text  = static fn ($v): string => is_string($v) ? $v : '';
        $extra = array();
        if ($text($invoice['tax_id'] ?? null) !== '') {
            $extra[] = array('label' => $text($invoice['tax_id_label'] ?? null), 'value' => $invoice['tax_id']);
        }
        if ($text($invoice['place_of_supply'] ?? null) !== '') {
            $extra[] = array('label' => $text($invoice['place_label'] ?? null), 'value' => $invoice['place_of_supply']);
        }

        $summary = array();
        $codes   = array();
        foreach (is_array($invoice['rates'] ?? null) ? $invoice['rates'] : array() as $rate) {
            if (!is_array($rate)) {
                continue;
            }
            // The tax itself is on the receipt's lines; the summary gives the taxable value only.
            $summary[] = array(
                'label' => sprintf(__('Taxable value at %s', 'tax'), $text($rate['label'] ?? null)),
                'value' => $money((int) ($rate['base'] ?? 0)),
            );
            foreach (is_array($rate['codes'] ?? null) ? $rate['codes'] : array() as $code) {
                if (is_string($code) && $code !== '') {
                    $codes[(int) ($rate['bp'] ?? 0)][$code] = true;
                }
            }
        }
        ksort($codes);
        foreach ($codes as $bp => $list) {
            $summary[] = array(
                'label' => sprintf(__('%1$s at %2$s%%', 'tax'), $text($invoice['code_label'] ?? null), Percent::fromBp($bp)),
                'value' => implode(', ', array_map('strval', array_keys($list))),
            );
        }
        if (!empty($invoice['discounted'])) {
            $summary[] = array('label' => __('Note', 'tax'), 'value' => __('Tax is worked out on the prices before any discount.', 'tax'));
        }

        $details['title']   = $text($invoice['title'] ?? null);
        $details['extra']   = array_merge(self::rows($details['extra'] ?? null), $extra, self::rows($invoice['extra'] ?? null));
        $details['summary'] = array_merge(self::rows($details['summary'] ?? null), $summary, self::rows($invoice['summary'] ?? null));
        $details['footer']  = $text($invoice['footer'] ?? null);

        return $details;
    }

    /**
     * @param mixed $rows
     *
     * @return array<int,array{label:string,value:string}>
     */
    private static function rows($rows): array
    {
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (is_array($row) && is_string($row['label'] ?? null) && is_string($row['value'] ?? null)) {
                $out[] = array('label' => $row['label'], 'value' => $row['value']);
            }
        }

        return $out;
    }
}
