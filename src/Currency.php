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
 * The smallest unit of a currency, which tax is rounded to so every total can be charged.
 */
final class Currency
{
    private const ZERO_DECIMAL = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    );

    private const THREE_DECIMAL = array('BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND');

    /**
     * The smallest unit in micros, from core's currency decimals when it has them: 10000 for two decimals, 1000000 for none, 1000 for three.
     */
    public static function step(string $currency): int
    {
        $currency = strtoupper($currency);
        if (method_exists('mindstellar\\currency\\CurrencyService', 'decimals')) {
            try {
                $decimals = \mindstellar\currency\CurrencyService::make()->decimals($currency);

                return 10 ** (6 - max(0, min(6, $decimals)));
            } catch (\Throwable) {
                // No database yet: use the built-in lists below.
            }
        }
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 1000000;
        }

        return in_array($currency, self::THREE_DECIMAL, true) ? 1000 : 10000;
    }
}
