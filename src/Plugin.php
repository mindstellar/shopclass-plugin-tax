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

use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\settings\SettingsPageRegistry;

/**
 * The hooks: tax lines on every cart quote and order, and the receipt as a tax invoice. The
 * maths is Calculator's and Rates'; this class only reads the settings and the cart and
 * runs the extension filters.
 */
final class Plugin
{
    public const PAGE = 'tax';

    public static function install(): void
    {
    }

    /**
     * Remove the stored settings.
     */
    public static function uninstall(): void
    {
        foreach (array_keys(SettingsPageRegistry::getInstance()->fields(self::PAGE)) as $name) {
            osc_delete_preference($name, self::PAGE);
        }
    }

    /**
     * `cart_tax_lines`: add this plugin's lines to what is there.
     *
     * @param mixed $lines
     * @param mixed $cart
     *
     * @return mixed
     */
    public static function taxLines($lines, $cart = array())
    {
        if (!is_array($lines) || !is_array($cart)) {
            return $lines;
        }

        return self::lines($lines, $cart, Config::fromSettings(), array(self::class, 'categoryChains'));
    }

    /**
     * The tax lines for a cart.
     *
     * @param array<int,mixed>                   $lines      lines other listeners added
     * @param array<string,mixed>                $cart       core's cart
     * @param callable(int[]):array<int,int[]>   $categoryOf category ids => category chain per category id
     *
     * @return array<int,mixed>
     */
    public static function lines(array $lines, array $cart, Config $config, callable $categoryOf): array
    {
        if ($config->rates === null) {
            return $lines;
        }
        $currency = strtoupper((string) ($cart['currency'] ?? ''));
        $address  = is_array($cart['address'] ?? null) ? $cart['address'] : array();
        $context  = Context::build($config, $address, $currency, (int) ($cart['user_id'] ?? 0));
        $items    = array_values(array_filter(is_array($cart['lines'] ?? null) ? $cart['lines'] : array(), 'is_array'));
        $chains   = $config->rates->hasCategoryRates()
            ? $categoryOf(array_values(array_unique(array_filter(array_map(static fn (array $l): int => (int) ($l['category_id'] ?? 0), $items)))))
            : array();

        $taxable = array();
        foreach ($items as $item) {
            $itemId = (int) ($item['item_id'] ?? 0);
            $chain  = $chains[(int) ($item['category_id'] ?? 0)] ?? array();
            $entry  = array(
                'kind'        => 'item',
                'line'        => (int) ($item['line'] ?? 0),
                'item_id'     => $itemId,
                'category_id' => (int) ($chain[0] ?? 0),
                'title'       => (string) ($item['title'] ?? ''),
                'qty'         => (int) ($item['qty'] ?? 0),
                'amount'      => (int) ($item['amount'] ?? 0),
            );
            $taxable[] = $entry + self::rate($config->rates->forLine($chain, $context['country']), $entry, $context);
        }
        $shipping = array();
        foreach (is_array($cart['shipping_lines'] ?? null) ? $cart['shipping_lines'] : array() as $ship) {
            $amount = is_array($ship) ? (int) ($ship['amount'] ?? 0) : 0;
            if ($amount <= 0 || !$config->taxShipping) {
                continue;
            }
            $entry      = array('kind' => 'shipping', 'line' => null, 'item_id' => null, 'category_id' => 0, 'title' => (string) ($ship['label'] ?? ''), 'qty' => 1, 'amount' => $amount);
            $shipping[] = $entry + self::rate($config->rates->forLine(array(), $context['country']), $entry, $context);
        }
        // An order discount lowers the items; one marked for shipping (free delivery) only the shipping.
        $off = array('items' => 0, 'shipping' => 0);
        foreach (is_array($cart['discount_lines'] ?? null) ? $cart['discount_lines'] : array() as $line) {
            if (is_array($line)) {
                $off[self::appliesTo($line)] += max(0, -(int) ($line['amount'] ?? 0));
            }
        }
        $step       = Currency::step($currency);
        $discounted = ($off['items'] > 0 && $taxable !== array()) || ($off['shipping'] > 0 && $shipping !== array());
        $taxable    = array_merge(self::afterDiscount($taxable, $off['items'], $step), self::afterDiscount($shipping, $off['shipping'], $step));

        $default = Parts::single($config->label);
        $parts   = Parts::normalise(osc_apply_filter('tax_parts', $default, $context), $default);
        foreach ((new Calculator($config->inclusive, $step))->lines($taxable, $parts) as $line) {
            if ($discounted) {
                $line['meta']['after_discount'] = true;
            }
            $lines[] = $line + array('currency' => $currency);
        }

        return $lines;
    }

    /**
     * What a discount line comes off: `shipping` when its meta says `applies_to: shipping`,
     * else the items.
     *
     * @param array<string,mixed> $line
     */
    public static function appliesTo(array $line): string
    {
        $meta = is_array($line['meta'] ?? null) ? $line['meta'] : array();

        return ($meta['applies_to'] ?? $line['applies_to'] ?? null) === 'shipping' ? 'shipping' : 'items';
    }

    /**
     * The entries with a discount taken off, shared over them by value in whole steps of the
     * currency. The last entry takes the rest, so the shares add up to the discount.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<int,array<string,mixed>>
     */
    private static function afterDiscount(array $entries, int $discount, int $step): array
    {
        $total = array_sum(array_map(static fn (array $e): int => max(0, (int) $e['amount']), $entries));
        if ($total <= 0 || $discount <= 0) {
            return $entries;
        }
        $discount = min($discount, $total);
        $left     = $discount;
        $last     = array_key_last($entries);
        foreach ($entries as $i => $entry) {
            $amount = max(0, (int) $entry['amount']);
            $share  = $i === $last ? $left : min($left, $amount, Calculator::mulDivRound($discount, $amount, $total * $step) * $step);
            $left  -= $share;
            $entries[$i]['amount'] = max(0, $amount - $share);
        }

        return $entries;
    }

    /**
     * `tax_line_rate` over the table's rate: a listener may return another {bp, code}.
     *
     * @param array{bp:int,code:?string,source:string} $rate
     * @param array<string,mixed>                      $entry
     * @param array<string,mixed>                      $context
     *
     * @return array{bp:int,code:?string}
     */
    private static function rate(array $rate, array $entry, array $context): array
    {
        $asked = osc_apply_filter('tax_line_rate', $rate, $entry, $context);
        $bp    = is_array($asked) ? ($asked['bp'] ?? null) : null;
        $code  = is_array($asked) ? ($asked['code'] ?? null) : null;
        if (!is_int($bp) || $bp < 0 || $bp > Calculator::MAX_BP) {
            return array('bp' => $rate['bp'], 'code' => $rate['code']);
        }

        return array('bp' => $bp, 'code' => is_string($code) && $code !== '' ? mb_substr($code, 0, 40, 'UTF-8') : null);
    }

    /**
     * `billing_receipt_details`: an order taxed by this plugin prints as a tax invoice.
     *
     * @param mixed $details
     * @param mixed $order
     *
     * @return mixed
     */
    public static function receiptDetails($details, $order = null)
    {
        if (!is_array($details) || !$order instanceof Order || !$order->isGoods()) {
            return $details;
        }
        $lines = OrderLineStore::ofOrder($order->getId());
        $taxes = array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === OrderLineStore::KIND_TAX && isset($l['meta']['part'], $l['meta']['bp'])));
        if ($taxes === array()) {
            return $details;
        }
        $config     = Config::fromSettings();
        $fulfilment = $order->fulfilment() ?? array();
        $address    = is_array($fulfilment['address'] ?? null) ? $fulfilment['address'] : array();
        $discounted = array_filter($lines, static fn (array $l): bool => $l['kind'] === OrderLineStore::KIND_DISCOUNT) !== array();
        $context    = Context::build($config, $address, $order->getCurrency(), $order->getUserId());
        $invoice    = osc_apply_filter('tax_invoice', Invoice::build($config, $taxes, $address, $discounted), $order, $context);
        $currency   = $order->getCurrency();

        return Invoice::apply($details, is_array($invoice) ? $invoice : array(), self::money($currency));
    }

    /**
     * Formats micros as core's receipt does, e.g. "₹380.59"; older cores without that formatter give "380.59 INR".
     *
     * @return \Closure(int):string
     */
    private static function money(string $currency): \Closure
    {
        if (method_exists('mindstellar\\currency\\CurrencyService', 'money')) {
            $service = \mindstellar\currency\CurrencyService::make();

            return static fn (int $m): string => $service->money($m, $currency);
        }

        return static fn (int $m): string => \mindstellar\currency\Money::format($m, $currency);
    }

    /**
     * Each category and its parents, nearest first, from core's category tree.
     *
     * @param int[] $categoryIds
     *
     * @return array<int,int[]> chain by category id
     */
    public static function categoryChains(array $categoryIds): array
    {
        $out = array();
        foreach ($categoryIds as $id) {
            $out[$id] = $id > 0 ? self::chain($id) : array();
        }

        return $out;
    }

    /**
     * @return int[] the category and its parents, nearest first
     */
    private static function chain(int $categoryId): array
    {
        $chain = array();
        foreach (\Category::getInstance()->hierarchy($categoryId) as $row) {
            if (is_array($row) && isset($row['pk_i_id'])) {
                $chain[] = (int) $row['pk_i_id'];
            }
        }

        return $chain !== array() ? $chain : array($categoryId);
    }
}
