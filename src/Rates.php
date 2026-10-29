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
 * basis points. Stored as the `rates` preference:
 * `{"default_bp":1800,"by_country":{"DE":1900},"by_category":{"12":{"bp":500,"code":"6109"}}}`.
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

    /** @var array<int,array{bp:int,code:?string}> category id => rate */
    public array $byCategory;

    /**
     * @param array<string,int>                     $byCountry
     * @param array<int,array{bp:int,code:?string}> $byCategory
     */
    public function __construct(int $defaultBp, array $byCountry = array(), array $byCategory = array())
    {
        $this->defaultBp  = $defaultBp;
        $this->byCountry  = $byCountry;
        $this->byCategory = $byCategory;
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
        $byCategory = array();
        foreach ($categories as $id => $rate) {
            $code = is_array($rate) ? ($rate['code'] ?? null) : false;
            if (!is_int($id) || $id <= 0 || !is_array($rate) || !self::bp($rate['bp'] ?? null)
                || ($code !== null && (!is_string($code) || preg_match(self::CODE, $code) !== 1))) {
                return null;
            }
            $byCategory[$id] = array('bp' => $rate['bp'], 'code' => $code);
        }

        return new self($data['default_bp'], $byCountry, $byCategory);
    }

    /**
     * The table as stored.
     */
    public function toJson(): string
    {
        $categories = array();
        foreach ($this->byCategory as $id => $rate) {
            $categories[(string) $id] = $rate['code'] === null ? array('bp' => $rate['bp']) : $rate;
        }

        return (string) json_encode(array(
            'default_bp'  => $this->defaultBp,
            'by_country'  => (object) $this->byCountry,
            'by_category' => (object) $categories,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The rate for one line: the nearest category in $chain with a rate (the line's own
     * category first, then its parents), else the buyer country's, else the default.
     *
     * @param int[]  $chain   category ids from the line's own up to the root; empty for shipping
     * @param string $country two-letter code, or ''
     *
     * @return array{bp:int,code:?string,source:string}
     */
    public function forLine(array $chain, string $country): array
    {
        foreach ($chain as $id) {
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
        return $this->byCategory !== array();
    }

    /**
     * The table from the admin form: a default percent, country rows and category rows. A
     * row left wholly blank is skipped.
     *
     * @param array<int,mixed> $countries  {code, rate} rows
     * @param array<int,mixed> $categories {id, rate, code} rows
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
        $byCategory = array();
        foreach ($categories as $i => $row) {
            $id   = (int) ($row['id'] ?? 0);
            $rate = trim((string) ($row['rate'] ?? ''));
            $code = trim((string) ($row['code'] ?? ''));
            if ($id <= 0 && $rate === '' && $code === '') {
                continue;
            }
            $bp = Percent::parse($rate);
            if ($id <= 0) {
                return array(null, __('Choose a category for each category rate.', 'tax'), self::cell('category', $i, 'id'));
            }
            if ($bp === null) {
                return array(null, __('Each category rate must be a percent from 0 to 100.', 'tax'), self::cell('category', $i, 'rate'));
            }
            if ($code !== '' && preg_match(self::CODE, $code) !== 1) {
                return array(null, sprintf(__('"%s" is not a tax code: up to 20 letters, digits, spaces, dots, dashes or slashes.', 'tax'), $code), self::cell('category', $i, 'code'));
            }
            if (isset($byCategory[$id])) {
                return array(null, __('A category is listed twice.', 'tax'), self::cell('category', $i, 'id'));
            }
            $byCategory[$id] = array('bp' => $bp, 'code' => $code === '' ? null : $code);
        }
        if (count($byCountry) > self::MAX_COUNTRIES || count($byCategory) > self::MAX_CATEGORIES) {
            return array(null, __('That is more rates than the table holds.', 'tax'), null);
        }

        return array(new self($defaultBp, $byCountry, $byCategory), null, null);
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
