<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Google;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverStripe\Core\Injector\Injectable;

/**
 * Builds the Google `gtag` JavaScript: the base loader + `config`, the GA4 ecommerce events
 * (view_item / add_to_cart / begin_checkout / purchase), the Google Ads conversion on purchase, and the Enhanced
 * Conversions `user_data` set. GA4 uses `transaction_id` = the order id, the same dedup key the server would reuse.
 *
 * Consent Mode v2 is NOT emitted here — cookieconsent already emits the `gtag('consent','default', …denied)` and
 * the `update` on a decision, and maps the categories to the four signals; this connector only ensures `gtag` is
 * loaded + the events fire, each wrapped as a consent-gated inert script by the core
 * {@see \SilverShop\ConversionTracking\Service\ConsentGate} (GA4 → statistics, Ads → marketing).
 *
 * Enhanced Conversions are passed RAW (email/phone/address): Google requires the un-hashed fields on the web tag
 * and hashes them client-side. They are therefore strictly gated on marketing consent.
 */
class GoogleGtag
{
    use Injectable;

    public function loaderUrl(string $id): string
    {
        return 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($id);
    }

    /**
     * The gtag bootstrap + GA4 config. cookieconsent already stubs `window.gtag` / `dataLayer` and emits the
     * Consent-Mode default, so this is safe to run post-consent.
     */
    public function baseConfigScript(string $measurementId): string
    {
        return 'window.dataLayer = window.dataLayer || [];'
            . 'function gtag(){dataLayer.push(arguments);}'
            . "gtag('js', new Date());"
            . 'gtag(' . json_encode('config') . ',' . json_encode($measurementId) . ');';
    }

    public function adsConfigScript(string $adsId): string
    {
        return 'gtag(' . json_encode('config') . ',' . json_encode($adsId) . ');';
    }

    /**
     * The GA4 ecommerce event, e.g. `gtag('event','view_item',{currency,value,items:[…]})`. Empty for PageView.
     */
    public function ga4EventScript(ConversionEvent $event): string
    {
        if (!$event->name->isEcommerce()) {
            return '';
        }

        $params = json_encode($this->ga4Params($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "gtag('event'," . json_encode($event->name->ga4Name()) . ',' . $params . ');';
    }

    /**
     * @return array<string, mixed>
     */
    public function ga4Params(ConversionEvent $event): array
    {
        $params = [
            'currency' => $event->currency,
            'value' => round($event->value, 2),
        ];
        if ($event->name === EventName::PURCHASE && $event->orderID > 0) {
            $params['transaction_id'] = (string) $event->orderID;
        }
        $params['items'] = array_map(fn (ConversionItem $item): array => $this->item($item), $event->items);

        return $params;
    }

    /**
     * The Google Ads conversion, fired on purchase: `gtag('event','conversion',{send_to:'AW-ID/LABEL',…})`.
     */
    public function adsConversionScript(ConversionEvent $event, string $adsId, string $label): string
    {
        $sendTo = $label !== '' ? $adsId . '/' . $label : $adsId;
        $params = [
            'send_to' => $sendTo,
            'value' => round($event->value, 2),
            'currency' => $event->currency,
        ];
        if ($event->orderID > 0) {
            $params['transaction_id'] = (string) $event->orderID;
        }

        $json = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "gtag('event','conversion'," . $json . ');';
    }

    /**
     * Enhanced Conversions: `gtag('set','user_data',{…raw…})`. Google hashes it client-side. Returns '' when there
     * is no matchable data. MUST be gated on marketing consent and fired before the conversion.
     */
    public function enhancedConversionsScript(ConversionUser $user): string
    {
        $data = array_filter([
            'email' => $user->email,
            'phone_number' => $user->phone,
        ], static fn ($v): bool => $v !== null && $v !== '');

        $address = array_filter([
            'first_name' => $user->firstName,
            'last_name' => $user->lastName,
            'postal_code' => $user->postcode,
            'country' => $user->country,
        ], static fn ($v): bool => $v !== null && $v !== '');
        if ($address !== []) {
            $data['address'] = $address;
        }

        if ($data === []) {
            return '';
        }

        return "gtag('set','user_data'," . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');';
    }

    /**
     * One GA4 items[] entry.
     *
     * @return array<string, mixed>
     */
    private function item(ConversionItem $item): array
    {
        $entry = [
            'item_id' => $item->id,
            'item_name' => $item->name,
            'price' => round($item->price, 2),
            'quantity' => $item->quantity,
        ];
        if ($item->itemGroupId !== null && $item->itemGroupId !== '') {
            $entry['item_group_id'] = $item->itemGroupId;
        }
        if ($item->brand !== null && $item->brand !== '') {
            $entry['item_brand'] = $item->brand;
        }

        return $entry;
    }
}
