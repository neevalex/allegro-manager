<?php
declare(strict_types=1);
require_once '/var/www/allegro-manager/app/AllegroClient.php';

$config = AllegroConfig::load();
$configured = AllegroConfig::isConfigured($config);
$wooConfigured = AllegroConfig::isWooConfigured($config);
$client = new AllegroClient($config);
$token = $client->token();
$status = allegro_safe_token_status($token);
$wooClient = new WooCommerceClient($config);
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/woocommerce-to-allegro.php', PHP_URL_PATH) ?: '/woocommerce-to-allegro.php';
$navTabs = [
    ['label' => 'Dashboard', 'href' => '/', 'match' => ['/']],
    ['label' => 'Offers', 'href' => '/offers.php', 'match' => ['/offers.php', '/offer.php']],
    ['label' => 'WooCommerce', 'href' => '/woocommerce.php', 'match' => ['/woocommerce.php', '/woocommerce-product.php', '/woocommerce-to-allegro.php']],
    ['label' => 'Settings', 'href' => '/settings.php', 'match' => ['/settings.php']],
];

$productId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$back = is_string($_GET['back'] ?? null) ? rawurldecode((string)$_GET['back']) : (is_string($_POST['back'] ?? null) ? rawurldecode((string)$_POST['back']) : '/woocommerce.php');
if ($back === '' || !str_starts_with($back, '/')) {
    $back = '/woocommerce.php';
}
$details = null;
$draft = null;
$template = null;
$error = null;
$saveMessage = null;
$sendMessage = null;
$sendError = null;
$refreshState = is_string($_GET['refresh'] ?? null) ? (string)$_GET['refresh'] : '';
$refreshMessage = is_string($_GET['refresh_message'] ?? null) ? (string)$_GET['refresh_message'] : '';
$refreshAt = is_string($_GET['refresh_at'] ?? null) ? (string)$_GET['refresh_at'] : '';
$refreshPid = is_string($_GET['refresh_pid'] ?? null) ? (string)$_GET['refresh_pid'] : '';
$refreshReturnTo = (string)($_SERVER['REQUEST_URI'] ?? '/woocommerce-to-allegro.php');
$refreshMeta = allegro_read_dashboard_refresh_state();
$trackedItems = [];
$lastLogPreview = '';

function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fmt_iso_time(?string $value): string {
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s T');
    } catch (Throwable) {
        return $value;
    }
}
function fmt_decimal(float $value, int $precision = 2): string {
    $formatted = number_format($value, $precision, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}
function safe_slug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'item';
    return trim($value, '-') ?: 'item';
}
function data_root(): string { return '/var/www/allegro-manager/data'; }
function draft_dir(): string { return data_root() . '/woo-allegro-drafts'; }
function log_dir(): string { return data_root() . '/woo-allegro-logs'; }
function registry_path(): string { return data_root() . '/woo-allegro-created-products.json'; }
function draft_path(int $productId): string { return draft_dir() . '/product-' . $productId . '.json'; }
function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    @chmod($path, 0755);
}
function load_json_file(string $path, array $fallback = []): array {
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}
function save_json_file(string $path, array $payload): void {
    ensure_dir(dirname($path));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode JSON payload.');
    }
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not write file: ' . $path);
    }
    @chmod($path, 0644);
}
function prune_empty(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $item = prune_empty($item);
            if ($item === [] || $item === null) {
                continue;
            }
            $result[$key] = $item;
            continue;
        }
        if ($item === null) {
            continue;
        }
        if (is_string($item) && trim($item) === '') {
            continue;
        }
        $result[$key] = $item;
    }
    return $result;
}
function text_from_html(string $html, int $maxLength = 3000): string {
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
    if (mb_strlen($text) > $maxLength) {
        $text = mb_substr($text, 0, $maxLength - 1) . '…';
    }
    return trim($text);
}
function html_paragraph(string $text): string {
    $safe = nl2br(h($text), false);
    return '<p>' . $safe . '</p>';
}
function source_label(array $product, array $item): string {
    $parts = [];
    foreach (($item['attributes'] ?? []) as $attribute) {
        $name = trim((string)($attribute['name'] ?? ''));
        $option = trim((string)($attribute['option'] ?? ''));
        if ($name !== '' && $option !== '') {
            $parts[] = $name . ': ' . $option;
        }
    }
    $suffix = $parts ? ' · ' . implode(' · ', $parts) : '';
    return trim((string)$product['name']) . $suffix;
}
function compute_price_pln(string $wooPrice, array $config): float {
    $uah = (float)str_replace(',', '.', $wooPrice);
    $rate = max(0.0001, (float)($config['exchange_rate_pln_uah'] ?? 0));
    $markup = max(0, (float)($config['nacenka_percent'] ?? 50));
    $delivery = max(0, (float)($config['delivery_cost_pln'] ?? 0));
    if ($uah <= 0 || $rate <= 0) {
        return max(0, $delivery);
    }
    $basePln = $uah / $rate;
    $withMarkup = $basePln * (1 + ($markup / 100));
    return round($withMarkup + $delivery, 2);
}
function build_parameter_rows(array $product, array $item): array {
    $rows = [];
    foreach (($item['attributes'] ?? []) as $attribute) {
        $name = trim((string)($attribute['name'] ?? ''));
        $option = trim((string)($attribute['option'] ?? ''));
        if ($name === '' || $option === '') {
            continue;
        }
        $rows[] = [
            'name' => $name,
            'values' => [$option],
        ];
    }
    if ($rows === []) {
        foreach (($product['attributes'] ?? []) as $attribute) {
            $name = trim((string)($attribute['name'] ?? ''));
            $options = array_values(array_filter(array_map(static fn($option): string => trim((string)$option), $attribute['options'] ?? []), static fn(string $value): bool => $value !== ''));
            if ($name === '' || $options === []) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'values' => $options,
            ];
        }
    }
    return $rows;
}
function build_images(array $product, array $item): array {
    $images = [];
    foreach ([(string)($item['image_url'] ?? ''), (string)($product['image_url'] ?? '')] as $url) {
        $url = trim($url);
        if ($url !== '' && !in_array($url, $images, true)) {
            $images[] = $url;
        }
    }
    return $images;
}
function compute_available_stock_from_status(?string $stockStatus): int {
    $status = strtolower(trim((string)$stockStatus));
    return $status === 'instock' ? 40 : 0;
}
function build_default_payload(array $product, array $item, ?array $template, array $config): array {
    $title = source_label($product, $item);
    $sku = trim((string)($item['sku'] ?? ''));
    if ($sku === '') {
        $sku = trim((string)($product['sku'] ?? ''));
    }
    if ($sku === '') {
        $sku = 'woo-' . (int)$product['id'] . '-' . ((int)($item['id'] ?? 0) > 0 ? (int)$item['id'] : 'base');
    }
    $wooPrice = (string)($item['price'] ?? '');
    if ($wooPrice === '') {
        $wooPrice = (string)($product['price'] ?? '');
    }
    $pricePln = compute_price_pln($wooPrice, $config);
    $stockStatus = (string)($item['stock_status'] ?? $product['stock_status'] ?? '');
    $stock = compute_available_stock_from_status($stockStatus);
    $images = build_images($product, $item);
    $parameters = build_parameter_rows($product, $item);
    $descriptionText = text_from_html((string)($product['short_description'] ?? ''));
    if ($descriptionText === '') {
        $descriptionText = text_from_html((string)($product['description'] ?? ''));
    }
    $categoryId = trim((string)($template['category']['id'] ?? ''));
    $productDefinition = [
        'name' => $title,
        'parameters' => $parameters,
        'images' => $images,
    ];
    if ($categoryId !== '') {
        $productDefinition['category'] = ['id' => $categoryId];
    }

    $payload = [
        'name' => $title,
        'external' => ['id' => $sku],
        'images' => $images,
        'sellingMode' => [
            'format' => (string)($template['selling_mode_format'] ?? 'BUY_NOW'),
            'price' => [
                'amount' => number_format($pricePln, 2, '.', ''),
                'currency' => 'PLN',
            ],
        ],
        'stock' => [
            'available' => $stock,
            'unit' => 'UNIT',
        ],
        'publication' => $template['publication'] ?? ['status' => 'INACTIVE'],
        'language' => (string)($template['language'] ?? 'pl-PL'),
        'productSet' => [[
            'product' => prune_empty($productDefinition),
            'quantity' => ['value' => 1],
        ]],
        'parameters' => $parameters,
    ];
    if ($descriptionText !== '') {
        $payload['description'] = [
            'sections' => [[
                'items' => [[
                    'type' => 'TEXT',
                    'content' => html_paragraph($descriptionText),
                ]],
            ]],
        ];
    }
    foreach (['category', 'delivery', 'afterSalesServices', 'payments', 'location', 'taxSettings'] as $field) {
        if (isset($template[$field])) {
            $payload[$field] = $template[$field];
        }
    }
    return prune_empty($payload);
}
function payload_to_description_text(array $payload): string {
    $chunks = [];
    foreach (($payload['description']['sections'] ?? []) as $section) {
        foreach (($section['items'] ?? []) as $item) {
            if (strtoupper((string)($item['type'] ?? '')) !== 'TEXT') {
                continue;
            }
            $text = trim(html_entity_decode(strip_tags((string)($item['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                $chunks[] = $text;
            }
        }
    }
    return trim(implode("\n\n", $chunks));
}
function parameters_to_text(array $parameters): string {
    $lines = [];
    foreach ($parameters as $parameter) {
        $name = trim((string)($parameter['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $values = [];
        foreach (($parameter['values'] ?? []) as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $values[] = $value;
            }
        }
        $lines[] = $name . ': ' . implode(' | ', $values);
    }
    return implode("\n", $lines);
}
function parse_multiline_list(string $value): array {
    $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $result = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $result[] = $line;
        }
    }
    return $result;
}
function parse_parameters_text(string $value): array {
    $rows = [];
    foreach (parse_multiline_list($value) as $line) {
        $parts = explode(':', $line, 2);
        $name = trim((string)($parts[0] ?? ''));
        $rawValues = trim((string)($parts[1] ?? ''));
        if ($name === '') {
            continue;
        }
        $values = [];
        foreach (preg_split('/\s*\|\s*/', $rawValues) ?: [] as $single) {
            $single = trim((string)$single);
            if ($single !== '') {
                $values[] = $single;
            }
        }
        if ($values === [] && $rawValues !== '') {
            $values[] = $rawValues;
        }
        if ($values === []) {
            continue;
        }
        $rows[] = ['name' => $name, 'values' => array_values(array_unique($values))];
    }
    return $rows;
}
function payload_to_form_data(array $payload, array $fallback): array {
    $productSet = $payload['productSet'][0]['product'] ?? [];
    $parameterSource = is_array($productSet['parameters'] ?? null) && $productSet['parameters'] !== []
        ? $productSet['parameters']
        : ($payload['parameters'] ?? []);
    $imageSource = is_array($payload['images'] ?? null) && $payload['images'] !== []
        ? $payload['images']
        : ($productSet['images'] ?? []);
    return [
        'offer_name' => trim((string)($payload['name'] ?? $fallback['offer_name'] ?? '')),
        'external_id' => trim((string)($payload['external']['id'] ?? $fallback['external_id'] ?? '')),
        'selling_format' => trim((string)($payload['sellingMode']['format'] ?? $fallback['selling_format'] ?? 'BUY_NOW')),
        'price_amount' => trim((string)($payload['sellingMode']['price']['amount'] ?? $fallback['price_amount'] ?? '0.00')),
        'stock_available' => trim((string)($payload['stock']['available'] ?? $fallback['stock_available'] ?? '0')),
        'source_stock_status' => trim((string)($fallback['source_stock_status'] ?? '')),
        'publication_status' => trim((string)($payload['publication']['status'] ?? $fallback['publication_status'] ?? 'INACTIVE')),
        'language' => trim((string)($payload['language'] ?? $fallback['language'] ?? 'pl-PL')),
        'description_text' => payload_to_description_text($payload) !== '' ? payload_to_description_text($payload) : (string)($fallback['description_text'] ?? ''),
        'images_text' => implode("\n", array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $imageSource), static fn(string $value): bool => $value !== ''))),
        'category_id' => trim((string)($payload['category']['id'] ?? $productSet['category']['id'] ?? $fallback['category_id'] ?? '')),
        'parameters_text' => parameters_to_text($parameterSource) !== '' ? parameters_to_text($parameterSource) : (string)($fallback['parameters_text'] ?? ''),
    ];
}
function form_data_to_payload(array $formData, ?array $template): array {
    $name = trim((string)($formData['offer_name'] ?? ''));
    $externalId = trim((string)($formData['external_id'] ?? ''));
    $sellingFormat = trim((string)($formData['selling_format'] ?? 'BUY_NOW')) ?: 'BUY_NOW';
    $priceAmount = number_format((float)str_replace(',', '.', (string)($formData['price_amount'] ?? '0')), 2, '.', '');
    $stockAvailable = compute_available_stock_from_status($formData['source_stock_status'] ?? null);
    $publicationStatus = trim((string)($formData['publication_status'] ?? 'INACTIVE')) ?: 'INACTIVE';
    $language = trim((string)($formData['language'] ?? 'pl-PL')) ?: 'pl-PL';
    $descriptionText = trim((string)($formData['description_text'] ?? ''));
    $images = parse_multiline_list((string)($formData['images_text'] ?? ''));
    $categoryId = trim((string)($formData['category_id'] ?? ''));
    $parameters = parse_parameters_text((string)($formData['parameters_text'] ?? ''));
    $productDefinition = [
        'name' => $name,
        'images' => $images,
        'parameters' => $parameters,
    ];
    if ($categoryId !== '') {
        $productDefinition['category'] = ['id' => $categoryId];
    }
    $payload = [
        'name' => $name,
        'external' => ['id' => $externalId],
        'images' => $images,
        'sellingMode' => [
            'format' => $sellingFormat,
            'price' => [
                'amount' => $priceAmount,
                'currency' => 'PLN',
            ],
        ],
        'stock' => [
            'available' => $stockAvailable,
            'unit' => 'UNIT',
        ],
        'publication' => ['status' => $publicationStatus],
        'language' => $language,
        'productSet' => [[
            'product' => prune_empty($productDefinition),
            'quantity' => ['value' => 1],
        ]],
        'parameters' => $parameters,
    ];
    if ($descriptionText !== '') {
        $payload['description'] = [
            'sections' => [[
                'items' => [[
                    'type' => 'TEXT',
                    'content' => html_paragraph($descriptionText),
                ]],
            ]],
        ];
    }
    if ($categoryId !== '') {
        $payload['category'] = ['id' => $categoryId];
    }
    foreach (['delivery', 'afterSalesServices', 'payments', 'location', 'taxSettings'] as $field) {
        if (isset($template[$field])) {
            $payload[$field] = $template[$field];
        }
    }
    return prune_empty($payload);
}
function source_items_from_details(array $details): array {
    $product = $details['product'];
    $variations = $details['variations'] ?? [];
    if ($variations !== []) {
        return $variations;
    }
    return [[
        'id' => 0,
        'sku' => (string)($product['sku'] ?? ''),
        'status' => (string)($product['status'] ?? ''),
        'price' => (string)($product['price'] ?? ''),
        'stock_status' => (string)($product['stock_status'] ?? ''),
        'stock_quantity' => $product['stock_quantity'] ?? null,
        'attributes' => [],
        'image_url' => (string)($product['image_url'] ?? ''),
        'updated_at' => $product['updated_at'] ?? null,
        'created_at' => $product['created_at'] ?? null,
        'synced_to_allegro' => false,
        'allegro_id' => '',
        'allegro_frontend_url' => '',
        'allegro_backend_url' => '',
    ]];
}
function build_draft(array $details, ?array $template, array $config, string $back, ?array $existing = null): array {
    $product = $details['product'];
    $existingVersion = (int)($existing['version'] ?? 0);
    $preserveStoredSelection = $existingVersion >= 5;
    $existingMap = [];
    foreach (($existing['items'] ?? []) as $item) {
        if (is_array($item) && !empty($item['key'])) {
            $existingMap[(string)$item['key']] = $item;
        }
    }
    $draftItems = [];
    foreach (source_items_from_details($details) as $sourceItem) {
        $variationId = (int)($sourceItem['id'] ?? 0);
        $key = $variationId > 0 ? 'variation-' . $variationId : 'product-' . (int)$product['id'];
        $generatedPayload = build_default_payload($product, $sourceItem, $template, $config);
        $existingItem = $existingMap[$key] ?? [];
        $existingPayload = json_decode((string)($existingItem['payload_json'] ?? ''), true);
        $effectivePayload = is_array($existingPayload) ? $existingPayload : $generatedPayload;
        $defaultFormData = payload_to_form_data($generatedPayload, [
            'source_stock_status' => (string)($sourceItem['stock_status'] ?? $product['stock_status'] ?? ''),
        ]);
        $storedFormData = is_array($existingItem['form_data'] ?? null) ? $existingItem['form_data'] : null;
        $formData = is_array($storedFormData)
            ? array_merge($defaultFormData, $storedFormData)
            : payload_to_form_data($effectivePayload, $defaultFormData);
        $formData['source_stock_status'] = (string)($sourceItem['stock_status'] ?? $product['stock_status'] ?? '');
        $formData['stock_available'] = (string)compute_available_stock_from_status($formData['source_stock_status']);
        $payloadJson = json_encode(form_data_to_payload($formData, $template), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $draftItems[] = [
            'key' => $key,
            'label' => source_label($product, $sourceItem),
            'selected' => $preserveStoredSelection && array_key_exists('selected', $existingItem)
                ? (bool)$existingItem['selected']
                : ((string)($sourceItem['stock_status'] ?? $product['stock_status'] ?? '') === 'instock'),
            'woo_product_id' => (int)$product['id'],
            'woo_product_sku' => (string)($product['sku'] ?? ''),
            'woo_variation_id' => $variationId,
            'woo_variation_sku' => (string)($sourceItem['sku'] ?? ''),
            'source' => [
                'status' => (string)($sourceItem['status'] ?? ''),
                'price' => (string)($sourceItem['price'] ?? ''),
                'stock_status' => (string)($sourceItem['stock_status'] ?? ''),
                'stock_quantity' => $sourceItem['stock_quantity'] ?? null,
                'attributes' => $sourceItem['attributes'] ?? [],
                'image_url' => (string)($sourceItem['image_url'] ?? ''),
                'updated_at' => $sourceItem['updated_at'] ?? null,
                'synced_to_allegro' => !empty($sourceItem['synced_to_allegro']),
                'allegro_id' => (string)($sourceItem['allegro_id'] ?? ''),
                'allegro_frontend_url' => (string)($sourceItem['allegro_frontend_url'] ?? ''),
                'allegro_backend_url' => (string)($sourceItem['allegro_backend_url'] ?? ''),
            ],
            'form_data' => $formData,
            'payload_json' => is_string($payloadJson) ? $payloadJson : '{}',
            'last_result' => is_array($existingItem['last_result'] ?? null) ? $existingItem['last_result'] : null,
        ];
    }
    return [
        'version' => 5,
        'woo_product_id' => (int)$product['id'],
        'woo_product_name' => (string)($product['name'] ?? ''),
        'woo_product_sku' => (string)($product['sku'] ?? ''),
        'back' => $back,
        'template' => $template,
        'items' => $draftItems,
        'updated_at' => gmdate('c'),
        'last_send' => is_array($existing['last_send'] ?? null) ? $existing['last_send'] : null,
    ];
}
function apply_post_to_draft(array $draft, array $post, ?array $template): array {
    $formInputs = is_array($post['form_data'] ?? null) ? $post['form_data'] : [];
    $selectedInputs = is_array($post['selected'] ?? null) ? $post['selected'] : [];
    foreach ($draft['items'] as &$item) {
        $key = (string)$item['key'];
        $item['selected'] = array_key_exists($key, $selectedInputs);
        $currentFormData = is_array($item['form_data'] ?? null) ? $item['form_data'] : [];
        $postedFormData = is_array($formInputs[$key] ?? null) ? $formInputs[$key] : [];
        $item['form_data'] = array_merge($currentFormData, array_map(static fn($value): string => is_string($value) ? trim($value) : (string)$value, $postedFormData));
        $item['form_data']['source_stock_status'] = (string)($item['source']['stock_status'] ?? '');
        $item['form_data']['stock_available'] = (string)compute_available_stock_from_status($item['form_data']['source_stock_status']);
        $item['payload_json'] = (string)(json_encode(form_data_to_payload($item['form_data'], $template), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }
    unset($item);
    $draft['updated_at'] = gmdate('c');
    return $draft;
}
function load_registry(): array {
    return load_json_file(registry_path(), ['items' => [], 'updated_at' => null]);
}
function save_registry(array $registry): void {
    $registry['updated_at'] = gmdate('c');
    save_json_file(registry_path(), $registry);
}
function upsert_registry_record(array &$registry, array $record): void {
    $matchProduct = (int)($record['woo_product_id'] ?? 0);
    $matchVariation = (int)($record['woo_variation_id'] ?? 0);
    foreach ($registry['items'] as $index => $existing) {
        if (!is_array($existing)) {
            continue;
        }
        if ((int)($existing['woo_product_id'] ?? 0) === $matchProduct && (int)($existing['woo_variation_id'] ?? 0) === $matchVariation) {
            $registry['items'][$index] = $record;
            return;
        }
    }
    $registry['items'][] = $record;
}
function filter_tracked_for_product(array $registry, int $productId): array {
    $items = [];
    foreach (($registry['items'] ?? []) as $item) {
        if (is_array($item) && (int)($item['woo_product_id'] ?? 0) === $productId) {
            $items[] = $item;
        }
    }
    return $items;
}
function build_log_path(int $productId): string {
    ensure_dir(log_dir());
    return log_dir() . '/product-' . $productId . '-' . gmdate('Ymd-His') . '.log';
}
function load_log_preview(?string $path): string {
    if (!$path || !is_file($path)) {
        return '';
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return '';
    }
    return trim($raw);
}

if ($productId <= 0) {
    $error = 'WooCommerce product ID is missing.';
} elseif (!$wooConfigured) {
    $error = 'WooCommerce settings are incomplete.';
} else {
    try {
        $details = $wooClient->getProductDetails($productId);
        if ($configured && $status['authorized']) {
            try {
                $template = $client->latestOfferCreationTemplate();
            } catch (Throwable) {
                $template = null;
            }
        }
        $existingDraft = load_json_file(draft_path($productId), []);
        $draft = build_draft($details, $template, $config, $back, $existingDraft ?: null);
        $registry = load_registry();
        $trackedItems = filter_tracked_for_product($registry, $productId);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = trim((string)($_POST['draft_action'] ?? 'save'));
            if ($action === 'rebuild') {
                $draft = build_draft($details, $template, $config, $back, null);
                save_json_file(draft_path($productId), $draft);
                $saveMessage = 'Draft rebuilt from live WooCommerce data.';
            } else {
                $draft = apply_post_to_draft($draft, $_POST, $template);
                if ($action === 'send') {
                    if (!$configured || !$status['authorized']) {
                        throw new RuntimeException('Authorize Allegro in Settings before sending product drafts.');
                    }
                    $logPath = build_log_path($productId);
                    $logLines = [];
                    $results = [];
                    $successCount = 0;
                    $errorCount = 0;
                    $registry = load_registry();

                    foreach ($draft['items'] as &$item) {
                        if (empty($item['selected'])) {
                            continue;
                        }
                        $linePrefix = '[' . gmdate('c') . '] [' . $item['key'] . '] ';
                        $logLines[] = $linePrefix . 'Preparing payload for ' . $item['label'];
                        $payloadDecoded = json_decode((string)$item['payload_json'], true);
                        if (!is_array($payloadDecoded)) {
                            $errorCount++;
                            $message = 'Payload JSON is invalid.';
                            $item['last_result'] = [
                                'ok' => false,
                                'message' => $message,
                                'at' => gmdate('c'),
                            ];
                            $results[] = $item['last_result'] + ['key' => $item['key'], 'label' => $item['label']];
                            $logLines[] = $linePrefix . $message;
                            continue;
                        }
                        try {
                            $logLines[] = $linePrefix . 'Sending POST /sale/product-offers';
                            $created = $client->createProductOffer($payloadDecoded);
                            $offerId = trim((string)($created['offer_id'] ?? ($created['offer']['id'] ?? '')));
                            $traceId = trim((string)($created['trace_id'] ?? ''));
                            $item['last_result'] = [
                                'ok' => true,
                                'message' => 'Offer created or accepted by Allegro.',
                                'status_code' => (int)($created['status_code'] ?? 201),
                                'offer_id' => $offerId,
                                'trace_id' => $traceId,
                                'at' => gmdate('c'),
                            ];
                            $results[] = $item['last_result'] + ['key' => $item['key'], 'label' => $item['label']];
                            $successCount++;
                            $logLines[] = $linePrefix . 'SUCCESS status=' . (int)($created['status_code'] ?? 201) . ' offer_id=' . ($offerId !== '' ? $offerId : 'n/a') . ' trace_id=' . ($traceId !== '' ? $traceId : 'n/a');
                            upsert_registry_record($registry, [
                                'woo_product_id' => (int)$item['woo_product_id'],
                                'woo_product_sku' => (string)$item['woo_product_sku'],
                                'woo_variation_id' => (int)$item['woo_variation_id'],
                                'woo_variation_sku' => (string)$item['woo_variation_sku'],
                                'allegro_offer_id' => $offerId,
                                'allegro_trace_id' => $traceId,
                                'status_code' => (int)($created['status_code'] ?? 201),
                                'created_at' => gmdate('c'),
                                'label' => (string)$item['label'],
                                'log_path' => $logPath,
                            ]);
                        } catch (Throwable $e) {
                            $errorCount++;
                            $item['last_result'] = [
                                'ok' => false,
                                'message' => $e->getMessage(),
                                'at' => gmdate('c'),
                            ];
                            $results[] = $item['last_result'] + ['key' => $item['key'], 'label' => $item['label']];
                            $logLines[] = $linePrefix . 'ERROR ' . $e->getMessage();
                        }
                    }
                    unset($item);
                    $logText = implode(PHP_EOL, $logLines) . PHP_EOL;
                    file_put_contents($logPath, $logText, LOCK_EX);
                    @chmod($logPath, 0644);
                    save_registry($registry);
                    $draft['last_send'] = [
                        'at' => gmdate('c'),
                        'success_count' => $successCount,
                        'error_count' => $errorCount,
                        'log_path' => $logPath,
                        'results' => $results,
                    ];
                    save_json_file(draft_path($productId), $draft);
                    $trackedItems = filter_tracked_for_product($registry, $productId);
                    $lastLogPreview = trim($logText);
                    if ($errorCount > 0) {
                        $sendError = 'Send finished with ' . $errorCount . ' error(s). Check the log below.';
                    }
                    if ($successCount > 0 || $errorCount > 0) {
                        $sendMessage = 'Send attempt finished. Success: ' . $successCount . ' · Errors: ' . $errorCount . '.';
                    }
                } else {
                    save_json_file(draft_path($productId), $draft);
                    $saveMessage = 'Draft saved.';
                }
            }
        } else {
            save_json_file(draft_path($productId), $draft);
        }

        if ($lastLogPreview === '' && !empty($draft['last_send']['log_path'])) {
            $lastLogPreview = load_log_preview((string)$draft['last_send']['log_path']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Allegro Manager — WooCommerce to Allegro</title>
  <meta name="description" content="Prepare editable Allegro offer payloads from WooCommerce products and variations.">
  <link rel="icon" href="assets/allegro-manager-logo.svg" type="image/svg+xml">
  <style>
    :root {
      --ink:#111827; --muted:#667085; --line:#e5e7eb; --surface:#fff; --soft:#fff7ed;
      --amber:#ff6b00; --amber-dark:#c2410c; --green:#15803d; --red:#b91c1c; --bg:#f8fafc;
      --page-pad: clamp(16px, 3vw, 32px); --gap: clamp(16px, 2.4vw, 22px);
      --card-pad: clamp(20px, 4vw, 42px); --radius: clamp(22px, 4vw, 28px);
    }
    * { box-sizing: border-box; }
    body { margin:0; min-height:100vh; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color:var(--ink); background: radial-gradient(circle at 12% 12%, rgba(255,107,0,.13), transparent 30rem), linear-gradient(135deg, #fff 0%, var(--bg) 55%, #fff7ed 100%); padding:var(--page-pad); }
    main { width:min(1440px, 100%); margin:0 auto; display:grid; gap:var(--gap); }
    .card { background: rgba(255,255,255,.92); border:1px solid rgba(229,231,235,.95); border-radius:var(--radius); box-shadow:0 24px 80px rgba(17,24,39,.08); padding:var(--card-pad); }
    .topbar { display:flex; align-items:center; justify-content:space-between; gap:var(--gap); margin-bottom:clamp(18px, 3vw, 28px); }
    .header-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; }
    .header-actions form { margin:0; }
    .nav-shell { margin-bottom: clamp(18px, 3vw, 28px); }
    .tabs { display:flex; flex-wrap:wrap; gap:10px; }
    .tab-link { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:11px 16px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.92); color:var(--muted); text-decoration:none; font-weight:800; transition:all .18s ease; }
    .tab-link:hover { color:var(--ink); border-color:#fdba74; background:#fffaf5; }
    .tab-link.active { background:linear-gradient(180deg, #ff8a1f, #ff6b00); border-color:#ff6b00; color:#fff; box-shadow:0 14px 30px rgba(255,107,0,.22); }
    .logo { width:min(260px,58vw); height:auto; display:block; }
    .eyebrow { margin:0 0 10px; color:var(--amber-dark); font-size:13px; font-weight:800; letter-spacing:.16em; text-transform:uppercase; }
    h1 { margin:0; font-size:clamp(34px, 7vw, 58px); line-height:.96; letter-spacing:-.055em; }
    h2 { margin:0 0 16px; font-size:clamp(21px,4vw,24px); letter-spacing:-.02em; }
    h3 { margin:0; font-size:18px; }
    p { color:var(--muted); line-height:1.65; margin:16px 0 0; }
    .btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:12px 16px; border-radius:14px; border:1px solid transparent; background:var(--ink); color:#fff; text-decoration:none; font-weight:800; cursor:pointer; }
    .btn.primary { background:var(--amber); }
    .btn.secondary { background:#fff; color:var(--ink); border-color:var(--line); }
    .btn.danger { background:#fff; color:var(--red); border-color:#fecaca; }
    .btn[disabled] { opacity:.5; cursor:not-allowed; }
    .notice { border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:18px; padding:clamp(16px, 3vw, 20px); }
    .success { border-color:#bbf7d0; background:#ecfdf3; color:#166534; }
    .error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
    .helper { font-size:14px; }
    .pill-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:18px; }
    .pill { display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border-radius:999px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-size:13px; font-weight:800; }
    .actions { display:flex; flex-wrap:wrap; gap:12px; margin-top:24px; }
    .grid { display:grid; grid-template-columns: minmax(0,1fr) minmax(340px,.75fr); gap:var(--gap); }
    .field { display:grid; gap:8px; }
    label { font-size:14px; font-weight:800; color:var(--ink); }
    input[type='text'], input[type='number'], select, textarea { width:100%; border-radius:16px; border:1px solid var(--line); padding:14px 16px; color:var(--ink); background:#fff; }
    input[type='text'], input[type='number'], select { min-height:52px; font: 14px/1.45 Inter, ui-sans-serif, system-ui, sans-serif; }
    textarea { min-height:140px; font: 13px/1.55 ui-monospace, SFMono-Regular, Menlo, monospace; resize:vertical; }
    .item-grid { display:grid; gap:16px; }
    .item-card { border:1px solid var(--line); border-radius:22px; background:#fff; overflow:hidden; }
    .item-toggle { background:#fff; }
    .item-toggle summary { list-style:none; cursor:pointer; display:grid; grid-template-columns:minmax(0, 2.6fr) repeat(4, minmax(120px, .9fr)); gap:16px; padding:20px 46px 20px 20px; align-items:center; position:relative; }
    .item-toggle summary::-webkit-details-marker { display:none; }
    .item-toggle summary::after { content:'▾'; color:var(--muted); font-size:18px; line-height:1; position:absolute; right:18px; top:22px; }
    .item-toggle[open] summary::after { transform:rotate(180deg); }
    .item-body { padding:0 20px 20px; border-top:1px solid var(--line); }
    .item-head { display:flex; flex-wrap:wrap; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .checkline { display:flex; align-items:center; gap:10px; font-weight:800; }
    input[type='checkbox'] { width:18px; height:18px; }
    .summary-main { min-width:0; }
    .summary-main h3 { margin:0; }
    .summary-meta-line { margin-top:6px; color:var(--muted); font-size:14px; overflow-wrap:anywhere; }
    .summary-stat { min-width:0; }
    .summary-stat-label { font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
    .summary-stat-value { font-size:16px; font-weight:800; color:var(--ink); line-height:1.3; overflow-wrap:anywhere; }
    .summary-stat-note { margin-top:4px; color:var(--muted); font-size:12px; line-height:1.35; overflow-wrap:anywhere; }
    .summary-check { display:flex; align-items:center; gap:10px; min-height:100%; font-weight:800; justify-content:flex-start; }
    .meta { display:grid; gap:0; }
    .row { display:flex; justify-content:space-between; gap:18px; padding:10px 0; border-top:1px solid var(--line); }
    .row span:first-child { color:var(--muted); }
    .row span:last-child { text-align:right; font-weight:750; overflow-wrap:anywhere; }
    .link-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
    .mini-link { color:var(--amber-dark); font-weight:800; text-decoration:none; }
    .mini-link:hover { text-decoration:underline; }
    .editor-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; margin-top:16px; }
    .editor-section { border:1px solid var(--line); border-radius:20px; padding:18px; background:#fcfcfd; }
    .editor-section h4 { margin:0 0 14px; font-size:16px; letter-spacing:-.01em; }
    .form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
    .field.full { grid-column:1 / -1; }
    .input-note { margin:4px 0 0; color:var(--muted); font-size:12px; line-height:1.45; }
    .preview-block { margin-top:16px; }
    .preview-block textarea { min-height:220px; background:#f8fafc; }
    pre.code { margin:0; white-space:pre-wrap; word-break:break-word; background:#111827; color:#f9fafb; border-radius:18px; padding:18px; font: 12px/1.55 ui-monospace, SFMono-Regular, Menlo, monospace; overflow:auto; }
    code { background:#f3f4f6; border:1px solid #e5e7eb; border-radius:7px; padding:1px 5px; }
    .source-table { width:100%; border-collapse:collapse; }
    .matrix-toggle { border:1px solid var(--line); border-radius:20px; background:#fff; }
    .matrix-toggle summary { list-style:none; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:18px 20px; font-weight:800; }
    .matrix-toggle summary::-webkit-details-marker { display:none; }
    .matrix-toggle summary::after { content:'▾'; color:var(--amber-dark); font-size:18px; transition:transform .18s ease; }
    .matrix-toggle[open] summary::after { transform:rotate(180deg); }
    .matrix-toggle-body { padding:0 20px 18px; }
    .matrix-toggle-note { margin:0 0 14px; color:var(--muted); }
    .source-table th, .source-table td { padding:12px 10px; border-top:1px solid var(--line); vertical-align:top; text-align:left; }
    .source-table th { font-size:12px; letter-spacing:.08em; color:var(--amber-dark); text-transform:uppercase; }
    @media (max-width: 1280px) {
      .item-toggle summary { grid-template-columns:minmax(0, 2fr) repeat(2, minmax(130px, 1fr)); }
      .summary-check { grid-column:2; }
    }
    @media (max-width: 1100px) { .grid { grid-template-columns:1fr; } .editor-grid, .form-grid { grid-template-columns:1fr; } .item-toggle summary { grid-template-columns:1fr 1fr; } }
    @media (max-width: 900px) {
      .source-table, .source-table thead, .source-table tbody, .source-table tr, .source-table th, .source-table td { display:block; }
      .source-table thead { display:none; }
      .source-table tr { border-top:1px solid var(--line); padding:12px 0; }
      .source-table td { border-top:none; padding:8px 0; }
      .source-table td::before { content:attr(data-label); display:block; font-size:12px; font-weight:800; letter-spacing:.08em; color:var(--amber-dark); text-transform:uppercase; margin-bottom:6px; }
      .item-toggle summary { grid-template-columns:1fr; gap:12px; padding-right:44px; }
      .summary-check { grid-column:auto; }
    }
    @media (max-width: 440px) {
      .logo { width:min(190px,56vw); }
      .header-actions .btn { padding:10px 12px; font-size:12px; }
      h1 { font-size:clamp(32px,10vw,42px); }
      .row { flex-direction:column; gap:4px; }
      .row span:last-child { text-align:left; }
      .actions { display:grid; grid-template-columns:1fr; }
      .btn { width:100%; }
    }
  </style>
</head>
<body>
<main>
  <section class="card">
    <div class="topbar">
      <img class="logo" src="assets/allegro-manager-logo.svg" alt="Allegro Manager logo">
      <div class="header-actions">
        <form method="post" action="/refresh.php">
          <input type="hidden" name="return_to" value="<?= h($refreshReturnTo) ?>">
          <button class="btn primary" type="submit"<?= (!$configured || !$status['authorized']) ? ' disabled' : '' ?>>Refresh data</button>
        </form>
      </div>
    </div>
    <nav class="nav-shell" aria-label="Primary">
      <div class="tabs" role="tablist" aria-label="Dashboard sections">
        <?php foreach ($navTabs as $tab): ?>
          <?php $isActive = in_array($currentPath, $tab['match'], true); ?>
          <a class="tab-link<?= $isActive ? ' active' : '' ?>" href="<?= h($tab['href']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= h($tab['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </nav>
    <p class="eyebrow">Woo → Allegro workflow</p>
    <h1>Prepare Allegro offer draft</h1>
    <p>Review the live WooCommerce product data, edit the offer through normal fields and selectors, then click <strong>Send selected to Allegro</strong>. Each item is now split into <strong>Product itself</strong> and <strong>Product categories + parameters</strong>, while the generated JSON stays visible only as a read-only preview.</p>
    <?php if ($details): ?>
      <div class="pill-row">
        <span class="pill">Woo product ID: <?= h((string)$details['product']['id']) ?></span>
        <span class="pill">Product SKU: <?= h($details['product']['sku'] !== '' ? $details['product']['sku'] : '—') ?></span>
        <span class="pill">Items prepared: <?= h((string)count($draft['items'] ?? [])) ?></span>
        <span class="pill">Template offer: <?= h((string)($template['source_offer_id'] ?? 'none')) ?></span>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($refreshState === 'started'): ?>
    <section class="card notice"><strong>Refresh started.</strong> Dashboard data is updating in the background<?php if ($refreshPid !== ''): ?> (PID <?= h($refreshPid) ?>)<?php endif; ?>.</section>
  <?php elseif ($refreshState === 'ok'): ?>
    <section class="card notice success"><strong>Dashboard data refreshed.</strong><?php if ($refreshAt !== ''): ?> Generated at <?= h(fmt_iso_time($refreshAt)) ?>.<?php endif; ?></section>
  <?php elseif ($refreshState === 'error'): ?>
    <section class="card notice error"><strong>Refresh failed:</strong> <?= h($refreshMessage !== '' ? $refreshMessage : 'Unknown error') ?></section>
  <?php elseif (($refreshMeta['status'] ?? '') === 'running'): ?>
    <section class="card notice"><strong>Refresh in progress.</strong><?php if (!empty($refreshMeta['started_at'])): ?> Started at <?= h(fmt_iso_time((string)$refreshMeta['started_at'])) ?>.<?php endif; ?></section>
  <?php endif; ?>

  <?php if ($error): ?>
    <section class="card notice error"><strong>Workflow error:</strong> <?= h($error) ?></section>
  <?php endif; ?>
  <?php if ($saveMessage): ?>
    <section class="card notice success"><strong><?= h($saveMessage) ?></strong></section>
  <?php endif; ?>
  <?php if ($sendMessage): ?>
    <section class="card notice success"><strong><?= h($sendMessage) ?></strong></section>
  <?php endif; ?>
  <?php if ($sendError): ?>
    <section class="card notice error"><strong><?= h($sendError) ?></strong></section>
  <?php endif; ?>

  <?php if ($details && $draft): ?>
    <section class="grid">
      <div class="card">
        <div class="actions" style="margin-top:0;">
          <a class="btn secondary" href="<?= h($back) ?>">← Back to WooCommerce</a>
          <a class="btn secondary" href="/woocommerce-product.php?id=<?= h((string)$details['product']['id']) ?>&amp;back=<?= h(rawurlencode($back)) ?>">Open product detail</a>
          <?php if ($details['product']['permalink'] !== ''): ?>
            <a class="btn secondary" href="<?= h($details['product']['permalink']) ?>" target="_blank" rel="noopener noreferrer">Open product ↗</a>
          <?php endif; ?>
        </div>
        <h2>Live WooCommerce source data</h2>
        <div class="meta">
          <div class="row"><span>Name</span><span><?= h($details['product']['name']) ?></span></div>
          <div class="row"><span>Type</span><span><?= h($details['product']['type']) ?></span></div>
          <div class="row"><span>Price (Woo)</span><span><?= h($details['product']['price'] !== '' ? $details['product']['price'] : '—') ?><?= $details['product']['currency'] !== '' && $details['product']['price'] !== '' ? ' ' . h($details['product']['currency']) : '' ?></span></div>
          <div class="row"><span>Stock</span><span><?= h($details['product']['stock_status']) ?><?php if ($details['product']['stock_quantity'] !== null): ?> · <?= h((string)$details['product']['stock_quantity']) ?><?php endif; ?></span></div>
          <div class="row"><span>Updated</span><span><?= h(fmt_iso_time($details['product']['updated_at'])) ?></span></div>
          <div class="row"><span>Exchange rate</span><span>1 PLN = <?= h(fmt_decimal((float)($config['exchange_rate_pln_uah'] ?? 0), 4)) ?> UAH</span></div>
          <div class="row"><span>Nacenka</span><span><?= h(fmt_decimal((float)($config['nacenka_percent'] ?? 50), 2)) ?>%</span></div>
          <div class="row"><span>Delivery cost</span><span><?= h(fmt_decimal((float)($config['delivery_cost_pln'] ?? 0), 2)) ?> PLN</span></div>
        </div>
        <p class="helper">Default Allegro price formula used in the generated payloads: <code>(Woo UAH ÷ exchange_rate) × (1 + nacenka%) + delivery_cost_pln</code>.</p>
      </div>

      <div class="card">
        <h2>Tracked products already added</h2>
        <?php if ($trackedItems !== []): ?>
          <div class="meta">
            <?php foreach ($trackedItems as $tracked): ?>
              <div class="row"><span><?= h((string)($tracked['label'] ?? ('Variation ' . ($tracked['woo_variation_id'] ?? 0)))) ?></span><span>#<?= h((string)($tracked['allegro_offer_id'] ?? '—')) ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p>No successful Allegro creations have been tracked for this WooCommerce product yet.</p>
        <?php endif; ?>
        <?php if ($template): ?>
          <p class="helper">Default reusable Allegro fields were cloned from offer <code><?= h((string)$template['source_offer_id']) ?></code>. Edit the form fields below and the page will rebuild the Allegro payload automatically.</p>
        <?php else: ?>
          <p class="helper">No existing Allegro offer template could be loaded, so the payloads below use only WooCommerce data + pricing settings.</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <details class="matrix-toggle">
        <summary>
          <span>Variation / item source matrix</span>
          <span><?= h((string)count($draft['items'])) ?> items</span>
        </summary>
        <div class="matrix-toggle-body">
          <p class="matrix-toggle-note">Collapsed by default. Expand only when you need to inspect the live WooCommerce source rows.</p>
          <table class="source-table">
            <thead>
              <tr>
                <th>Item</th>
                <th>SKU</th>
                <th>Woo price</th>
                <th>Stock</th>
                <th>Attributes</th>
                <th>Sync</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($draft['items'] as $item): ?>
                <?php $source = $item['source']; $attrs = []; foreach (($source['attributes'] ?? []) as $attribute) { $attrs[] = trim((string)($attribute['name'] ?? '')) . ': ' . trim((string)($attribute['option'] ?? '')); } ?>
                <tr>
                  <td data-label="Item"><?= h((string)$item['label']) ?></td>
                  <td data-label="SKU"><?= h((string)($item['woo_variation_sku'] !== '' ? $item['woo_variation_sku'] : $item['woo_product_sku'])) ?></td>
                  <td data-label="Woo price"><?= h((string)($source['price'] !== '' ? $source['price'] : $details['product']['price'])) ?><?= $details['product']['currency'] !== '' ? ' ' . h($details['product']['currency']) : '' ?></td>
                  <td data-label="Stock"><?= h((string)($source['stock_status'] ?? '')) ?><?php if (($source['stock_quantity'] ?? null) !== null): ?> · <?= h((string)$source['stock_quantity']) ?><?php endif; ?></td>
                  <td data-label="Attributes"><?= h($attrs ? implode(' · ', $attrs) : '—') ?></td>
                  <td data-label="Sync">
                    <?php if (!empty($source['synced_to_allegro'])): ?>
                      Synced · ID <?= h((string)$source['allegro_id']) ?>
                    <?php else: ?>
                      Not synced yet
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    </section>

    <section class="card">
      <form method="post" action="/woocommerce-to-allegro.php">
        <input type="hidden" name="id" value="<?= h((string)$productId) ?>">
        <input type="hidden" name="back" value="<?= h(rawurlencode($back)) ?>">
        <div class="actions" style="margin-top:0;">
          <button class="btn primary" type="submit" name="draft_action" value="save">Save draft</button>
          <button class="btn secondary" type="submit" name="draft_action" value="send">Send selected to Allegro</button>
          <button class="btn danger" type="submit" name="draft_action" value="rebuild" onclick="return confirm('Rebuild this draft from live WooCommerce data and discard local edits?');">Rebuild from WooCommerce</button>
        </div>
        <p class="helper">Use the structured fields below instead of editing raw JSON. The item editor is split into <strong>Product itself</strong> and <strong>Product categories + parameters</strong>, and the generated request body is shown only as a read-only preview.</p>

        <div class="item-grid">
          <?php foreach ($draft['items'] as $item): ?>
            <?php $form = is_array($item['form_data'] ?? null) ? $item['form_data'] : []; ?>
            <article class="item-card">
              <details class="item-toggle">
                <summary>
                  <div class="summary-main">
                    <h3><?= h((string)$item['label']) ?></h3>
                    <p class="summary-meta-line">Woo variation ID: <?= h((string)$item['woo_variation_id']) ?> · SKU: <?= h((string)($item['woo_variation_sku'] !== '' ? $item['woo_variation_sku'] : $item['woo_product_sku'])) ?></p>
                  </div>
                  <div class="summary-stat">
                    <div class="summary-stat-label">Price</div>
                    <div class="summary-stat-value"><?= h((string)($form['price_amount'] ?? '0.00')) ?> PLN</div>
                  </div>
                  <div class="summary-stat">
                    <div class="summary-stat-label">Stock</div>
                    <div class="summary-stat-value"><?= h((string)($form['stock_available'] ?? '0')) ?></div>
                    <div class="summary-stat-note"><?= h((string)($item['source']['stock_status'] ?? '')) ?></div>
                  </div>
                  <label class="summary-check" onclick="event.stopPropagation();">
                    <input type="checkbox" name="selected[<?= h((string)$item['key']) ?>]" value="1"<?= !empty($item['selected']) ? ' checked' : '' ?> onclick="event.stopPropagation();">
                    <span>Select for send</span>
                  </label>
                  <div class="summary-stat">
                    <div class="summary-stat-label">Woo sync state</div>
                    <div class="summary-stat-value"><?= !empty($item['source']['synced_to_allegro']) ? 'Already synced' : 'Not synced' ?></div>
                    <div class="summary-stat-note"><?= !empty($item['source']['synced_to_allegro']) ? 'ID ' . h((string)$item['source']['allegro_id']) : 'Not sent yet' ?></div>
                  </div>
                </summary>
                <div class="item-body">
                  <p class="helper" style="margin:0 0 16px;">Expanded editor for Woo variation ID <?= h((string)$item['woo_variation_id']) ?>. Summary data stays in the collapsed header above.</p>
                  <?php if (!empty($item['source']['allegro_frontend_url']) || !empty($item['source']['allegro_backend_url'])): ?>
                    <div class="link-row">
                      <?php if (!empty($item['source']['allegro_frontend_url'])): ?><a class="mini-link" href="<?= h((string)$item['source']['allegro_frontend_url']) ?>" target="_blank" rel="noopener noreferrer">Frontend ↗</a><?php endif; ?>
                      <?php if (!empty($item['source']['allegro_backend_url'])): ?><a class="mini-link" href="<?= h((string)$item['source']['allegro_backend_url']) ?>" target="_blank" rel="noopener noreferrer">Backend ↗</a><?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <div class="editor-grid">
                    <section class="editor-section">
                      <h4>Product itself</h4>
                      <div class="form-grid">
                        <div class="field full">
                          <label for="offer_name_<?= h((string)$item['key']) ?>">Offer name</label>
                          <input id="offer_name_<?= h((string)$item['key']) ?>" type="text" name="form_data[<?= h((string)$item['key']) ?>][offer_name]" value="<?= h((string)($form['offer_name'] ?? '')) ?>">
                        </div>
                        <div class="field">
                          <label for="external_id_<?= h((string)$item['key']) ?>">External ID / SKU</label>
                          <input id="external_id_<?= h((string)$item['key']) ?>" type="text" name="form_data[<?= h((string)$item['key']) ?>][external_id]" value="<?= h((string)($form['external_id'] ?? '')) ?>">
                        </div>
                        <div class="field">
                          <label for="language_<?= h((string)$item['key']) ?>">Language</label>
                          <select id="language_<?= h((string)$item['key']) ?>" name="form_data[<?= h((string)$item['key']) ?>][language]">
                            <?php foreach (['pl-PL', 'en-US', 'uk-UA'] as $languageOption): ?>
                              <option value="<?= h($languageOption) ?>"<?= (($form['language'] ?? '') === $languageOption) ? ' selected' : '' ?>><?= h($languageOption) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="field">
                          <label for="selling_format_<?= h((string)$item['key']) ?>">Selling format</label>
                          <select id="selling_format_<?= h((string)$item['key']) ?>" name="form_data[<?= h((string)$item['key']) ?>][selling_format]">
                            <?php foreach (['BUY_NOW', 'ADVERTISEMENT'] as $formatOption): ?>
                              <option value="<?= h($formatOption) ?>"<?= (($form['selling_format'] ?? '') === $formatOption) ? ' selected' : '' ?>><?= h($formatOption) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="field">
                          <label for="price_amount_<?= h((string)$item['key']) ?>">Price (PLN)</label>
                          <input id="price_amount_<?= h((string)$item['key']) ?>" type="number" step="0.01" min="0" name="form_data[<?= h((string)$item['key']) ?>][price_amount]" value="<?= h((string)($form['price_amount'] ?? '0.00')) ?>">
                        </div>
                        <div class="field">
                          <label for="stock_available_<?= h((string)$item['key']) ?>">Available stock</label>
                          <input id="stock_available_<?= h((string)$item['key']) ?>" type="number" step="1" min="0" name="form_data[<?= h((string)$item['key']) ?>][stock_available]" value="<?= h((string)($form['stock_available'] ?? '0')) ?>" readonly>
                          <input type="hidden" name="form_data[<?= h((string)$item['key']) ?>][source_stock_status]" value="<?= h((string)($form['source_stock_status'] ?? ($item['source']['stock_status'] ?? ''))) ?>">
                          <p class="input-note">Automatically derived from WooCommerce stock status: <code>instock → 40</code>, <code>outofstock → 0</code>.</p>
                        </div>
                        <div class="field full">
                          <label for="publication_status_<?= h((string)$item['key']) ?>">Publication status</label>
                          <select id="publication_status_<?= h((string)$item['key']) ?>" name="form_data[<?= h((string)$item['key']) ?>][publication_status]">
                            <?php foreach (['INACTIVE', 'ACTIVE', 'ENDED'] as $statusOption): ?>
                              <option value="<?= h($statusOption) ?>"<?= (($form['publication_status'] ?? '') === $statusOption) ? ' selected' : '' ?>><?= h($statusOption) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="field full">
                          <label for="images_text_<?= h((string)$item['key']) ?>">Images</label>
                          <textarea id="images_text_<?= h((string)$item['key']) ?>" name="form_data[<?= h((string)$item['key']) ?>][images_text]"><?= h((string)($form['images_text'] ?? '')) ?></textarea>
                          <p class="input-note">One image URL per line.</p>
                        </div>
                        <div class="field full">
                          <label for="description_text_<?= h((string)$item['key']) ?>">Description</label>
                          <textarea id="description_text_<?= h((string)$item['key']) ?>" name="form_data[<?= h((string)$item['key']) ?>][description_text]"><?= h((string)($form['description_text'] ?? '')) ?></textarea>
                        </div>
                      </div>
                    </section>

                    <section class="editor-section">
                      <h4>Product categories + parameters</h4>
                      <div class="form-grid">
                        <div class="field full">
                          <label for="category_id_<?= h((string)$item['key']) ?>">Category ID</label>
                          <input id="category_id_<?= h((string)$item['key']) ?>" type="text" name="form_data[<?= h((string)$item['key']) ?>][category_id]" value="<?= h((string)($form['category_id'] ?? '')) ?>">
                          <p class="input-note">This category is used both for the main offer and the product definition inside <code>productSet</code>.</p>
                        </div>
                        <div class="field full">
                          <label for="parameters_text_<?= h((string)$item['key']) ?>">Category / product parameters</label>
                          <textarea id="parameters_text_<?= h((string)$item['key']) ?>" name="form_data[<?= h((string)$item['key']) ?>][parameters_text]"><?= h((string)($form['parameters_text'] ?? '')) ?></textarea>
                          <p class="input-note">One parameter per line. Format: <code>Name: value</code> or <code>Name: value 1 | value 2</code>.</p>
                        </div>
                      </div>
                    </section>
                  </div>

                  <div class="preview-block">
                    <div class="field full">
                      <label for="payload_preview_<?= h((string)$item['key']) ?>">Generated Allegro JSON preview</label>
                      <textarea id="payload_preview_<?= h((string)$item['key']) ?>" readonly><?= h((string)$item['payload_json']) ?></textarea>
                    </div>
                  </div>
                </div>
              </details>
            </article>
          <?php endforeach; ?>
        </div>
      </form>
    </section>

    <?php if (!empty($draft['last_send'])): ?>
      <section class="grid">
        <div class="card">
          <h2>Last send summary</h2>
          <div class="meta">
            <div class="row"><span>Last send at</span><span><?= h(fmt_iso_time((string)($draft['last_send']['at'] ?? ''))) ?></span></div>
            <div class="row"><span>Success count</span><span><?= h((string)($draft['last_send']['success_count'] ?? 0)) ?></span></div>
            <div class="row"><span>Error count</span><span><?= h((string)($draft['last_send']['error_count'] ?? 0)) ?></span></div>
            <div class="row"><span>Log path</span><span><code><?= h((string)($draft['last_send']['log_path'] ?? '—')) ?></code></span></div>
          </div>
        </div>
        <div class="card">
          <h2>Tracked registry file</h2>
          <p class="helper">Successful creations are persisted in <code><?= h(registry_path()) ?></code> with Woo product IDs, variation IDs, SKUs, Allegro offer IDs, trace IDs, and log paths.</p>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($lastLogPreview !== ''): ?>
      <section class="card">
        <h2>Last process log</h2>
        <pre class="code"><?= h($lastLogPreview) ?></pre>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
