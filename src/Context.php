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
 * Who sells and where the goods go, as every tax filter receives it. With no address yet the
 * buyer is taken to be in the seller's country and state.
 */
final class Context
{
    /**
     * @param array<string,mixed> $address the cart's or order's address; only country, state and postcode are read
     *
     * @return array{seller_country:string,seller_state:string,country:string,state:string,postcode:string,same_country:bool,same_state:bool,inclusive:bool,currency:string,user_id:int}
     */
    public static function build(Config $config, array $address, string $currency, int $userId): array
    {
        $country = strtoupper(trim(is_string($address['country'] ?? null) ? $address['country'] : ''));
        $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : $config->sellerCountry;
        $state   = trim(is_string($address['state'] ?? null) ? $address['state'] : '');
        $state   = $state !== '' ? mb_substr($state, 0, 100, 'UTF-8') : $config->sellerState;

        return array(
            'seller_country' => $config->sellerCountry,
            'seller_state'   => $config->sellerState,
            'country'        => $country,
            'state'          => $state,
            'postcode'       => mb_substr(trim(is_string($address['postcode'] ?? null) ? $address['postcode'] : ''), 0, 20, 'UTF-8'),
            'same_country'   => $country !== '' && $country === $config->sellerCountry,
            'same_state'     => $state !== '' && self::fold($state) === self::fold($config->sellerState),
            'inclusive'      => $config->inclusive,
            'currency'       => strtoupper($currency),
            'user_id'        => $userId,
        );
    }

    /**
     * A place name compared without case or extra spaces: "Tamil  nadu" is "Tamil Nadu".
     */
    public static function fold(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    }
}
