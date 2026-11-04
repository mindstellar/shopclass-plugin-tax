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

use Params;

/**
 * The rate table on the settings page: the default rate, a row per country and a row per
 * category. Core's settings form posts it with its CSRF and capability checks; collect()
 * turns the rows into the `rates` preference.
 */
final class RatesField
{
    /** Blank rows offered under the saved ones. */
    private const BLANK_ROWS = 3;

    private static ?string $error = null;

    /** @var ?array{default:string,country:array<int,array<string,string>>,category:array<int,array<string,string>>} the rows posted with a refused save (category rows carry id, country, rate, code) */
    private static ?array $posted = null;

    /** @var ?array{table:string,row:int,column:string} */
    private static ?array $cell = null;

    /**
     * The posted rows as the `rates` JSON. A refused table keeps what is stored and reports
     * why through validate().
     *
     * @param array<string,mixed> $field
     */
    public static function collect(array $field = array()): string
    {
        $country  = self::rows(array('code', 'rate'), 'tax_country_');
        $category = self::rows(array('id', 'country', 'rate', 'code'), 'tax_category_');
        $default  = Params::getParamString('tax_default');
        [$rates, self::$error, self::$cell] = Rates::fromForm($default, $country, $category);

        if ($rates !== null) {
            self::$posted = null;

            return $rates->toJson();
        }
        self::$posted = array('default' => $default, 'country' => $country, 'category' => $category);
        // Never empty, so validate() runs and the save is refused.
        $stored = (string) osc_get_preference('rates', Plugin::PAGE);

        return $stored !== '' ? $stored : '{}';
    }

    /**
     * @param mixed $value
     */
    public static function validate($value = null): ?string
    {
        return self::$error;
    }

    /**
     * Draw the table.
     *
     * @param array<string,mixed> $field core's custom field, with `values` of the whole form
     */
    public static function render(array $field = array()): void
    {
        $rates  = Rates::fromJson($field['values']['rates'] ?? '') ?? new Rates(0);
        $posted = self::$posted;
        $cell   = self::$cell;
        $error  = self::$error;
        // Core's own error contract marks the refused cell and prints its message under it.
        $errorAt = static function (string $table, int $row, string $column) use ($posted, $cell, $error): string {
            return $posted !== null && $cell === array('table' => $table, 'row' => $row, 'column' => $column) ? (string) $error : '';
        };

        osc_admin_field(array(
            'type'   => 'text',
            'name'   => 'tax_default',
            'id'     => 'tax-default',
            'value'  => $posted !== null ? $posted['default'] : Percent::fromBp($rates->defaultBp),
            'suffix' => '%',
            'width'  => 'num',
            'row'    => false,
            'attrs'  => array('aria-label' => __('Default rate', 'tax')),
        ));
        echo '<p class="help-box">' . osc_esc_html(__('Used when no country or category rate below applies. 0 charges no tax.', 'tax')) . '</p>';

        $input = static function (string $name, string $id, string $value, string $label, string $error, string $width = 'num'): void {
            osc_admin_field(array('type' => 'text', 'name' => $name, 'id' => $id, 'value' => $value, 'width' => $width, 'row' => false, 'error' => $error, 'attrs' => array('aria-label' => $label)));
        };

        osc_admin_section_heading(__('Rates by buyer country', 'tax'), 'subsection');
        echo '<p class="help-box">' . osc_esc_html(__('Two-letter codes, such as DE or GB. A buyer with no address yet counts as in the seller country. Clear a row to remove it.', 'tax')) . '</p>';
        echo '<div class="osc-prose table-contains-actions osc-table-stack"><table class="table"><thead><tr><th>' . osc_esc_html(__('Country code', 'tax')) . '</th><th>' . osc_esc_html(__('Rate %', 'tax')) . '</th></tr></thead><tbody>';
        $n = 0;
        foreach (self::countryRows($rates, $posted) as $row) {
            echo '<tr><td data-col-name="' . osc_esc_html(__('Country code', 'tax')) . '">';
            $input('tax_country_code[]', 'tax-country-code-' . $n, $row['code'], __('Country code', 'tax'), $errorAt('country', $n, 'code'));
            echo '</td><td data-col-name="' . osc_esc_html(__('Rate %', 'tax')) . '">';
            $input('tax_country_rate[]', 'tax-country-rate-' . $n, $row['rate'], __('Rate %', 'tax'), $errorAt('country', $n, 'rate'));
            echo '</td></tr>';
            $n++;
        }
        echo '</tbody></table></div>';

        $options = array('' => __('Choose a category', 'tax')) + self::categoryOptions();
        osc_admin_section_heading(__('Rates by category', 'tax'), 'subsection');
        echo '<p class="help-box">' . osc_esc_html(__('A category rate wins over the country and default rates, and covers its subcategories unless one has its own. The tax code, if any, is listed on the invoice.', 'tax')) . '</p>';
        echo '<p class="help-box">' . osc_esc_html(__('Leave Country blank for every buyer, or give a two-letter code to use the rate only for buyers there. A rate for the buyer\'s country wins over a blank one.', 'tax')) . '</p>';
        echo '<div class="osc-prose table-contains-actions osc-table-stack"><table class="table"><thead><tr><th>' . osc_esc_html(__('Category', 'tax')) . '</th><th>' . osc_esc_html(__('Country', 'tax')) . '</th><th>' . osc_esc_html(__('Rate %', 'tax')) . '</th><th>'
            . osc_esc_html(__('Tax code', 'tax')) . '</th></tr></thead><tbody>';
        $n = 0;
        foreach (self::categoryRows($rates, $posted) as $row) {
            echo '<tr><td data-col-name="' . osc_esc_html(__('Category', 'tax')) . '">';
            osc_admin_field(array(
                'type'    => 'select',
                'name'    => 'tax_category_id[]',
                'id'      => 'tax-category-id-' . $n,
                'value'   => $row['id'],
                'options' => $options,
                'row'     => false,
                'error'   => $errorAt('category', $n, 'id'),
                'attrs'   => array('aria-label' => __('Category', 'tax')),
            ));
            echo '</td><td data-col-name="' . osc_esc_html(__('Country', 'tax')) . '">';
            $input('tax_category_country[]', 'tax-category-country-' . $n, $row['country'], __('Country', 'tax'), $errorAt('category', $n, 'country'));
            echo '</td><td data-col-name="' . osc_esc_html(__('Rate %', 'tax')) . '">';
            $input('tax_category_rate[]', 'tax-category-rate-' . $n, $row['rate'], __('Rate %', 'tax'), $errorAt('category', $n, 'rate'));
            echo '</td><td data-col-name="' . osc_esc_html(__('Tax code', 'tax')) . '">';
            $input('tax_category_code[]', 'tax-category-code-' . $n, $row['code'], __('Tax code', 'tax'), $errorAt('category', $n, 'code'), 'key');
            echo '</td></tr>';
            $n++;
        }
        echo '</tbody></table></div>';
    }

    /**
     * Country rows to draw: the posted ones after a refused save, else the saved ones, then blanks.
     *
     * @param ?array<string,mixed> $posted
     *
     * @return array<int,array{code:string,rate:string}>
     */
    private static function countryRows(Rates $rates, ?array $posted): array
    {
        $out = array();
        if ($posted !== null) {
            foreach (self::withoutTrailingBlanks($posted['country']) as $row) {
                $out[] = array('code' => $row['code'], 'rate' => $row['rate']);
            }
        } else {
            foreach ($rates->byCountry as $code => $bp) {
                $out[] = array('code' => (string) $code, 'rate' => Percent::fromBp($bp));
            }
        }

        return array_merge($out, array_fill(0, self::BLANK_ROWS, array('code' => '', 'rate' => '')));
    }

    /**
     * @param ?array<string,mixed> $posted
     *
     * @return array<int,array{id:string,country:string,rate:string,code:string}>
     */
    private static function categoryRows(Rates $rates, ?array $posted): array
    {
        $out = array();
        if ($posted !== null) {
            foreach (self::withoutTrailingBlanks($posted['category']) as $row) {
                $out[] = array('id' => $row['id'], 'country' => $row['country'], 'rate' => $row['rate'], 'code' => $row['code']);
            }
        } else {
            $row = static fn (int $id, string $country, array $rate): array => array('id' => (string) $id, 'country' => $country, 'rate' => Percent::fromBp($rate['bp']), 'code' => (string) $rate['code']);
            foreach ($rates->byCategory as $id => $rate) {
                $out[] = $row($id, '', $rate);
            }
            foreach ($rates->byCategoryCountry as $country => $categoryRates) {
                foreach ($categoryRates as $id => $rate) {
                    $out[] = $row($id, (string) $country, $rate);
                }
            }
        }

        return array_merge($out, array_fill(0, self::BLANK_ROWS, array('id' => '', 'country' => '', 'rate' => '', 'code' => '')));
    }

    /**
     * @param array<int,array<string,string>> $rows
     *
     * @return array<int,array<string,string>>
     */
    private static function withoutTrailingBlanks(array $rows): array
    {
        while ($rows !== array() && trim(implode('', end($rows))) === '') {
            array_pop($rows);
        }

        return $rows;
    }

    /**
     * The posted columns zipped into rows: tax_country_code[] and tax_country_rate[] become
     * {code, rate} rows.
     *
     * @param string[] $columns
     *
     * @return array<int,array<string,string>>
     */
    private static function rows(array $columns, string $prefix): array
    {
        $posted = array();
        foreach ($columns as $column) {
            $posted[$column] = array_values(Params::getParamArray($prefix . $column));
        }
        $count = min(Rates::MAX_CATEGORIES + self::BLANK_ROWS, max(array_map('count', $posted)));
        $rows  = array();
        for ($i = 0; $i < $count; $i++) {
            $row = array();
            foreach ($columns as $column) {
                $value        = $posted[$column][$i] ?? '';
                $row[$column] = is_string($value) ? $value : '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Every enabled category by id, with its path ("Clothing › Shirts").
     *
     * @return array<string,string>
     */
    private static function categoryOptions(): array
    {
        $rows = \Category::getInstance()->listEnabled();
        if (method_exists('mindstellar\\admin\\ui\\Picker', 'categoryOptions')) {
            $out = array();
            foreach (\mindstellar\admin\ui\Picker::categoryOptions($rows) as $id => $option) {
                $out[(string) $id] = (string) ($option['path'] ?? $option['name'] ?? $id);
            }

            return $out;
        }
        $out = array();
        foreach ($rows as $row) {
            $out[(string) $row['pk_i_id']] = (string) ($row['s_name'] ?? $row['pk_i_id']);
        }

        return $out;
    }
}
