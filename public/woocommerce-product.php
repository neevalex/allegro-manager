<?php
declare(strict_types=1);
$bootstrap = __DIR__ . '/bootstrap.php';
if (!file_exists($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/public/bootstrap.php';
}
require_once $bootstrap;

$wooConfigured = AllegroConfig::isWooConfigured($config);
$wooClient = new WooCommerceClient($config);
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/woocommerce-product.php', PHP_URL_PATH) ?: '/woocommerce-product.php';

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
  <style>
    :root { --ink:#111827; --muted:#667085; --line:#e5e7eb; --amber:#ff6b00; --amber-dark:#c2410c; --green:#15803d; --red:#b91c1c; --bg:#f8fafc; --page-pad:clamp(16px,3vw,32px); --gap:clamp(16px,2.4vw,22px); --card-pad:clamp(20px,4vw,42px); --radius:clamp(22px,4vw,28px); }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; color:var(--ink); background:radial-gradient(circle at 12% 12%, rgba(255,107,0,.13), transparent 30rem), linear-gradient(135deg, #fff 0%, var(--bg) 55%, #fff7ed 100%); padding:var(--page-pad); }
    main { width:min(1380px,100%); margin:0 auto; display:grid; gap:var(--gap); }
    .card { background:rgba(255,255,255,.92); border:1px solid rgba(229,231,235,.95); border-radius:var(--radius); box-shadow:0 24px 80px rgba(17,24,39,.08); padding:var(--card-pad); }
    .topbar { display:flex; align-items:center; justify-content:space-between; gap:var(--gap); margin-bottom:clamp(18px,3vw,28px); }
    .header-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; }
    .header-actions form { margin:0; }
    .nav-shell { margin-bottom:clamp(18px,3vw,28px); }
    .tabs { display:flex; flex-wrap:wrap; gap:10px; }
    .tab-link { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:11px 16px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.92); color:var(--muted); text-decoration:none; font-weight:800; transition:all .18s ease; }
    .tab-link:hover { color:var(--ink); border-color:#fdba74; background:#fffaf5; }
    .tab-link.active { background:linear-gradient(180deg, #ff8a1f, #ff6b00); border-color:#ff6b00; color:#fff; box-shadow:0 14px 30px rgba(255,107,0,.22); }
    .logo { width:min(260px,58vw); height:auto; display:block; }
    .eyebrow { margin:0 0 10px; color:var(--amber-dark); font-size:13px; font-weight:800; letter-spacing:.16em; text-transform:uppercase; }
    h1 { margin:0; font-size:clamp(30px,6vw,54px); line-height:1; letter-spacing:-.05em; }
    h2 { margin:0 0 16px; font-size:clamp(21px,4vw,24px); letter-spacing:-.02em; }
    p { color:var(--muted); line-height:1.65; margin:16px 0 0; }
    .btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:12px 16px; border-radius:14px; border:1px solid transparent; background:var(--ink); color:#fff; text-decoration:none; font-weight:800; cursor:pointer; }
    .btn.primary { background:var(--amber); }
    .btn.secondary { background:#fff; color:var(--ink); border-color:var(--line); }
    .btn[disabled] { opacity:.5; cursor:not-allowed; }
    .notice { border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:18px; padding:clamp(16px,3vw,20px); }
    .notice a { color:inherit; font-weight:800; }
    .error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
    .success { border-color:#bbf7d0; background:#ecfdf3; color:#166534; }
    .hero { display:grid; grid-template-columns: minmax(0,140px) minmax(0,1fr); gap:18px; align-items:start; }
    .hero-thumb { width:140px; height:140px; border-radius:24px; object-fit:cover; background:linear-gradient(135deg,#f8fafc,#fff7ed); border:1px solid var(--line); }
    .meta-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:var(--gap); }
    .meta { display:grid; gap:0; margin-top:8px; }
    .row { display:flex; justify-content:space-between; gap:18px; padding:12px 0; border-top:1px solid var(--line); }
    .row span:first-child { color:var(--muted); }
    .row span:last-child { text-align:right; font-weight:750; overflow-wrap:anywhere; }
    .pills { display:flex; flex-wrap:wrap; gap:10px; margin-top:18px; }
    .pill { display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border-radius:999px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-size:13px; font-weight:800; }
    .pill.synced { background:#ecfdf3; border-color:#bbf7d0; color:#166534; }
    .pill.unsynced { background:#f8fafc; border-color:#e5e7eb; color:#475467; }
    .sync-links { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
    .sync-link { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:800; }
    table { width:100%; border-collapse:collapse; margin-top:22px; }
    th, td { padding:14px 12px; border-top:1px solid var(--line); vertical-align:top; text-align:left; }
    th { font-size:12px; letter-spacing:.08em; color:var(--amber-dark); text-transform:uppercase; }
    .variation-cell { display:grid; grid-template-columns:56px minmax(0,1fr); gap:12px; align-items:start; }
    .variation-thumb { width:56px; height:56px; border-radius:14px; object-fit:cover; background:linear-gradient(135deg,#f8fafc,#fff7ed); border:1px solid var(--line); }
    code { background:#f3f4f6; border:1px solid #e5e7eb; border-radius:7px; padding:1px 5px; }
    .helper { font-size:14px; }
    @media (max-width: 900px) {
      .hero, .meta-grid { grid-template-columns:1fr; }
      table, thead, tbody, tr, th, td { display:block; }
      thead { display:none; }
      tr { border-top:1px solid var(--line); padding:14px 0; }
      td { border-top:none; padding:8px 0; }
      td::before { content:attr(data-label); display:block; font-size:12px; font-weight:800; letter-spacing:.08em; color:var(--amber-dark); text-transform:uppercase; margin-bottom:6px; }
      .row { flex-direction:column; gap:4px; }
      .row span:last-child { text-align:left; }
    }
  </style>
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
