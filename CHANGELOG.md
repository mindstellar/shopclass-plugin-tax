# Changelog

## Unreleased

### New
- A category rate can name a buyer country, so it applies only to buyers there.

## 0.2.1

### Changed
- Uses `CurrencyService::money()` only; the fallback for older cores is gone.

## 0.2.0

### New
- Each item and delivery line carries its own tax, which invoices and credit notes show and refunds use.
- Optional buyer tax number at checkout, format-checked and printed on the invoice.
- Filter `tax_buyer_id` for plugins that check a country's tax number.
- Invoices use the `tax_invoice` code label, such as HSN.

### Fixed
- A cart with more than 20 tax lines no longer stops the quote.

## 0.1.4

### Changed
- Tax rounds to the currency decimals set in core, when core has them.

## 0.1.3

### Fixed
- An order discount lowers only the items' tax; a shipping discount lowers only the shipping tax.
- Invoice rows show amounts in the shop's price format.

## 0.1.2

### Fixed
- Tax is worked out on prices after discounts.

## 0.1.1

### Changed
- Tax lines and the tax invoice use core's typed cart and receipt events.

## 0.1.0

### New
- Tax lines on every cart quote and order, from a default rate, rates by buyer country and rates by category.
- Prices that include tax (the default) or tax added on top.
- Tax worked out once per line, rounded half up to the currency's smallest unit.
- Delivery charges can be taxed too.
- A paid order's receipt prints as a tax invoice with the seller's tax number, place of supply and a summary per rate.
- Filters `tax_line_rate`, `tax_parts` and `tax_invoice` for plugins that change a line's rate, split a tax or add to the invoice.
- Settings page with the rate table under Billing → Tax.
- Translation template in `languages/tax.pot`.
