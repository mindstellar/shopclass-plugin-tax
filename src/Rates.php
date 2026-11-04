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
 * The rate table: a default rate, a rate per buyer country and a rate per category, in
 * basis points. A category rate can hold for one buyer country only. Stored as the `rates`
 * preference: `{"default_bp":1800,"by_country":{"DE":1900},"by_category":{"12":{"bp":500,"code":"6109"}},
 * "by_category_country":{"IN":{"7":{"bp":1200}}}}`; the last key is left out when empty.
 */
final class Rates
{
    public const MAX_COUNTRIES = 250;

    public const MAX_CATEGORIES = 500;

    /** A category's tax code, such as a commodity or HSN code. */
    private const CODE = '/^[A-Za-z0-9][A-Za-z0-9 .\/-]{0,19}$/';

    public int $defaultBp;

    /** @var array<string,int> country code => bp */
    public array $byCountry;

    /** @var array<int,array{bp:int,code:?string}> category id => rate, for every buyer country */
    public array $byCategory;

    /** @var array<string,array<int,array{bp:int,code:?string}>> country code => category id => rate */
    public array $byCategoryCountry;

    /**
     * @param array<string,int>                                     $byCountry
     * @param array<int,array{bp:int,code:?string}>                 $byCategory
     * @param array<string,array<int,array{bp:int,code:?string}>>   $byCategoryCountry
     */
    public function __construct(int $defaultBp, array $byCountry = array(), array $byCategory = array(), array $byCategoryCountry = array())
    {
        $this->defaultBp         = $defaultBp;
        $this->byCountry         = $byCountry;
        $this->byCategory        = $byCategory;
        $this->byCategoryCountry = $byCategoryCountry;
    }

    /**
     * Read the stored table. Anything malformed, anywhere, reads as no table at all, so a
     * broken entry never charges a rate the admin did not set.
     *
     * @param mixed $json
     */
    public static function fromJson($json): ?self
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($data) || !self::bp($data['default_bp'] ?? null)) {
            return null;
        }
        $countries  = $data['by_country'] ?? array();
        $categories = $data['by_category'] ?? array();
        if (!is_array($countries) || !is_array($categories) || count($countries) > self::MAX_COUNTRIES || count($categories) > self::MAX_CATEGORIES) {
            return null;
        }
        $byCountry = array();
        foreach ($countries as $code => $bp) {
            if (!is_string($code) || preg_match('/^[A-Z]{2}$/', $code) !== 1 || !self::bp($bp)) {
                return null;
            }
            $byCountry[$code] = $bp;
        }
        $byCategory = self::categoryRates($categories);
        $perCountry = $data['by_category_country'] ?? array();
        if ($byCategory === null || !is_array($perCountry)) {
            return null;
        }
        $byCategoryCountry = array();
        $count             = count($byCategory);
        foreach ($perCountry as $code => $rates) {
            $rates = is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 && is_array($rates) ? self::categoryRates($rates) : null;
            if ($rates === null || $rates === array()) {
                return null;
            }
            $count += count($rates);
            $byCategoryCountry[$code] = $rates;
        }
        if ($count > self::MAX_CATEGORIES) {
            return null;
        }

        return new self($data['default_bp'], $byCountry, $byCategory, $byCategoryCountry);
    }

    /**
     * Stored category rates, or null when any is malformed.
     *
     * @param array<mixed,mixed> $rows category id => {bp, code}
     *
     * @return ?array<int,array{bp:int,code:?string}>
     */
    private static function categoryRates(array $rows): ?array
    {
        $out = array();
        foreach ($rows as $id => $rate) {
            $code = is_array($rate) ? ($rate['code'] ?? null) : false;
            if (!is_int($id) || $id <= 0 || !is_array($rate) || !self::bp($rate['bp'] ?? null)
                || ($code !== null && (!is_string($code) || preg_match(self::CODE, $code) !== 1))) {
                return null;
            }
            $out[$id] = array('bp' => $rate['bp'], 'code' => $code);
        }

        return $out;
    }

    /**
     * The table as stored.
     */
    public function toJson(): string
    {
        $categories = static function (array $rates): object {
            $out = array();
            foreach ($rates as $id => $rate) {
                $out[(string) $id] = $rate['code'] === null ? array('bp' => $rate['bp']) : $rate;
            }

            return (object) $out;
        };
        $table = array(
            'default_bp'  => $this->defaultBp,
            'by_country'  => (object) $this->byCountry,
            'by_category' => $categories($this->byCategory),
        );
        if ($this->byCategoryCountry !== array()) {
            $table['by_category_country'] = (object) array_map($categories, $this->byCategoryCountry);
        }

        return (string) json_encode($table, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The rate for one line: the nearest category in $chain with a rate (the line's own
     * category first, then its parents; at each, the buyer country's rate before the one for
     * every country), else the buyer country's, else the default.
     *
     * @param int[]  $chain   category ids from the line's own up to the root; empty for shipping
     * @param string $country two-letter code, or ''
     *
     * @return array{bp:int,code:?string,source:string}
     */
    public function forLine(array $chain, string $country): array
    {
        foreach ($chain as $id) {
            if ($country !== '' && isset($this->byCategoryCountry[$country][(int) $id])) {
                return $this->byCategoryCountry[$country][(int) $id] + array('source' => 'category');
            }
            if (isset($this->byCategory[(int) $id])) {
                return $this->byCategory[(int) $id] + array('source' => 'category');
            }
        }
        if ($country !== '' && isset($this->byCountry[$country])) {
            return array('bp' => $this->byCountry[$country], 'code' => null, 'source' => 'country');
        }

        return array('bp' => $this->defaultBp, 'code' => null, 'source' => 'default');
    }

    public function hasCategoryRates(): bool
    {
        return $this->byCategory !== array() || $this->byCategoryCountry !== array();
    }

    /**
     * The table from the admin form: a default percent, country rows and category rows. A
     * row left wholly blank is skipped.
     *
     * @param array<int,mixed> $countries  {code, rate} rows
     * @param array<int,mixed> $categories {id, country, rate, code} rows; a blank country is every country
     *
     * @return array{0:?self,1:?string,2:?array{table:string,row:int,column:string}} the table, or null
     *         and the first error with the refused cell (null when no single cell is at fault)
     */
    public static function fromForm(string $default, array $countries, array $categories): array
    {
        $defaultBp = Percent::parse($default === '' ? '0' : $default);
        if ($defaultBp === null) {
            return array(null, __('The default rate must be a percent from 0 to 100, such as 18 or 12.5.', 'tax'), null);
        }
        $byCountry = array();
        foreach ($countries as $i => $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $rate = trim((string) ($row['rate'] ?? ''));
            if ($code === '' && $rate === '') {
                continue;
            }
            $bp = Percent::parse($rate);
            if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                return array(null, sprintf(__('"%s" is not a two-letter country code.', 'tax'), $code), self::cell('country', $i, 'code'));
            }
            if ($bp === null) {
                return array(null, sprintf(__('The rate for %s must be a percent from 0 to 100.', 'tax'), $code), self::cell('country', $i, 'rate'));
            }
            if (isset($byCountry[$code])) {
                return array(null, sprintf(__('%s is listed twice.', 'tax'), $code), self::cell('country', $i, 'code'));
            }
            $byCountry[$code] = $bp;
        }
        $byCategory        = array();
        $byCategoryCountry = array();
        foreach ($categories as $i => $row) {
            $id      = (int) ($row['id'] ?? 0);
            $country = strtoupper(trim((string) ($row['country'] ?? '')));
            $rate    = trim((string) ($row['rate'] ?? ''));
            $code    = trim((string) ($row['code'] ?? ''));
            if ($id <= 0 && $country === '' && $rate === '' && $code === '') {
                continue;
            }
            $bp = Percent::parse($rate);
            if ($id <= 0) {
                return array(null, __('Choose a category for each category rate.', 'tax'), self::cell('category', $i, 'id'));
            }
            if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                return array(null, sprintf(__('"%s" is not a two-letter country code.', 'tax'), $country), self::cell('category', $i, 'country'));
            }
            if ($bp === null) {
                return array(null, __('Each category rate must be a percent from 0 to 100.', 'tax'), self::cell('category', $i, 'rate'));
            }
            if ($code !== '' && preg_match(self::CODE, $code) !== 1) {
                return array(null, sprintf(__('"%s" is not a tax code: up to 20 letters, digits, spaces, dots, dashes or slashes.', 'tax'), $code), self::cell('category', $i, 'code'));
            }
            if ($country === '' ? isset($byCategory[$id]) : isset($byCategoryCountry[$country][$id])) {
                return array(null, __('A category is listed twice.', 'tax'), self::cell('category', $i, 'id'));
            }
            $rateRow = array('bp' => $bp, 'code' => $code === '' ? null : $code);
            if ($country === '') {
                $byCategory[$id] = $rateRow;
            } else {
                $byCategoryCountry[$country][$id] = $rateRow;
            }
        }
        $categoryCount = count($byCategory) + array_sum(array_map('count', $byCategoryCountry));
        if (count($byCountry) > self::MAX_COUNTRIES || $categoryCount > self::MAX_CATEGORIES) {
            return array(null, __('That is more rates than the table holds.', 'tax'), null);
        }

        return array(new self($defaultBp, $byCountry, $byCategory, $byCategoryCountry), null, null);
    }

    /**
     * @return array{table:string,row:int,column:string}
     */
    private static function cell(string $table, int $row, string $column): array
    {
        return array('table' => $table, 'row' => $row, 'column' => $column);
    }

    /**
     * @param mixed $bp
     */
    private static function bp($bp): bool
    {
        return is_int($bp) && $bp >= 0 && $bp <= Calculator::MAX_BP;
    }
}
