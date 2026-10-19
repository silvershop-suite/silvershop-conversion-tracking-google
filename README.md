# SilverShop Conversion Tracking — Google connector

Google connector for [`silvershop-suite/conversion-tracking`](https://github.com/silvershop/silvershop-conversion-tracking).
It implements the core's `ConversionProvider` seam for Google, client-side via `gtag`:

- **GA4 ecommerce** — `view_item` / `add_to_cart` / `begin_checkout` / `purchase`, with `items[]`, `value`,
  `currency` and `transaction_id` (= the order id) on purchase.
- **Google Ads** — a conversion on purchase (`gtag('event','conversion',{send_to:'AW-ID/LABEL',…})`).
- **Enhanced Conversions** — `gtag('set','user_data', …)` with the **raw** email / phone / address (Google hashes
  them client-side), fired before the conversion.
- **Consent Mode v2** — inherited from `silvershop-suite/cookieconsent` (which emits the `default` + `update` and maps the
  categories to `ad_storage` / `ad_user_data` / `ad_personalization` / `analytics_storage`). This connector does not
  reinvent it; it only ensures `gtag` loads + the events fire. GA4 is gated on **`statistics`**, the Ads conversion +
  Enhanced Conversions on **`marketing`**.

## Install

```bash
composer require silvershop-suite/conversion-tracking-google
```

Registers + switches itself on, inert until configured. Set the environment:

```
SS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX       # GA4 measurement id
SS_GOOGLE_ADS_CONVERSION_ID=AW-XXXXXXXX  # optional — Google Ads conversion id
SS_GOOGLE_ADS_PURCHASE_LABEL=abcLabel    # optional — the Ads purchase conversion label
```

Mode is configurable (`datalayer_only` | `built_in_tags` | `both`, default `both`):

```yaml
SilverShop\ConversionTracking\Google\GoogleProvider:
  mode: both
```

## Deduplication

GA4 `purchase` carries `transaction_id = {OrderID}`, so a re-view of the confirmation page does not double-count,
and the id matches the one the core mints for the Meta Pixel/CAPI pair.

## v1 scope

v1 is **client-side only**. The server-side path (Google's **Data Manager API** — the post-2026 replacement for the
retired Ads-API offline upload) is v2, so this connector reports no server-side support; the core never enqueues a
server dispatch for it.

> **Consent Mode SSR-default note.** `silvershop-suite/cookieconsent` emits the server-side `gtag('consent','default',
> …denied)` in `<head>` only when the `GA_CODE` / `GTM_CODE` env var is set; otherwise its JS runtime emits the
> default-denied before any tag activates. For a belt-and-suspenders SSR default, set one of those env vars. This
> connector does not emit a competing default.
>
> In v1 the gtag library is loaded gated on analytics (`statistics`) consent when GA4 is configured, so a
> Google-Ads-only-without-analytics setup should load `gtag.js` via GTM instead.

## Privacy / GDPR

- Enhanced-Conversions PII is sent raw (Google hashes it client-side) and **only** with `marketing` consent.
- Data is sent to **Google (US)** — declare this processor relationship (DPA + SCCs) in your privacy policy.

## Development

```bash
composer install
composer lint    # PHP_CodeSniffer (PSR-12)
composer stan    # PHPStan (silverstan, level 5)
composer test    # PHPUnit
```

`silvershop-suite/conversion-tracking` is an RVXD module resolved from the composer `repositories` VCS entry (a private
core repo needs a GitHub token — see the CI workflow).

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
