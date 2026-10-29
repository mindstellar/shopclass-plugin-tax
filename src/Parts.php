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
 * The parts a tax splits into. By default one part named after the tax ("GST", "VAT"); the
 * `tax_parts` filter may return others, such as two halves of a rate.
 */
final class Parts
{
    public const MAX = 4;

    /**
     * The one part every tax has unless a filter says otherwise.
     *
     * @return array<int,array{id:string,label:string,share:int}>
     */
    public static function single(string $label): array
    {
        $label = self::label($label) ?? 'Tax';
        $id    = substr((string) preg_replace('/[^a-z0-9]+/', '', strtolower($label)), 0, 16);

        return array(array('id' => $id !== '' ? $id : 'tax', 'label' => $label, 'share' => 10000));
    }

    /**
     * What a filter returned, when it is 1 to 4 parts with distinct ids of a-z and 0-9, a
     * label and whole shares summing to 10000; otherwise $default.
     *
     * @param mixed                                              $parts
     * @param array<int,array{id:string,label:string,share:int}> $default
     *
     * @return array<int,array{id:string,label:string,share:int}>
     */
    public static function normalise($parts, array $default): array
    {
        if (!is_array($parts) || $parts === array() || count($parts) > self::MAX) {
            return $default;
        }
        $out = array();
        $sum = 0;
        foreach (array_values($parts) as $part) {
            $id    = is_array($part) ? ($part['id'] ?? null) : null;
            $label = is_array($part) ? self::label($part['label'] ?? null) : null;
            $share = is_array($part) ? ($part['share'] ?? null) : null;
            if (!is_string($id) || preg_match('/^[a-z0-9]{1,16}$/', $id) !== 1 || isset($out[$id]) || $label === null
                || !is_int($share) || $share < 1 || $share > 10000) {
                return $default;
            }
            $out[$id] = array('id' => $id, 'label' => $label, 'share' => $share);
            $sum     += $share;
        }

        return $sum === 10000 ? array_values($out) : $default;
    }

    /**
     * @param mixed $label
     */
    private static function label($label): ?string
    {
        if (!is_string($label)) {
            return null;
        }
        $label = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $label));

        return $label === '' ? null : mb_substr($label, 0, 40, 'UTF-8');
    }
}
