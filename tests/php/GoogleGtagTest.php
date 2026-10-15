<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Google\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Google\GoogleGtag;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverStripe\Dev\SapphireTest;

/**
 * The gtag JavaScript: GA4 ecommerce events, the Google Ads conversion, Enhanced Conversions (raw, to be hashed
 * client-side by Google), and the transaction_id dedup key.
 */
class GoogleGtagTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function event(EventName $name, int $orderID = 0, ?ConversionUser $user = null): ConversionEvent
    {
        return new ConversionEvent(
            name: $name,
            eventId: 'e.1',
            items: [new ConversionItem('RJ-001', 'RJ', 'Rain Jacket', 12.95, 2, 'Acme')],
            value: 25.90,
            currency: 'EUR',
            user: $user ?? new ConversionUser(),
            orderID: $orderID,
        );
    }

    public function testLoaderUrlCarriesId(): void
    {
        $this->assertSame(
            'https://www.googletagmanager.com/gtag/js?id=G-ABC123',
            GoogleGtag::create()->loaderUrl('G-ABC123')
        );
    }

    public function testBaseConfigScriptConfiguresMeasurementId(): void
    {
        $js = GoogleGtag::create()->baseConfigScript('G-ABC123');
        $this->assertStringContainsString("gtag('js', new Date())", $js);
        $this->assertStringContainsString('gtag("config","G-ABC123")', $js);
    }

    public function testGa4ViewItemEvent(): void
    {
        $js = GoogleGtag::create()->ga4EventScript($this->event(EventName::VIEW_CONTENT));
        $this->assertStringContainsString("gtag('event',\"view_item\"", $js);
        $this->assertStringContainsString('"currency":"EUR"', $js);
        $this->assertStringContainsString('"item_id":"RJ-001"', $js);
        $this->assertStringNotContainsString('transaction_id', $js);
    }

    public function testGa4PurchaseCarriesTransactionId(): void
    {
        $params = GoogleGtag::create()->ga4Params($this->event(EventName::PURCHASE, 42));
        $this->assertSame('42', $params['transaction_id']);
        $this->assertSame(25.90, $params['value']);
        $this->assertSame('RJ-001', $params['items'][0]['item_id']);
        $this->assertSame('RJ', $params['items'][0]['item_group_id']);
        $this->assertSame('Acme', $params['items'][0]['item_brand']);
    }

    public function testGa4EventEmptyForPageView(): void
    {
        $this->assertSame('', GoogleGtag::create()->ga4EventScript($this->event(EventName::PAGE_VIEW)));
    }

    public function testAdsConversionScript(): void
    {
        $js = GoogleGtag::create()->adsConversionScript($this->event(EventName::PURCHASE, 42), 'AW-999', 'abcLabel');
        $this->assertStringContainsString("gtag('event','conversion'", $js);
        $this->assertStringContainsString('"send_to":"AW-999/abcLabel"', $js);
        $this->assertStringContainsString('"transaction_id":"42"', $js);
        $this->assertStringContainsString('"value":25.9', $js);
    }

    public function testEnhancedConversionsCarriesRawPii(): void
    {
        $user = new ConversionUser(
            email: 'buyer@example.com',
            phone: '+31612345678',
            firstName: 'Ada',
            lastName: 'Lovelace',
            postcode: '1011AB',
            country: 'NL',
        );
        $js = GoogleGtag::create()->enhancedConversionsScript($user);

        $this->assertStringContainsString("gtag('set','user_data'", $js);
        // Raw (un-hashed) — Google hashes client-side.
        $this->assertStringContainsString('"email":"buyer@example.com"', $js);
        $this->assertStringContainsString('"phone_number":"+31612345678"', $js);
        $this->assertStringContainsString('"first_name":"Ada"', $js);
        $this->assertStringContainsString('"postal_code":"1011AB"', $js);
    }

    public function testEnhancedConversionsEmptyWithoutData(): void
    {
        $this->assertSame('', GoogleGtag::create()->enhancedConversionsScript(new ConversionUser()));
    }
}
