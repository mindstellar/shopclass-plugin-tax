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
 * The buyer's tax number, asked at checkout. Only its format is checked; a country plugin may
 * relabel, normalise or refuse it through the `tax_buyer_id` filter.
 */
final class BuyerId
{
    /** The checkout field id. */
    public const FIELD = 'tax_id';

    /** Longest value the buyer may type, spaces and dashes included. */
    public const MAX_INPUT = 32;

    /**
     * Spaces, dots and dashes taken out, letters upper-cased: "de 123.456-789" is "DE123456789".
     */
    public static function normalise(string $raw): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]+/u', '', $raw));
    }

    /**
     * Whether a typed number may be kept, and as what. Empty is valid, since the field is optional.
     * `ask` false means the checkout should not ask for it at all.
     *
     * @param array<string,mixed> $context from Context::build()
     *
     * @return array{valid:bool,value:string,message:string,label:string,ask:bool}
     */
    public static function check(string $raw, array $context, string $label): array
    {
        $value   = self::normalise($raw);
        $refusal = __('Enter a tax number of 4 to 20 letters and digits.', 'tax');
        $valid   = $value === '' || preg_match('/^[A-Z0-9]{4,20}$/', $value) === 1;
        $default = array('valid' => $valid, 'value' => $value, 'message' => $valid ? '' : $refusal, 'label' => $label, 'ask' => true);
        $asked   = osc_apply_filter('tax_buyer_id', $default, $raw, $context);
        if (!is_array($asked)) {
            return $default;
        }
        $out = array(
            'valid'   => is_bool($asked['valid'] ?? null) ? $asked['valid'] : $default['valid'],
            'value'   => is_string($asked['value'] ?? null) ? mb_substr(trim($asked['value']), 0, 100, 'UTF-8') : $default['value'],
            'message' => is_string($asked['message'] ?? null) ? trim($asked['message']) : '',
            'label'   => is_string($asked['label'] ?? null) && trim($asked['label']) !== '' ? mb_substr(trim($asked['label']), 0, 60, 'UTF-8') : $label,
            'ask'     => is_bool($asked['ask'] ?? null) ? $asked['ask'] : true,
        );
        if ($out['valid']) {
            $out['message'] = '';
        } elseif ($out['message'] === '') {
            $out['message'] = $refusal;
        }

        return $out;
    }
}
