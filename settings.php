<?php

/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\tax\RatesField;

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's
 * capability, validates each field and stores it under the "tax" section.
 */

$text = static function (string $name, string $label, int $max, string $default = '', string $help = ''): array {
    return array(
        'type'      => 'text',
        'name'      => $name,
        'label'     => $label,
        'help'      => $help,
        'default'   => $default,
        'maxlength' => $max,
        'validate'  => static function ($value) use ($max) {
            return mb_strlen((string) $value, 'UTF-8') <= $max ? null : sprintf(__('Use %d characters or fewer.', 'tax'), $max);
        },
    );
};

return array(
    'title'  => __('Tax', 'tax'),
    'menu'   => 'billing',
    'intro'  => __('Adds tax to every cart and order from the rates below, and prints the receipt as a tax invoice. Tax is worked out on the server, per line, and rounded to the currency\'s smallest unit.', 'tax'),
    'groups' => array(
        array(
            'title'  => __('Prices', 'tax'),
            'fields' => array(
                array(
                    'type'    => 'checkbox',
                    'name'    => 'prices_include_tax',
                    'label'   => __('Listing prices already include tax', 'tax'),
                    'help'    => __('On: the tax is shown as included and the total does not change. Off: the tax is added on top.', 'tax'),
                    'default' => true,
                ),
                $text('tax_label', __('Tax name', 'tax'), 20, __('Tax', 'tax'), __('Shown on each tax line, such as VAT or GST.', 'tax')),
                array(
                    'type'    => 'checkbox',
                    'name'    => 'tax_shipping',
                    'label'   => __('Tax delivery charges too', 'tax'),
                    'help'    => __('At the buyer country\'s rate, else the default rate. Free delivery is never taxed.', 'tax'),
                    'default' => false,
                ),
            ),
        ),
        array(
            'title'  => __('Seller', 'tax'),
            'fields' => array(
                array(
                    'type'      => 'text',
                    'name'      => 'seller_country',
                    'label'     => __('Seller country code', 'tax'),
                    'help'      => __('Two letters, such as IN or DE. A buyer with no address yet is taken to be here.', 'tax'),
                    'maxlength' => 2,
                    'sanitize'  => static fn ($value) => strtoupper(trim((string) $value)),
                    'validate'  => static function ($value) {
                        return $value === '' || preg_match('/^[A-Z]{2}$/', (string) $value) === 1 ? null : __('Use a two-letter country code.', 'tax');
                    },
                ),
                $text('seller_state', __('Seller state or region', 'tax'), 100),
                $text('seller_tax_id', __('Seller tax number', 'tax'), 40, '', __('Printed on every invoice, such as a VAT number.', 'tax')),
                $text('tax_id_label', __('Tax number label', 'tax'), 40, __('Tax number', 'tax'), __('What the invoice calls it, such as VAT number.', 'tax')),
            ),
        ),
        array(
            'title'  => __('Buyer', 'tax'),
            'fields' => array(
                array(
                    'type'    => 'select',
                    'name'    => 'ask_buyer_tax_id',
                    'label'   => __('Ask for buyer tax number', 'tax'),
                    'help'    => __('Optional: checkout shows a field for it and the invoice prints it. Only its format is checked.', 'tax'),
                    'options' => array(
                        'off'      => __('Off', 'tax'),
                        'optional' => __('Optional', 'tax'),
                    ),
                    'default' => 'off',
                ),
                $text('buyer_tax_id_label', __('Buyer tax number label', 'tax'), 40, __('Tax number', 'tax'), __('What checkout calls it, such as VAT number.', 'tax')),
            ),
        ),
        array(
            'title'  => __('Rates', 'tax'),
            'fields' => array(
                array(
                    'type'     => 'hidden',
                    'name'     => 'rates',
                    'collect'  => array(RatesField::class, 'collect'),
                    'validate' => array(RatesField::class, 'validate'),
                    'default'  => '',
                ),
                array(
                    'type'   => 'custom',
                    'name'   => 'rates_table',
                    'label'  => __('Default rate', 'tax'),
                    'render' => array(RatesField::class, 'render'),
                ),
            ),
        ),
        array(
            'title'  => __('Invoice', 'tax'),
            'fields' => array(
                $text('invoice_title', __('Invoice title', 'tax'), 100, __('Tax invoice', 'tax')),
                array(
                    'type'      => 'textarea',
                    'name'      => 'invoice_footer',
                    'label'     => __('Invoice footer', 'tax'),
                    'help'      => __('Printed at the foot of every invoice. Plain text.', 'tax'),
                    'default'   => __('Thank you for your order.', 'tax'),
                    'maxlength' => 1000,
                    'validate'  => static function ($value) {
                        return mb_strlen((string) $value, 'UTF-8') <= 1000 ? null : __('Use 1000 characters or fewer.', 'tax');
                    },
                ),
            ),
        ),
    ),
);
