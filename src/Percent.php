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
 * Rates as the admin types them ("18", "12.5", "0.25") and as basis points (1800, 1250, 25).
 */
final class Percent
{
    /**
     * A typed percent as basis points: 0 to 100 with up to two decimals, '.' or ','.
     *
     * @return int|null null when it is not one
     */
    public static function parse(string $text): ?int
    {
        $text = str_replace(array(',', '%', ' '), array('.', '', ''), trim($text));
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return $bp <= Calculator::MAX_BP ? $bp : null;
    }

    /**
     * $num / $den as a plain decimal with no trailing zeros: 1800 / 100 is "18", 1250 / 100
     * is "12.5". $den is a power of ten.
     */
    public static function format(int $num, int $den): string
    {
        $places = strlen((string) $den) - 1;
        $whole  = intdiv($num, $den);
        $frac   = rtrim(str_pad((string) ($num - $whole * $den), $places, '0', STR_PAD_LEFT), '0');

        return $frac === '' ? (string) $whole : $whole . '.' . $frac;
    }

    /**
     * Basis points as the percent the admin typed: 1800 is "18".
     */
    public static function fromBp(int $bp): string
    {
        return self::format($bp, 100);
    }
}
