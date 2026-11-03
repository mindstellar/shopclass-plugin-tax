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
 * The tax maths, in integer micros. Tax is worked out once per line, never per unit, and
 * rounded half up to the currency's smallest unit. Each line's tax is split into its parts
 * there too, so the parts and the lines always add up to the same whole.
 */
final class Calculator
{
    /** Highest rate, in basis points (100%). */
    public const MAX_BP = 10000;

    private bool $inclusive;

    private int $step;

    /**
     * @param bool $inclusive true when prices already hold the tax
     * @param int  $step      the currency's smallest unit in micros (10000 for two decimals)
     */
    public function __construct(bool $inclusive, int $step)
    {
        $this->inclusive = $inclusive;
        $this->step      = max(1, $step);
    }

    /**
     * The taxable value and the tax of one line.
     *
     * Exclusive: tax = amount x bp / 10000. Inclusive: base = amount x 10000 / (10000 + bp)
     * and tax = amount - base. Both rounded half up to the step.
     *
     * @return array{base:int,tax:int}
     */
    public function line(int $amount, int $bp): array
    {
        if ($amount <= 0 || $bp <= 0) {
            return array('base' => max(0, $amount), 'tax' => 0);
        }
        $bp = min($bp, self::MAX_BP);
        if ($this->inclusive) {
            $base = self::mulDivRound($amount, 10000, (10000 + $bp) * $this->step) * $this->step;
            // An amount off the step (after a discount) still gives tax in whole steps.
            $tax = self::mulDivRound(max(0, $amount - $base), 1, $this->step) * $this->step;

            return array('base' => $amount - $tax, 'tax' => $tax);
        }

        return array('base' => $amount, 'tax' => self::mulDivRound($amount, $bp, 10000 * $this->step) * $this->step);
    }

    /**
     * Split a tax into parts by share (basis points of the tax, summing to 10000), in whole
     * steps by largest remainder, so the parts always sum to the tax. A remainder below one
     * step goes to the first part.
     *
     * @param int   $tax
     * @param int[] $shares
     *
     * @return int[]
     */
    public function split(int $tax, array $shares): array
    {
        $steps = intdiv($tax, $this->step);
        $odd   = $tax - $steps * $this->step;
        $out   = array();
        $rest  = array();
        $given = 0;
        foreach (array_values($shares) as $i => $share) {
            $exact    = $steps * $share;
            $out[$i]  = intdiv($exact, 10000);
            $rest[$i] = $exact % 10000;
            $given   += $out[$i];
        }
        // Largest remainder first; ties go to the earlier part.
        $order = array_keys($rest);
        usort($order, static fn (int $a, int $b): int => $rest[$b] <=> $rest[$a] ?: $a <=> $b);
        for ($k = 0, $left = $steps - $given; $left > 0 && $order !== array(); $k++, $left--) {
            $out[$order[$k % count($order)]]++;
        }
        foreach ($out as $i => $n) {
            $out[$i] = $n * $this->step + ($i === 0 ? $odd : 0);
        }

        return $out;
    }

    /**
     * The tax lines for a cart: one per rate and part, in rate order.
     *
     * @param array<int,array<string,mixed>> $taxable {kind, line, item_id, code, amount, bp} each
     * @param array<int,array{id:string,label:string,share:int}> $parts from Parts::normalise()
     *
     * @return array<int,array{id:string,label:string,amount:int,meta:array<string,mixed>}>
     */
    public function lines(array $taxable, array $parts): array
    {
        $shares = array_column($parts, 'share');
        $groups = array();
        foreach ($taxable as $entry) {
            $bp = min(self::MAX_BP, max(0, (int) ($entry['bp'] ?? 0)));
            if ($bp === 0 || (int) ($entry['amount'] ?? 0) <= 0) {
                continue;
            }
            $one   = $this->line((int) ($entry['amount'] ?? 0), $bp);
            $split = $this->split($one['tax'], $shares);
            foreach ($parts as $i => $part) {
                $key = $bp . '|' . $i;
                if (!isset($groups[$key])) {
                    $groups[$key] = array('bp' => $bp, 'part' => $i, 'base' => 0, 'tax' => 0, 'lines' => array());
                }
                $groups[$key]['base']   += $one['base'];
                $groups[$key]['tax']    += $split[$i];
                $groups[$key]['lines'][] = array(
                    'kind'    => (string) ($entry['kind'] ?? 'item'),
                    'line'    => isset($entry['line']) ? (int) $entry['line'] : null,
                    'item_id' => isset($entry['item_id']) ? (int) $entry['item_id'] : null,
                    'code'    => isset($entry['code']) && $entry['code'] !== '' ? (string) $entry['code'] : null,
                    'base'    => $one['base'],
                    'tax'     => $split[$i],
                ) + (isset($entry['ref']) && $entry['ref'] !== '' ? array('ref' => (string) $entry['ref']) : array());
            }
        }
        uksort($groups, static function (string $a, string $b): int {
            [$bpA, $iA] = array_map('intval', explode('|', $a));
            [$bpB, $iB] = array_map('intval', explode('|', $b));

            return $bpA <=> $bpB ?: $iA <=> $iB;
        });

        $out = array();
        foreach ($groups as $group) {
            $part  = $parts[$group['part']];
            $pct   = Percent::format($group['bp'] * $part['share'], 1000000);
            $out[] = array(
                'id'     => 'tax.' . $part['id'] . '.' . $pct,
                'label'  => $part['label'] . ' ' . $pct . '%',
                'amount' => $group['tax'],
                'meta'   => array(
                    'bp'       => $group['bp'],
                    'part'     => $part['id'],
                    'share'    => $part['share'],
                    'base'     => $group['base'],
                    'included' => $this->inclusive,
                    'lines'    => $group['lines'],
                ),
            );
        }

        return $out;
    }

    /**
     * The tax of each item line (by cart position) and shipping line (by id), read back from the
     * tax lines lines() made. Each part names its tax line, so the parts naming a tax line sum to it.
     *
     * @param array<int,array<string,mixed>> $taxLines from lines()
     *
     * @return array{item:array<int,array{included:bool,base:int,code:?string,parts:list<array{tax:string,label:string,bp:int,amount:int}>}>,shipping:array<string,array{included:bool,base:int,code:?string,parts:list<array{tax:string,label:string,bp:int,amount:int}>}>}
     */
    public static function perLine(array $taxLines): array
    {
        $out = array('item' => array(), 'shipping' => array());
        foreach ($taxLines as $line) {
            $meta = is_array($line['meta'] ?? null) ? $line['meta'] : array();
            if (!isset($meta['bp'], $meta['share']) || !is_array($meta['lines'] ?? null)) {
                continue;
            }
            $part = array(
                'tax'   => (string) $line['id'],
                'label' => (string) $line['label'],
                'bp'    => intdiv((int) $meta['bp'] * (int) $meta['share'] + 5000, 10000),
            );
            foreach ($meta['lines'] as $entry) {
                $kind = $entry['kind'] ?? null;
                $ref  = $kind === 'shipping' ? ($entry['ref'] ?? null) : ($entry['line'] ?? null);
                if (($kind !== 'item' && $kind !== 'shipping') || $ref === null) {
                    continue;
                }
                $out[$kind][$ref] ??= array('included' => ($meta['included'] ?? false) === true, 'base' => (int) $entry['base'], 'code' => $entry['code'] ?? null, 'parts' => array());
                $out[$kind][$ref]['parts'][] = $part + array('amount' => (int) $entry['tax']);
            }
        }

        return $out;
    }

    /**
     * round_half_up($a x $mul / $div) for $a >= 0, without overflow: $a is split by $div
     * first so the product never exceeds $div x $mul.
     */
    public static function mulDivRound(int $a, int $mul, int $div): int
    {
        $q = intdiv($a, $div);
        $r = $a - $q * $div;

        return $q * $mul + intdiv(2 * $r * $mul + $div, 2 * $div);
    }
}
