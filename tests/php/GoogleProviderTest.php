<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Google\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Google\GoogleProvider;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverShop\ConversionTracking\Provider\ConversionProvider;
use SilverShop\ConversionTracking\Service\ConsentGate;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * The Google provider: env-driven configuration, GA4 (statistics) vs Ads/Enhanced-Conversions (marketing)
 * consent-gated snippets, and the client-only v1 (no server-side send).
 */
class GoogleProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(ConsentGate::class, 'consent_state_class', '\\NoConsentModule');
        Config::modify()->set(ConsentGate::class, 'assume_consent_without_module', true);
    }

    protected function tearDown(): void
    {
        foreach (['SS_GA4_MEASUREMENT_ID', 'SS_GOOGLE_ADS_CONVERSION_ID', 'SS_GOOGLE_ADS_PURCHASE_LABEL'] as $key) {
            Environment::setEnv($key, '');
        }
        parent::tearDown();
    }

    private function purchaseEvent(?ConversionUser $user = null): ConversionEvent
    {
        return new ConversionEvent(
            name: EventName::PURCHASE,
            eventId: 'purchase.42',
            items: [new ConversionItem('RJ-001', null, 'Rain Jacket', 12.95, 1)],
            value: 12.95,
            currency: 'EUR',
            user: $user ?? new ConversionUser(email: 'buyer@example.com'),
            orderID: 42,
        );
    }

    private function viewEvent(): ConversionEvent
    {
        return new ConversionEvent(
            name: EventName::VIEW_CONTENT,
            eventId: 'view.7',
            items: [new ConversionItem('RJ-001', null, 'Rain Jacket', 12.95, 1)],
            value: 12.95,
            currency: 'EUR',
        );
    }

    public function testIsConfiguredWithGa4OrAds(): void
    {
        $provider = GoogleProvider::create();
        $this->assertFalse($provider->isConfigured());

        Environment::setEnv('SS_GA4_MEASUREMENT_ID', 'G-ABC');
        $this->assertTrue($provider->isConfigured());

        Environment::setEnv('SS_GA4_MEASUREMENT_ID', '');
        Environment::setEnv('SS_GOOGLE_ADS_CONVERSION_ID', 'AW-999');
        $this->assertTrue($provider->isConfigured(), 'Ads id alone configures it');
    }

    public function testConsentCategoryIsStatistics(): void
    {
        $this->assertSame('statistics', GoogleProvider::create()->consentCategory(EventName::PURCHASE));
    }

    public function testNoServerSideInV1(): void
    {
        $provider = GoogleProvider::create();
        $this->assertFalse($provider->supportsServerSide());
        $this->assertTrue($provider->sendServerSide($this->purchaseEvent()), 'no-op, never throws');
    }

    /**
     * @param array<int, \SilverStripe\ORM\FieldType\DBHTMLText> $snippets
     */
    private function html(array $snippets): string
    {
        return implode('', array_map(static fn ($t): string => (string) $t->getValue(), $snippets));
    }

    public function testBaseTagsLoadGtagAndConfigPerCategory(): void
    {
        Environment::setEnv('SS_GA4_MEASUREMENT_ID', 'G-ABC');
        Environment::setEnv('SS_GOOGLE_ADS_CONVERSION_ID', 'AW-999');

        $html = $this->html(GoogleProvider::create()->baseTagSnippets());

        $this->assertStringContainsString('googletagmanager.com/gtag/js?id=G-ABC', $html);
        $this->assertStringContainsString('gtag("config","G-ABC")', $html);
        $this->assertStringContainsString('gtag("config","AW-999")', $html);
    }

    public function testClientSnippetsGa4EventStatistics(): void
    {
        Environment::setEnv('SS_GA4_MEASUREMENT_ID', 'G-ABC');
        $snippets = GoogleProvider::create()->clientSnippets($this->viewEvent());

        $this->assertNotEmpty($snippets);
        $this->assertStringContainsString("gtag('event',\"view_item\"", (string) $snippets[0]->getValue());
    }

    public function testClientSnippetsPurchaseAddsEnhancedConversionsAndAdsConversion(): void
    {
        Environment::setEnv('SS_GA4_MEASUREMENT_ID', 'G-ABC');
        Environment::setEnv('SS_GOOGLE_ADS_CONVERSION_ID', 'AW-999');
        Environment::setEnv('SS_GOOGLE_ADS_PURCHASE_LABEL', 'lbl');

        $html = $this->html(GoogleProvider::create()->clientSnippets($this->purchaseEvent()));

        $this->assertStringContainsString("gtag('event',\"purchase\"", $html);
        $this->assertStringContainsString("gtag('set','user_data'", $html);
        $this->assertStringContainsString('"email":"buyer@example.com"', $html);
        $this->assertStringContainsString('"send_to":"AW-999/lbl"', $html);
    }

    public function testPageViewHasNoClientSnippet(): void
    {
        Environment::setEnv('SS_GA4_MEASUREMENT_ID', 'G-ABC');
        $event = new ConversionEvent(name: EventName::PAGE_VIEW, eventId: 'pv');
        $this->assertSame([], GoogleProvider::create()->clientSnippets($event));
    }

    public function testDatalayerOnlyModeEmitsNoTags(): void
    {
        Environment::setEnv('SS_GA4_MEASUREMENT_ID', 'G-ABC');
        Config::modify()->set(GoogleProvider::class, 'mode', ConversionProvider::MODE_DATALAYER_ONLY);

        $this->assertSame([], GoogleProvider::create()->baseTagSnippets());
        $this->assertSame([], GoogleProvider::create()->clientSnippets($this->viewEvent()));
    }
}
