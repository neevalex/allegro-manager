<?php
declare(strict_types=1);

namespace App\WooCommerce;

/**
 * Normalizes raw WooCommerce REST API data into consistent format.
 */
final class ProductNormalizer
{
    public static function normalizeProduct(array $product): array
    {
        $categories = [];
        foreach (($product['categories'] ?? []) as $category) {
            if (is_array($category) && isset($category['name'])) {
                $categories[] = (string)$category['name'];
            }
        }

        $attributes = [];
        foreach (($product['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $options = [];
            foreach (($attribute['options'] ?? []) as $option) {
                $options[] = (string)$option;
            }
            $attributes[] = [
                'id' => (int)($attribute['id'] ?? 0),
                'name' => (string)($attribute['name'] ?? ''),
                'slug' => (string)($attribute['slug'] ?? ''),
                'variation' => !empty($attribute['variation']),
                'visible' => !empty($attribute['visible']),
                'options' => $options,
            ];
        }

        $images = is_array($product['images'] ?? null) ? $product['images'] : [];
        $firstImage = is_array($images[0] ?? null) ? $images[0] : [];
        $dimensions = is_array($product['dimensions'] ?? null) ? $product['dimensions'] : [];

        return [
            'id' => (int)($product['id'] ?? 0),
            'name' => (string)($product['name'] ?? ''),
            'permalink' => (string)($product['permalink'] ?? ''),
            'sku' => (string)($product['sku'] ?? ''),
            'status' => (string)($product['status'] ?? ''),
            'catalog_visibility' => (string)($product['catalog_visibility'] ?? ''),
            'price' => (string)($product['price'] ?? ''),
            'regular_price' => (string)($product['regular_price'] ?? ''),
            'sale_price' => (string)($product['sale_price'] ?? ''),
            'currency' => (string)($product['currency'] ?? ''),
            'stock_status' => (string)($product['stock_status'] ?? ''),
            'stock_quantity' => $product['stock_quantity'] ?? null,
            'manage_stock' => !empty($product['manage_stock']),
            'type' => (string)($product['type'] ?? ''),
            'featured' => !empty($product['featured']),
            'virtual' => !empty($product['virtual']),
            'downloadable' => !empty($product['downloadable']),
            'categories' => $categories,
            'attributes' => $attributes,
            'image_url' => (string)($firstImage['src'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'short_description' => (string)($product['short_description'] ?? ''),
            'weight' => (string)($product['weight'] ?? ''),
            'dimensions' => [
                'length' => (string)($dimensions['length'] ?? ''),
                'width' => (string)($dimensions['width'] ?? ''),
                'height' => (string)($dimensions['height'] ?? ''),
            ],
            'updated_at' => $product['date_modified_gmt'] ?? $product['date_modified'] ?? null,
            'created_at' => $product['date_created_gmt'] ?? $product['date_created'] ?? null,
        ];
    }

    public static function normalizeVariation(array $variation): array
    {
        $attributes = [];
        foreach (($variation['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $attributes[] = [
                'id' => (int)($attribute['id'] ?? 0),
                'name' => (string)($attribute['name'] ?? ''),
                'option' => (string)($attribute['option'] ?? ''),
            ];
        }

        $metaMap = [];
        foreach (($variation['meta_data'] ?? []) as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $key = trim((string)($meta['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $metaMap[$key] = $meta['value'] ?? null;
        }

        $allegroIdRaw = $metaMap['allegro_id'] ?? null;
        $allegroId = trim((string)(is_scalar($allegroIdRaw) ? $allegroIdRaw : ''));
        $isSyncedToAllegro = $allegroId !== '';

        $dimensions = is_array($variation['dimensions'] ?? null) ? $variation['dimensions'] : [];
        $image = is_array($variation['image'] ?? null) ? $variation['image'] : [];

        return [
            'id' => (int)($variation['id'] ?? 0),
            'sku' => (string)($variation['sku'] ?? ''),
            'status' => (string)($variation['status'] ?? ''),
            'price' => (string)($variation['price'] ?? ''),
            'regular_price' => (string)($variation['regular_price'] ?? ''),
            'sale_price' => (string)($variation['sale_price'] ?? ''),
            'stock_status' => (string)($variation['stock_status'] ?? ''),
            'stock_quantity' => $variation['stock_quantity'] ?? null,
            'manage_stock' => !empty($variation['manage_stock']),
            'on_sale' => !empty($variation['on_sale']),
            'purchasable' => !empty($variation['purchasable']),
            'virtual' => !empty($variation['virtual']),
            'downloadable' => !empty($variation['downloadable']),
            'weight' => (string)($variation['weight'] ?? ''),
            'dimensions' => [
                'length' => (string)($dimensions['length'] ?? ''),
                'width' => (string)($dimensions['width'] ?? ''),
                'height' => (string)($dimensions['height'] ?? ''),
            ],
            'attributes' => $attributes,
            'image_url' => (string)($image['src'] ?? ''),
            'updated_at' => $variation['date_modified_gmt'] ?? $variation['date_modified'] ?? null,
            'created_at' => $variation['date_created_gmt'] ?? $variation['date_created'] ?? null,
            'meta' => $metaMap,
            'allegro_id' => $allegroId,
            'synced_to_allegro' => $isSyncedToAllegro,
            'allegro_frontend_url' => $isSyncedToAllegro ? 'https://allegro.pl/oferta/' . rawurlencode($allegroId) : '',
            'allegro_backend_url' => $isSyncedToAllegro ? '/offer.php?id=' . rawurlencode($allegroId) : '',
        ];
    }
}
