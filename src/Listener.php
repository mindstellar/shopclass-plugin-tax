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

use mindstellar\billing\event\CartTaxLines;
use mindstellar\billing\event\CheckoutFields;
use mindstellar\billing\event\CheckoutStarting;
use mindstellar\billing\event\DocumentComposing;
use mindstellar\billing\event\Line;
use mindstellar\billing\event\ReceiptComposing;
use mindstellar\billing\LineTax;

/**
 * The typed listeners: tax lines and each line's own tax on the cart, the buyer's tax number at
 * checkout, and the tax invoice on the receipt and the frozen invoice. The maths stays in Plugin.
 */
final class Listener
{
    public function onTaxLines(CartTaxLines $event): void
    {
        $cart  = $event->cartArray();
        $lines = Plugin::lines(array(), $cart, Config::fromSettings(), array(Plugin::class, 'categoryChains'));
        $room  = defined(Line::class . '::MAX_PER_EVENT') ? Line::MAX_PER_EVENT : 20;
        $added = 0;
        foreach ($lines as $line) {
            if (count((array) $event->rawLines()) >= $room) {
                break;
            }
            $event->addLine(new Line(
                (string) $line['id'],
                (string) $line['label'],
                (int) $line['amount'],
                (string) $line['currency'],
                is_array($line['meta'] ?? null) ? $line['meta'] : array()
            ));
            $added++;
        }
        // Line tax needs every tax line it names; older cores take none.
        if ($added === count($lines) && method_exists($event, 'setLineTax') && class_exists(LineTax::class)) {
            self::setLineTax($event, $lines);
        }
    }

    public function onFields(CheckoutFields $event): void
    {
        $config = Config::fromSettings();
        if (!$config->askBuyerId) {
            return;
        }
        $check = BuyerId::check('', self::context($config, $event->cartArray()), self::label($config));
        if ($check['ask']) {
            $event->addField(BuyerId::FIELD, $check['label'], false, BuyerId::MAX_INPUT, __('For a business purchase. Printed on the invoice.', 'tax'));
        }
    }

    public function onStarting(CheckoutStarting $event): void
    {
        $config = Config::fromSettings();
        if (!$config->askBuyerId || !method_exists($event, 'field')) {
            return;
        }
        $raw = $event->field(BuyerId::FIELD);
        if ($raw === '') {
            return;
        }
        $check = BuyerId::check($raw, self::context($config, $event->cartArray()), self::label($config));
        if (!$check['valid']) {
            $event->refuse('/fields/' . BuyerId::FIELD, 'invalid', $check['message']);

            return;
        }
        if ($check['value'] !== $raw) {
            try {
                $event->setField(BuyerId::FIELD, $check['value']);
            } catch (\InvalidArgumentException) {
                // The checkout no longer asks for it.
            }
        }
    }

    public function onDocument(DocumentComposing $event): void
    {
        // A credit note copies its invoice's label.
        if ($event->type() !== 'invoice') {
            return;
        }
        $label = Plugin::codeLabel($event->order());
        if ($label === null) {
            return;
        }
        $data            = $event->data();
        $data['wording'] = (is_array($data['wording'] ?? null) ? $data['wording'] : array());
        $data['wording']['code_label'] = $label;
        $event->setData($data);
    }

    public function onReceipt(ReceiptComposing $event): void
    {
        $before = $event->details();
        $after  = Plugin::receiptDetails($before, $event->order());
        if (!is_array($after) || $after === $before) {
            return;
        }
        $event->setTitle((string) ($after['title'] ?? ''));
        $event->setFooter((string) ($after['footer'] ?? ''));
        foreach (array_slice((array) ($after['extra'] ?? array()), count((array) $before['extra'])) as $row) {
            $event->addExtraRow((string) $row['label'], (string) $row['value']);
        }
        foreach (array_slice((array) ($after['summary'] ?? array()), count((array) $before['summary'])) as $row) {
            $event->addSummaryRow((string) $row['label'], (string) $row['value']);
        }
    }

    /**
     * Each line's share of the tax lines just added. One bad line sets none, since core drops a
     * breakdown that does not add up anyway.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private static function setLineTax(CartTaxLines $event, array $lines): void
    {
        $taxes = array();
        try {
            foreach (Calculator::perLine($lines) as $kind => $byRef) {
                foreach ($byRef as $ref => $tax) {
                    $taxes[] = array($kind, $kind === 'item' ? (int) $ref : (string) $ref, new LineTax($tax['included'], $tax['base'], $tax['parts'], $tax['code']));
                }
            }
            foreach ($taxes as [$kind, $ref, $tax]) {
                $event->setLineTax($kind, $ref, $tax);
            }
        } catch (\InvalidArgumentException $e) {
            trigger_error('Tax: no line tax for this cart: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /**
     * @param array<string,mixed> $cart
     *
     * @return array<string,mixed>
     */
    private static function context(Config $config, array $cart): array
    {
        return Context::build($config, is_array($cart['address'] ?? null) ? $cart['address'] : array(), (string) ($cart['currency'] ?? ''), (int) ($cart['user_id'] ?? 0));
    }

    private static function label(Config $config): string
    {
        return $config->buyerIdLabel !== '' ? $config->buyerIdLabel : __('Tax number', 'tax');
    }
}
