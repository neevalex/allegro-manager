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
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/woocommerce-product.php', PHP_URL_PATH) ?: '/woocommerce-product.php';
$navTabs = [
    ['label' => 'Dashboard', 'href' => '/', 'match' => ['/']],
    ['label' => 'Offers', 'href' => '/offers.php', 'match' => ['/offers.php', '/offer.php']],
    ['label' => 'WooCommerce', 'href' => '/woocommerce.php', 'match' => ['/woocommerce.php', '/woocommerce-product.php']],
    ['label' => 'Settings', 'href' => '/settings.php', 'match' => ['/settings.php']],
];

$productId = max(0, (int)($_GET['id'] ?? 0));
$backTo = is_string($_GET['back'] ?? null) ? (string)$_GET['back'] : '/woocommerce.php';
if (!str_starts_with($backTo, '/')) {
    $backTo = '/woocommerce.php';
}
$productDetails = null;
$error = null;
$refreshState = is_string($_GET['refresh'] ?? null) ? (string)$_GET['refresh'] : '';
$refreshMessage = is_string($_GET['refresh_message'] ?? null) ? (string)$_GET['refresh_message'] : '';
$refreshAt = is_string($_GET['refresh_at'] ?? null) ? (string)$_GET['refresh_at'] : '';
$refreshPid = is_string($_GET['refresh_pid'] ?? null) ? (string)$_GET['refresh_pid'] : '';
$refreshReturnTo = (string)($_SERVER['REQUEST_URI'] ?? '/woocommerce-product.php');
$refreshMeta = allegro_read_dashboard_refresh_state();

function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fmt_iso_time(?string $value): string {
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s T');
    } catch (Throwable) {
        return $value;
    }
}
function strip_html_text(?string $value): string {
    $text = trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $text;
}
function format_attribute_options(array $attribute): string {
    $options = [];
    foreach (($attribute['options'] ?? []) as $option) {
        $option = trim((string)$option);
        if ($option !== '') {
            $options[] = $option;
        }
    }
    return $options ? implode(', ', $options) : '—';
}
function format_variation_attributes(array $variation): string {
    $parts = [];
    foreach (($variation['attributes'] ?? []) as $attribute) {
        $name = trim((string)($attribute['name'] ?? ''));
        $option = trim((string)($attribute['option'] ?? ''));
        if ($name === '' && $option === '') {
            continue;
        }
        $parts[] = ($name !== '' ? $name : 'Attribute') . ': ' . ($option !== '' ? $option : '—');
    }
    return $parts ? implode(' · ', $parts) : '—';
}
function format_dimensions(array $dimensions): string {
    $parts = [];
    foreach (['length' => 'L', 'width' => 'W', 'height' => 'H'] as $key => $label) {
        $value = trim((string)($dimensions[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $label . ': ' . $value;
        }
    }
    return $parts ? implode(' · ', $parts) : '—';
}

if ($wooConfigured && $productId > 0) {
    try {
        $productDetails = $wooClient->getProductDetails($productId);
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
  <title>Allegro Manager — WooCommerce Product</title>
  <meta name="description" content="Allegro Manager — WooCommerce product detail and variations browser.">
  <link rel="icon" href="assets/allegro-manager-logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/style.css">
  <style></style>
</head>
<body>
<main>
  <section class="card">
    <div class="topbar">
      <img class="logo" src="assets/allegro-manager-logo.svg" alt="Allegro Manager logo">
      <div class="header-actions">
        <a class="btn secondary" href="<?= h($backTo) ?>">← Back to products</a>
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
    <p class="eyebrow">Store catalog</p>
    <h1>WooCommerce product</h1>
    <p>Product detail view with product-level attributes and the full variation list including SKU, stock status, and variation-specific attributes like color or size.</p>
  </section>

  <?php if ($refreshState === 'started'): ?>
    <section class="card notice"><strong>Refresh started.</strong> Dashboard data is updating in the background<?php if ($refreshPid !== ''): ?> (PID <?= h($refreshPid) ?>)<?php endif; ?>.</section>
  <?php elseif ($refreshState === 'ok'): ?>
    <section class="card notice success"><strong>Dashboard data refreshed.</strong><?php if ($refreshAt !== ''): ?> Generated at <?= h(fmt_iso_time($refreshAt)) ?>.<?php endif; ?></section>
  <?php elseif ($refreshState === 'error'): ?>
    <section class="card notice error"><strong>Refresh failed:</strong> <?= h($refreshMessage !== '' ? $refreshMessage : 'Unknown error') ?></section>
  <?php elseif (($refreshMeta['status'] ?? '') === 'running'): ?>
    <section class="card notice"><strong>Refresh in progress.</strong></section>
  <?php endif; ?>

  <?php if (!$wooConfigured): ?>
    <section class="card notice">
      <strong>WooCommerce settings are incomplete.</strong>
      Open <a href="/settings.php">Settings</a> and save the store URL, consumer key, and consumer secret first.
    </section>
  <?php endif; ?>

  <?php if ($productId <= 0): ?>
    <section class="card notice error"><strong>WooCommerce product ID is required.</strong> Open a product from the WooCommerce list page.</section>
  <?php endif; ?>

  <?php if ($error): ?>
    <section class="card notice error"><strong>WooCommerce error:</strong> <?= h($error) ?></section>
  <?php endif; ?>

  <?php if ($productDetails): ?>
    <?php $product = $productDetails['product']; ?>
    <section class="card">
      <div class="hero">
        <?php if ($product['image_url'] !== ''): ?>
          <img class="hero-thumb" src="<?= h($product['image_url']) ?>" alt="<?= h($product['name']) ?>">
        <?php else: ?>
          <div class="hero-thumb" aria-hidden="true"></div>
        <?php endif; ?>
        <div>
          <p class="eyebrow">Product detail</p>
          <h2><?= h($product['name']) ?></h2>
          <p><?= h(strip_html_text($product['short_description']) !== '' ? strip_html_text($product['short_description']) : 'No short description from WooCommerce.') ?></p>
          <div class="pills">
            <span class="pill">ID: <?= h((string)$product['id']) ?></span>
            <span class="pill">Type: <?= h($product['type'] !== '' ? $product['type'] : '—') ?></span>
            <span class="pill">SKU: <?= h($product['sku'] !== '' ? $product['sku'] : '—') ?></span>
            <span class="pill">Stock: <?= h($product['stock_status'] !== '' ? $product['stock_status'] : '—') ?><?php if ($product['stock_quantity'] !== null): ?> · <?= h((string)$product['stock_quantity']) ?><?php endif; ?></span>
            <span class="pill">Price: <?= h($product['price'] !== '' ? $product['price'] : '—') ?><?= $product['currency'] !== '' && $product['price'] !== '' ? ' ' . h($product['currency']) : '' ?></span>
            <span class="pill">Variations: <?= h((string)$productDetails['variation_count']) ?></span>
          </div>
        </div>
      </div>
    </section>

    <section class="meta-grid">
      <div class="card">
        <h2>Product info</h2>
        <div class="meta">
          <div class="row"><span>Status</span><span><?= h($product['status']) ?></span></div>
          <div class="row"><span>Catalog visibility</span><span><?= h($product['catalog_visibility'] !== '' ? $product['catalog_visibility'] : '—') ?></span></div>
          <div class="row"><span>Regular price</span><span><?= h($product['regular_price'] !== '' ? $product['regular_price'] : '—') ?><?= $product['currency'] !== '' && $product['regular_price'] !== '' ? ' ' . h($product['currency']) : '' ?></span></div>
          <div class="row"><span>Sale price</span><span><?= h($product['sale_price'] !== '' ? $product['sale_price'] : '—') ?><?= $product['currency'] !== '' && $product['sale_price'] !== '' ? ' ' . h($product['currency']) : '' ?></span></div>
          <div class="row"><span>Manage stock</span><span><?= $product['manage_stock'] ? 'yes' : 'no' ?></span></div>
          <div class="row"><span>Weight</span><span><?= h($product['weight'] !== '' ? $product['weight'] : '—') ?></span></div>
          <div class="row"><span>Dimensions</span><span><?= h(format_dimensions($product['dimensions'])) ?></span></div>
          <div class="row"><span>Created</span><span><?= h(fmt_iso_time($product['created_at'])) ?></span></div>
          <div class="row"><span>Updated</span><span><?= h(fmt_iso_time($product['updated_at'])) ?></span></div>
          <div class="row"><span>Permalink</span><span><?php if ($product['permalink'] !== ''): ?><a href="<?= h($product['permalink']) ?>" target="_blank" rel="noopener noreferrer">Open product ↗</a><?php else: ?>—<?php endif; ?></span></div>
        </div>
      </div>

      <div class="card">
        <h2>Product attributes</h2>
        <?php if ($product['attributes']): ?>
          <div class="meta">
            <?php foreach ($product['attributes'] as $attribute): ?>
              <div class="row"><span><?= h($attribute['name'] !== '' ? $attribute['name'] : ($attribute['slug'] !== '' ? $attribute['slug'] : 'Attribute')) ?></span><span><?= h(format_attribute_options($attribute)) ?></span></div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="helper">No product-level attributes were returned by WooCommerce for this item.</p>
        <?php endif; ?>
        <p class="helper">This is where product-specific data like color and size options appears when WooCommerce exposes them at the product level.</p>
      </div>
    </section>

    <section class="card">
      <p class="eyebrow">Variation matrix</p>
      <h2>Product variations</h2>
      <p>Each row shows the variation attributes, SKU, stock status, and other variation-specific data.</p>
      <?php if ($productDetails['variations']): ?>
        <table>
          <thead>
            <tr>
              <th>Variation</th>
              <th>Attributes</th>
              <th>SKU</th>
              <th>Allegro sync</th>
              <th>Price</th>
              <th>Stock</th>
              <th>Status</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($productDetails['variations'] as $variation): ?>
              <tr>
                <td data-label="Variation">
                  <div class="variation-cell">
                    <?php if ($variation['image_url'] !== ''): ?>
                      <img class="variation-thumb" src="<?= h($variation['image_url']) ?>" alt="Variation <?= h((string)$variation['id']) ?>">
                    <?php else: ?>
                      <div class="variation-thumb" aria-hidden="true"></div>
                    <?php endif; ?>
                    <div>
                      <strong>#<?= h((string)$variation['id']) ?></strong>
                      <div class="helper">Weight: <?= h($variation['weight'] !== '' ? $variation['weight'] : '—') ?> · Dimensions: <?= h(format_dimensions($variation['dimensions'])) ?></div>
                    </div>
                  </div>
                </td>
                <td data-label="Attributes"><?= h(format_variation_attributes($variation)) ?></td>
                <td data-label="SKU"><?= h($variation['sku'] !== '' ? $variation['sku'] : '—') ?></td>
                <td data-label="Allegro sync">
                  <?php if (!empty($variation['synced_to_allegro'])): ?>
                    <span class="pill synced">Synced to Allegro · ID <?= h($variation['allegro_id']) ?></span>
                    <div class="sync-links">
                      <a class="sync-link" href="<?= h($variation['allegro_frontend_url']) ?>" target="_blank" rel="noopener noreferrer">Frontend ↗</a>
                      <a class="sync-link" href="<?= h($variation['allegro_backend_url']) ?>" target="_blank" rel="noopener noreferrer">Backend ↗</a>
                    </div>
                  <?php else: ?>
                    <span class="pill unsynced">Not synced</span>
                  <?php endif; ?>
                </td>
                <td data-label="Price"><?= h($variation['price'] !== '' ? $variation['price'] : '—') ?><?= $product['currency'] !== '' && $variation['price'] !== '' ? ' ' . h($product['currency']) : '' ?></td>
                <td data-label="Stock"><?= h($variation['stock_status'] !== '' ? $variation['stock_status'] : '—') ?><?php if ($variation['stock_quantity'] !== null): ?> · <?= h((string)$variation['stock_quantity']) ?><?php endif; ?></td>
                <td data-label="Status"><?= h($variation['status'] !== '' ? $variation['status'] : '—') ?></td>
                <td data-label="Updated"><?= h(fmt_iso_time($variation['updated_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="helper">WooCommerce did not return any variations for this product. If the product is simple, that is expected.</p>
      <?php endif; ?>
      <p class="helper">API base in use: <code><?= h((string)$productDetails['meta']['api_base']) ?></code></p>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
