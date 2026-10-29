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
use mindstellar\billing\event\Line;
use mindstellar\billing\event\ReceiptComposing;

/**
 * The typed listeners: tax lines on the cart, and the tax invoice on the receipt. The maths stays in Plugin.
 */
final class Listener
{
    public function onTaxLines(CartTaxLines $event): void
    {
        $cart = $event->cartArray();
        foreach (Plugin::lines(array(), $cart, Config::fromSettings(), array(Plugin::class, 'categoryChains')) as $line) {
            $event->addLine(new Line(
                (string) $line['id'],
                (string) $line['label'],
                (int) $line['amount'],
                (string) $line['currency'],
                is_array($line['meta'] ?? null) ? $line['meta'] : array()
            ));
        }
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
}
