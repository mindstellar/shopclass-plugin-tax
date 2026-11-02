# Tax

Adds tax to every cart and order in a Shopclass shop, for any country. Set a default rate, rates by buyer country and rates by category. A paid order's receipt prints as a tax invoice.

![Tax settings](assets/screenshot-1.png)

## Requirements

- Shopclass 7.1.0 or later, with shop mode
- PHP 8.2 or later

Works on shared hosting. It needs no cron, worker, Redis or shell. On an older core it stays off and the admin sees a notice.

## Install

1. Open Plugins → Browse (or upload the zip from the GitHub release).
2. Install.
3. Activate.

## Set up

Open Billing → Tax.

1. Prices: turn on "Listing prices already include tax" (on by default), or leave it off to add tax on top. Set Tax name (such as VAT or sales tax). Turn on "Tax delivery charges too" to tax shipping.
2. Seller: set Seller country code, Seller state or region, Seller tax number and Tax number label.
3. Buyer: set "Ask for buyer tax number" to Optional to show a field for it at checkout, and name it with "Buyer tax number label" (such as VAT number). Off by default.
4. Rates: set the Default rate, then optional rates by buyer country (code and percent) and by category (category, percent, optional tax code). Clear a row to remove it.
5. Invoice: set Invoice title and Invoice footer.

With no rate table saved, the plugin adds nothing.

## How it works

- Rate order: the line's category rate (a subcategory uses its nearest parent's), then the buyer country's rate, then the default.
- A buyer with no address yet counts as in the seller's country and state.
- Delivery, when taxed, uses the buyer country's rate, else the default. Free delivery is never taxed.
- Prices include tax: tax = price − price × 100 / (100 + rate). The total does not change and checkout shows "Includes …".
- Tax on top: tax = price × rate / 100, added to the total.
- Tax is worked out once per cart line, never per unit. It is rounded half up to the currency's smallest unit.
- There is one tax line per rate, such as `tax.vat.19` "VAT 19%" (the id comes from the tax name). Its meta holds `bp` (basis points), `part`, `base`, `included` and the cart `lines` it covers.
- Tax is worked out on the prices after discounts: core runs `cart_discount_lines` first, an order discount is shared over the items by value, and a discount marked `applies_to: shipping` in its meta (free delivery) comes off the shipping only. On an older core that taxes first, a discounted order's invoice says so.
- All amounts are computed on the server from core's cart. Nothing in the request sets a rate or amount.
- Each item and taxed delivery line also gets its own tax: its taxable value, the part it pays to each tax line, and its tax code. Core keeps it only when the parts add up to the tax lines exactly. Invoices show it in a Tax column, and a credit note that returns units takes those lines' own tax. On an older 7.1 core the plugin works without it.
- Buyer tax number: only its format is checked (4 to 20 letters and digits once spaces, dots and dashes are taken out). It is stored on the order and printed on the invoice.
- Invoice: when an order carries this plugin's tax, its receipt becomes a tax invoice. It shows your title (default "Tax invoice"), tax number, place of supply (buyer's state and country), taxable value and tax codes per rate, and your footer.

## Notes / limits

- Up to 20 tax lines per cart (distinct rates × parts). Past that the rest are left out, and so is each line's own tax.
- Up to 250 country rates and 500 category rates.
- Rates run from 0% to 100%, with up to two decimals.
- No online check of a buyer tax number (no VIES or GST portal call) and no reverse charge. No invoice numbering of its own (the invoice shows the order number) and no PDF. The receipt page prints.
- Settings are preferences in the `tax` section. No table of its own. A placed order keeps its tax lines.
- Uninstalling removes the settings. Orders keep the tax lines they were charged.
- Queries: none per quote without category rates. With category rates, one primary-key query per request.

## For developers

Listens to `cart_tax_lines`, `billing_receipt_details`, and on cores with checkout fields `checkout_fields`, `cart_checkout_before` and `billing_document_compose`. Fires four filters. A return value of the wrong shape is ignored.

`$context`, passed to each:

```php
array(
    'seller_country' => 'DE',   'seller_state' => 'Berlin',
    'country'        => 'DE',   'state'        => 'Bavaria', 'postcode' => '80331',
    'same_country'   => true,   'same_state'   => false,  // compared without case or extra spaces
    'inclusive'      => true,   'currency'     => 'EUR',  'user_id' => 12,
)
```

`tax_line_rate` `($rate, $line, $context)` changes the rate of one line. `$rate` is `array('bp' => 1800, 'code' => '6109'|null, 'source' => 'category'|'country'|'default')`. `$line` is `array('kind' => 'item'|'shipping', 'line', 'ref' (shipping line id, shipping only), 'item_id', 'category_id', 'title', 'qty', 'amount')`. Return `array('bp' => int 0–10000, 'code' => ?string)`.

```php
osc_add_filter('tax_line_rate', function ($rate, $line, $context) {
    return $line['kind'] === 'shipping' ? array('bp' => 0, 'code' => null) : $rate;
});
```

`tax_parts` `($parts, $context)` splits a tax into parts. Default: one part named after the Tax name setting, e.g. `array(array('id' => 'vat', 'label' => 'VAT', 'share' => 10000))`. Return 1 to 4 parts. Each has an `id` (a–z, 0–9, up to 16), a `label` and a whole `share` in basis points. Shares must sum to 10000. Each line's tax is split by largest remainder, so parts add up to the whole.

The example below is the Tax GST plugin's India case.

```php
osc_add_filter('tax_parts', function ($parts, $context) {
    return $context['same_state']
        ? array(array('id' => 'cgst', 'label' => 'CGST', 'share' => 5000), array('id' => 'sgst', 'label' => 'SGST', 'share' => 5000))
        : array(array('id' => 'igst', 'label' => 'IGST', 'share' => 10000));
});
```

`tax_invoice` `($invoice, $order, $context)` edits the invoice. `$invoice` holds `title`, `tax_id_label`, `tax_id`, `place_label`, `place_of_supply`, `code_label` (default "Tax code"), `rates` (per tax line: `label, bp, part, base, tax, included, codes`), `discounted`, `extra`, `summary` and `footer`. Change labels, or append `array('label' => …, 'value' => …)` rows to `extra` (under the seller block) or `summary` (under the lines).

```php
osc_add_filter('tax_invoice', function ($invoice, $order, $context) {
    $invoice['code_label'] = 'HSN';
    $invoice['extra'][]    = array('label' => 'State code', 'value' => '07');

    return $invoice;
});
```

`tax_buyer_id` `($check, $raw, $context)` checks the buyer's tax number. `$check` is `array('valid' => bool, 'value' => normalised string, 'message' => string, 'label' => 'Tax number', 'ask' => true)`. It also runs with `$raw` empty when checkout builds the field, so a listener can set `label`, or `ask` false to not show the field. Return the check with any key changed; a refusal with no message gets the default one.

```php
osc_add_filter('tax_buyer_id', function ($check, $raw, $context) {
    if ($context['country'] !== 'GB' || $check['value'] === '') {
        return $check;
    }
    $check['valid'] = (bool) preg_match('/^GB\d{9}$/', $check['value']);

    return $check;
});
```

The India GST split is the separate Tax GST plugin, built on these filters.

Tests: `./tests/run.sh` (unit), or `SHOPCLASS_CORE=../shopclass DRIFT_DB_PORT=33061 ./tests/run.sh` to add the integration test. Regenerate the template with `php tools/make-pot.php`.

## Support

https://github.com/mindstellar/shopclass-plugin-tax/issues

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
