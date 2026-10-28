<?php
/**
 * Bitlane Search for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace Bitlane\Search\Model\Documents;

/**
 * Text, prices and filter values as the document schemas want them (server/widget_public/documents_v1.go).
 */
class Fields
{
    /**
     * Currencies whose minor unit isn't the cent: their decimals, as Bitlane's server has them.
     *
     * Server/world_data/data/currencies.json (num_decimals), which the widget
     * divides prices by. Not a currency's display precision, which only says
     * how prices show (docs/magento-plan.md MG13).
     */
    public const DECIMALS = [
        'AFN' => 0, 'ALL' => 0, 'AMD' => 0, 'BHD' => 3, 'BIF' => 0, 'CLP' => 0, 'COP' => 0, 'CRC' => 0,
        'DJF' => 0, 'GNF' => 0, 'GYD' => 0, 'IDR' => 0, 'IQD' => 0, 'IRR' => 0, 'ISK' => 0, 'JOD' => 3,
        'JPY' => 0, 'KMF' => 0, 'KPW' => 0, 'KRW' => 0, 'KWD' => 3, 'LAK' => 0, 'LBP' => 0, 'LYD' => 3,
        'MGA' => 0, 'MMK' => 0, 'MNT' => 0, 'MUR' => 0, 'OMR' => 3, 'PKR' => 0, 'PYG' => 0, 'RSD' => 0,
        'RWF' => 0, 'SLL' => 0, 'SOS' => 0, 'SYP' => 0, 'TND' => 3, 'TWD' => 0, 'TZS' => 0, 'UGX' => 0,
        'UZS' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0, 'YER' => 0,
    ];

    /**
     * HTML as plain, searchable text, at most $limit characters.
     *
     * Widgets' and variables' directives ({{widget …}}) go, and Page
     * Builder's markup with the rest of the tags.
     *
     * @param string $html
     * @param int $limit
     * @return string
     */
    public function text(string $html, int $limit = 20000): string
    {
        $html = (string) preg_replace('/\{\{[^}]*\}\}/', ' ', $html);
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = strip_tags((string) preg_replace('#<(br|/p|/div|/li|/h\d)[^>]*>#i', ' $0', $html));
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain text from HTML: Magento's Escaper only escapes.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $limit);
    }

    /**
     * An amount in the currency's minor unit (cents), as the widget formats prices.
     *
     * @param float $amount
     * @param string $currency
     * @return int
     */
    public function minor(float $amount, string $currency): int
    {
        return (int) round($amount * (10 ** (self::DECIMALS[strtoupper($currency)] ?? 2)));
    }

    /**
     * A filter value as the widget reads it: "title---slug---id---swatch".
     *
     * The swatch is a color or an image's URL, N/A without one.
     *
     * @param string $title
     * @param string $slug
     * @param int|string $id
     * @param string $swatch
     * @return string
     */
    public function value(string $title, string $slug, int|string $id, string $swatch = ''): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- labels are stored with entities, shown as text.
        $title = str_replace('---', '–', html_entity_decode($title, ENT_QUOTES, 'UTF-8'));

        return implode('---', [$title, $slug !== '' ? $slug : $this->slug($title), (string) $id, $swatch ?: 'N/A']);
    }

    /**
     * A name as a URL-safe slug ("Light Blue" is "light-blue").
     *
     * @param string $name
     * @return string
     */
    public function slug(string $name): string
    {
        $ascii = function_exists('transliterator_transliterate')
            ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name)
            : strtolower($name);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    }

    /**
     * Sets of filter values merged by attribute, each value once, in their order.
     *
     * @param list<array<string,list<string>>> $sets
     * @return array<string,list<string>>
     */
    public function merge(array $sets): array
    {
        $merged = [];
        foreach ($sets as $set) {
            foreach ($set as $attribute => $values) {
                foreach ($values as $value) {
                    $merged[$attribute][$value] = true;
                }
            }
        }

        return array_map(static fn (array $values) => array_map('strval', array_keys($values)), $merged);
    }
}
