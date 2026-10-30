# Changelog

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
