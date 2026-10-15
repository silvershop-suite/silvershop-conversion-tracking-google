<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Google;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Provider\ConversionProvider;
use SilverShop\ConversionTracking\Service\ConsentGate;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Google implementation of the conversion-tracking {@see ConversionProvider}: GA4 ecommerce events + a Google Ads
 * conversion on purchase + Enhanced Conversions, all via client-side `gtag`. Consent Mode v2 is inherited from
 * silvershop/cookieconsent (which emits the default/update + maps the categories to the four signals); this
 * connector only ensures `gtag` loads + the events fire. GA4 events are gated on the `statistics` category, the
 * Google Ads conversion + Enhanced Conversions on `marketing`.
 *
 * v1 is client-side only; the server-side path (Google's Data Manager API — the post-2026 replacement for the
 * retired Ads-API offline upload) is v2, so {@see self::supportsServerSide()} is false.
 *
 * Configuration comes from the environment:
 *   SS_GA4_MEASUREMENT_ID         the GA4 measurement id (G-…)
 *   SS_GOOGLE_ADS_CONVERSION_ID   the Google Ads conversion id (AW-…)
 *   SS_GOOGLE_ADS_PURCHASE_LABEL  the Ads purchase conversion label
 */
class GoogleProvider implements ConversionProvider
{
    use Injectable;
    use Configurable;

    private static string $mode = ConversionProvider::MODE_BOTH;

    public function getKey(): string
    {
        return 'google';
    }

    public function getName(): string
    {
        return _t(self::class . '.NAME', 'Google (GA4 + Ads)');
    }

    public function isConfigured(): bool
    {
        return $this->measurementId() !== '' || $this->adsId() !== '';
    }

    public function mode(): string
    {
        return (string) static::config()->get('mode');
    }

    public function consentCategory(EventName $name): string
    {
        // GA4 analytics is the primary path (statistics); the Ads conversion sub-snippet is gated on marketing
        // internally (see clientSnippets).
        return 'statistics';
    }

    public function baseTagSnippets(): array
    {
        if (!$this->isConfigured() || $this->mode() === self::MODE_DATALAYER_ONLY) {
            return [];
        }

        $gtag = GoogleGtag::create();
        $gate = ConsentGate::create();
        $primaryId = $this->measurementId() !== '' ? $this->measurementId() : $this->adsId();
        $loaderCategory = $this->measurementId() !== '' ? 'statistics' : 'marketing';

        $snippets = [$gate->src($gtag->loaderUrl($primaryId), $loaderCategory, ['async' => true])];

        if ($this->measurementId() !== '') {
            $snippets[] = $gate->script($gtag->baseConfigScript($this->measurementId()), 'statistics');
        }
        if ($this->adsId() !== '') {
            $snippets[] = $gate->script($gtag->adsConfigScript($this->adsId()), 'marketing');
        }

        return $snippets;
    }

    public function clientSnippets(ConversionEvent $event): array
    {
        if (!$this->isConfigured() || $this->mode() === self::MODE_DATALAYER_ONLY || !$event->name->isEcommerce()) {
            return [];
        }

        $gtag = GoogleGtag::create();
        $gate = ConsentGate::create();
        $snippets = [];

        // GA4 ecommerce event → statistics.
        if ($this->measurementId() !== '') {
            $snippets[] = $gate->script($gtag->ga4EventScript($event), 'statistics');
        }

        // Google Ads conversion (+ Enhanced Conversions) on purchase → marketing.
        if ($event->name === EventName::PURCHASE && $this->adsId() !== '') {
            $enhanced = $gtag->enhancedConversionsScript($event->user);
            if ($enhanced !== '') {
                $snippets[] = $gate->script($enhanced, 'marketing');
            }
            $snippets[] = $gate->script(
                $gtag->adsConversionScript($event, $this->adsId(), $this->purchaseLabel()),
                'marketing'
            );
        }

        return $snippets;
    }

    public function supportsServerSide(): bool
    {
        // v1 is gtag-only; the server-side Data Manager API upload is v2.
        return false;
    }

    public function sendServerSide(ConversionEvent $event): bool
    {
        // No server path in v1 — graceful no-op.
        return true;
    }

    private function measurementId(): string
    {
        return (string) Environment::getEnv('SS_GA4_MEASUREMENT_ID');
    }

    private function adsId(): string
    {
        return (string) Environment::getEnv('SS_GOOGLE_ADS_CONVERSION_ID');
    }

    private function purchaseLabel(): string
    {
        return (string) Environment::getEnv('SS_GOOGLE_ADS_PURCHASE_LABEL');
    }
}
