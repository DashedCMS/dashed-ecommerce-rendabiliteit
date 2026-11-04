<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

/**
 * Last-click-marketingkanaal van een order (Order-model of databaserij) op basis van
 * de attributiekolommen die Dashed sinds mei 2026 op de order vastlegt: gclid → Google,
 * fbclid of een Meta-UTM-bron → Meta, anders een zoekmachine als referrer → organisch.
 * Orders buiten de webshop krijgen hun herkomst (Bol, Kassa, Etsy, Overig).
 */
class Marketingkanalen
{
    public const GOOGLE = 'Google Ads';

    public const META = 'Meta Ads';

    public const BEIDE = 'Google + Meta';

    public const ORGANISCH = 'Organisch zoeken';

    public const DIRECT = 'Direct / onbekend';

    /** Weergavevolgorde in het rapport. */
    public const VOLGORDE = [self::GOOGLE, self::META, self::BEIDE, self::ORGANISCH, self::DIRECT, 'Bol', 'Kassa', 'Etsy', 'Overig'];

    /** utm_source-waarden (kleine letters) die op Meta-advertenties wijzen. */
    private const META_BRONNEN = ['ig', 'fb', 'an', 'facebook', 'instagram', 'meta', 'msg'];

    private const ZOEKMACHINES = ['google', 'bing', 'ecosia', 'yahoo', 'duckduckgo'];

    public static function voor(object $order): string
    {
        $herkomst = Kanalen::voor($order->order_origin ?? null);

        if ($herkomst !== 'Webshop') {
            return $herkomst;
        }

        $google = self::gevuld($order->gclid ?? null);
        $meta = self::gevuld($order->fbclid ?? null)
            || in_array(self::normaal($order->utm_source ?? null), self::META_BRONNEN, true);

        if ($google && $meta) {
            return self::BEIDE;
        }

        if ($google) {
            return self::GOOGLE;
        }

        if ($meta) {
            return self::META;
        }

        $referrer = self::normaal($order->landing_page_referrer ?? null);

        foreach (self::ZOEKMACHINES as $zoekmachine) {
            if ($referrer !== '' && str_contains($referrer, $zoekmachine)) {
                return self::ORGANISCH;
            }
        }

        return self::DIRECT;
    }

    private static function gevuld(mixed $waarde): bool
    {
        return self::normaal($waarde) !== '';
    }

    private static function normaal(mixed $waarde): string
    {
        return mb_strtolower(trim((string) $waarde));
    }
}
