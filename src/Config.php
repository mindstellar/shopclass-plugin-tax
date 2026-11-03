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
 * The saved settings, read from the preference cache. Tests build one by hand.
 */
final class Config
{
    public bool $inclusive;

    public bool $taxShipping;

    public string $label;

    public string $sellerCountry;

    public string $sellerState;

    public string $sellerTaxId;

    public string $taxIdLabel;

    public string $invoiceTitle;

    public string $invoiceFooter;

    public ?Rates $rates;

    /** Ask the buyer for a tax number at checkout (optional), else not at all. */
    public bool $askBuyerId;

    public string $buyerIdLabel;

    public function __construct(
        bool $inclusive,
        ?Rates $rates,
        string $label = 'Tax',
        bool $taxShipping = false,
        string $sellerCountry = '',
        string $sellerState = '',
        string $sellerTaxId = '',
        string $taxIdLabel = 'Tax number',
        string $invoiceTitle = 'Tax invoice',
        string $invoiceFooter = '',
        bool $askBuyerId = false,
        string $buyerIdLabel = ''
    ) {
        $this->inclusive     = $inclusive;
        $this->rates         = $rates;
        $this->label         = $label;
        $this->taxShipping   = $taxShipping;
        $this->sellerCountry = $sellerCountry;
        $this->sellerState   = $sellerState;
        $this->sellerTaxId   = $sellerTaxId;
        $this->taxIdLabel    = $taxIdLabel;
        $this->invoiceTitle  = $invoiceTitle;
        $this->invoiceFooter = $invoiceFooter;
        $this->askBuyerId    = $askBuyerId;
        $this->buyerIdLabel  = $buyerIdLabel;
    }

    public static function fromSettings(): self
    {
        $text    = static fn (string $name): string => trim((string) osc_settings_value(Plugin::PAGE, $name));
        $country = strtoupper($text('seller_country'));

        return new self(
            (bool) osc_settings_value(Plugin::PAGE, 'prices_include_tax'),
            Rates::fromJson($text('rates')),
            $text('tax_label'),
            (bool) osc_settings_value(Plugin::PAGE, 'tax_shipping'),
            preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '',
            $text('seller_state'),
            $text('seller_tax_id'),
            $text('tax_id_label'),
            $text('invoice_title'),
            $text('invoice_footer'),
            $text('ask_buyer_tax_id') === 'optional',
            $text('buyer_tax_id_label')
        );
    }
}
