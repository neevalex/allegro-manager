<?php
declare(strict_types=1);

namespace App\Allegro\Offers;

/**
 * Normalizes raw Allegro API offer data into consistent format.
 */
final class OfferNormalizer
{
    public static function normalizeProductOffer(array $offer): array
    {
        $offerId = (string)($offer['id'] ?? '');
        $descriptionSections = is_array($offer['description']['sections'] ?? null) ? $offer['description']['sections'] : [];
        
        return [
            'id' => $offerId,
            'name' => (string)($offer['name'] ?? ''),
            'external_id' => (string)($offer['external']['id'] ?? ''),
            'status' => (string)($offer['publication']['status'] ?? ''),
            'price_amount' => self::normalizePriceAmount($offer['sellingMode']['price']['amount'] ?? null),
            'price_currency' => (string)($offer['sellingMode']['price']['currency'] ?? 'PLN'),
            'stock_available' => (int)($offer['stock']['available'] ?? 0),
            'stock_unit' => (string)($offer['stock']['unit'] ?? 'UNIT'),
            'category_id' => (string)($offer['category']['id'] ?? ''),
            'format' => (string)($offer['sellingMode']['format'] ?? ''),
            'primary_image_url' => (string)(is_array($offer['images'] ?? null) ? ($offer['images'][0] ?? '') : ''),
            'allegro_url' => $offerId !== '' ? 'https://allegro.pl/oferta/' . rawurlencode($offerId) : '',
            'created_at' => $offer['createdAt'] ?? null,
            'updated_at' => $offer['updatedAt'] ?? null,
            'publication_started_at' => $offer['publication']['startedAt'] ?? null,
            'publication_ended_at' => $offer['publication']['endedAt'] ?? null,
            'publication_starting_at' => $offer['publication']['startingAt'] ?? null,
            'validation' => $offer['validation'] ?? null,
            'warnings' => $offer['warnings'] ?? null,
            'description_sections' => count($descriptionSections),
            'trace_id' => $offer['_trace_id'] ?? null,
        ];
    }

    public static function normalizePriceAmount(mixed $amount): string
    {
        if (is_string($amount) && trim($amount) !== '') {
            return number_format((float)str_replace(',', '.', $amount), 2, '.', '');
        }
        if (is_numeric($amount)) {
            return number_format((float)$amount, 2, '.', '');
        }
        return '0.00';
    }

    public static function normalizeListOffers(array $rawOffers): array
    {
        $items = [];
        foreach ($rawOffers as $offer) {
            $offerId = (string)($offer['id'] ?? '');
            $items[] = [
                'id' => $offerId !== '' ? $offerId : '—',
                'name' => (string)($offer['name'] ?? 'Unnamed offer'),
                'sku' => (string)($offer['external']['id'] ?? '—'),
                'status' => (string)($offer['publication']['status'] ?? '—'),
                'started_at' => $offer['publication']['startedAt'] ?? null,
                'starting_at' => $offer['publication']['startingAt'] ?? null,
                'ended_at' => $offer['publication']['endedAt'] ?? null,
                'price' => self::extractMoneyAmount($offer['sellingMode']['price'] ?? null),
                'currency' => self::extractMoneyCurrency($offer['sellingMode']['price'] ?? null),
                'sold' => (int)($offer['stock']['sold'] ?? 0),
                'available' => (int)($offer['stock']['available'] ?? 0),
                'visits' => (int)($offer['stats']['visitsCount'] ?? 0),
                'watchers' => (int)($offer['stats']['watchersCount'] ?? 0),
                'category_id' => (string)($offer['category']['id'] ?? '—'),
                'format' => (string)($offer['sellingMode']['format'] ?? '—'),
                'image_url' => (string)($offer['primaryImage']['url'] ?? ''),
                'allegro_url' => $offerId !== '' ? 'https://allegro.pl/oferta/' . rawurlencode($offerId) : '',
            ];
        }
        return $items;
    }

    public static function extractMoneyAmount(mixed $value): ?float
    {
        if (!is_array($value) || !isset($value['amount']) || !is_numeric($value['amount'])) {
            return null;
        }
        return (float)$value['amount'];
    }

    public static function extractMoneyCurrency(mixed $value): ?string
    {
        if (!is_array($value) || empty($value['currency'])) {
            return null;
        }
        return (string)$value['currency'];
    }
}
