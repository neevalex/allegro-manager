<?php
declare(strict_types=1);
$bootstrap = __DIR__ . '/bootstrap.php';
if (!file_exists($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/public/bootstrap.php';
}
require_once $bootstrap;

$wooConfigured = AllegroConfig::isWooConfigured($config);
$wooClient = new WooCommerceClient($config);
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/woocommerce.php', PHP_URL_PATH) ?: '/woocommerce.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$sort = is_string($_GET['sort'] ?? null) ? (string)$_GET['sort'] : 'modified_desc';
$searchQuery = is_string($_GET['search'] ?? null) ? trim((string)$_GET['search']) : '';
$skuQuery = is_string($_GET['sku'] ?? null) ? trim((string)$_GET['sku']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? strtolower(trim((string)$_GET['status'])) : '';
$productsPage = null;
$error = null;
$refreshState = is_string($_GET['refresh'] ?? null) ? (string)$_GET['refresh'] : '';
$refreshMessage = is_string($_GET['refresh_message'] ?? null) ? (string)$_GET['refresh_message'] : '';
$refreshAt = is_string($_GET['refresh_at'] ?? null) ? (string)$_GET['refresh_at'] : '';
$refreshPid = is_string($_GET['refresh_pid'] ?? null) ? (string)$_GET['refresh_pid'] : '';
$refreshReturnTo = (string)($_SERVER['REQUEST_URI'] ?? '/woocommerce.php');
$refreshMeta = allegro_read_dashboard_refresh_state();

function build_woocommerce_url(array $overrides = []): string {
    $params = [
        'page' => $GLOBALS['page'],
        'sort' => $GLOBALS['sort'],
        'search' => $GLOBALS['searchQuery'],
        'sku' => $GLOBALS['skuQuery'],
        'status' => $GLOBALS['statusFilter'],
    ];
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return '/woocommerce.php' . ($params ? '?' . http_build_query($params) : '');
}

if ($wooConfigured) {
    try {
        $productsPage = $wooClient->listProductsPage($page, 25, $sort, $searchQuery, $skuQuery, $statusFilter);
        $sort = $productsPage['sort'];
        $searchQuery = (string)($productsPage['filters']['search'] ?? $searchQuery);
        $skuQuery = (string)($productsPage['filters']['sku'] ?? $skuQuery);
        $statusFilter = (string)($productsPage['filters']['status'] ?? $statusFilter);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $productsPage = null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Allegro Manager — WooCommerce</title>
  <meta name="description" content="Allegro Manager — WooCommerce products browser.">
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
    <h1>WooCommerce</h1>
    <p>Browse WooCommerce products with pagination, title search, exact SKU lookup, and sortable columns powered by the store REST API.</p>
    <?php if ($productsPage): ?>
      <div class="pill-row">
        <span class="pill">Total products: <?= h((string)$productsPage['pagination']['total_count']) ?></span>
        <span class="pill">Showing: <?= h((string)$productsPage['pagination']['from']) ?>–<?= h((string)$productsPage['pagination']['to']) ?></span>
        <span class="pill">Sort: <?= h($productsPage['sort_options'][$sort]['label'] ?? $sort) ?></span>
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

  <?php if (!$wooConfigured): ?>
    <section class="card notice">
      <strong>WooCommerce settings are incomplete.</strong>
      Open <a href="/settings.php">Settings</a> and save the store URL, consumer key, and consumer secret first.
    </section>
  <?php endif; ?>

  <?php if ($error): ?>
    <section class="card notice error"><strong>WooCommerce error:</strong> <?= h($error) ?></section>
  <?php endif; ?>

  <section class="card">
    <p class="eyebrow">Product catalog</p>
    <h2>Paginated product list</h2>
    <p>Default order is WooCommerce last modified descending. Search by title, filter by exact SKU or product status, and switch sort order with official REST parameters.</p>
    <form method="get" action="/woocommerce.php">
      <div class="search-grid">
        <div class="field">
          <label for="search">TITLE SEARCH</label>
          <input id="search" name="search" type="text" value="<?= h($searchQuery) ?>" placeholder="hoodie, pajamas, family set">
        </div>
        <div class="field">
          <label for="sku">SKU SEARCH</label>
          <input id="sku" name="sku" type="text" value="<?= h($skuQuery) ?>" placeholder="Exact SKU">
        </div>
        <div class="field">
          <label for="status">STATUS</label>
          <select id="status" name="status">
            <?php $statusOptions = $productsPage['filters']['status_options'] ?? ['' => 'All statuses', 'publish' => 'Publish', 'draft' => 'Draft', 'pending' => 'Pending', 'private' => 'Private']; ?>
            <?php foreach ($statusOptions as $value => $label): ?>
              <option value="<?= h((string)$value) ?>"<?= (string)$value === $statusFilter ? ' selected' : '' ?>><?= h((string)$label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="sort">SORT PRODUCTS</label>
          <select id="sort" name="sort">
            <?php $sortOptions = $productsPage['sort_options'] ?? [
                'modified_desc' => ['label' => 'Updated (newest first)'],
                'date_desc' => ['label' => 'Created (newest first)'],
                'date_asc' => ['label' => 'Created (oldest first)'],
                'title_asc' => ['label' => 'Title (A → Z)'],
                'title_desc' => ['label' => 'Title (Z → A)'],
                'price_asc' => ['label' => 'Price (low to high)'],
                'price_desc' => ['label' => 'Price (high to low)'],
            ]; ?>
            <?php foreach ($sortOptions as $value => $option): ?>
              <option value="<?= h((string)$value) ?>"<?= (string)$value === $sort ? ' selected' : '' ?>><?= h((string)$option['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <button class="btn primary" type="submit">Apply filters</button>
        </div>
      </div>
    </form>

    <?php if ($productsPage): ?>
      <?php $pagination = $productsPage['pagination']; $windowStart = max(1, $pagination['page'] - 2); $windowEnd = min($pagination['total_pages'], $pagination['page'] + 2); ?>
      <table>
        <thead>
          <tr>
            <th>Product</th>
            <th>SKU</th>
            <th>Status</th>
            <th>Price</th>
            <th>Stock</th>
            <th>Categories</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($productsPage['items'] as $product): ?>
            <tr>
              <td data-label="Product">
                <div class="product-cell">
                  <?php if ($product['image_url'] !== ''): ?>
                    <img class="thumb" src="<?= h($product['image_url']) ?>" alt="<?= h($product['name']) ?>">
                  <?php else: ?>
                    <div class="thumb" aria-hidden="true"></div>
                  <?php endif; ?>
                  <div>
                    <p class="product-title">
                      <a href="/woocommerce-product.php?id=<?= h((string)$product['id']) ?>&amp;back=<?= h(rawurlencode(build_woocommerce_url())) ?>"><?= h($product['name']) ?></a>
                    </p>
                    <div class="product-meta">
                      <span>ID: <?= h((string)$product['id']) ?></span>
                      <span>Type: <?= h($product['type'] !== '' ? $product['type'] : '—') ?></span>
                      <span>Visibility: <?= h($product['catalog_visibility'] !== '' ? $product['catalog_visibility'] : '—') ?></span>
                    </div>
                  </div>
                </div>
              </td>
              <td data-label="SKU"><?= h($product['sku'] !== '' ? $product['sku'] : '—') ?></td>
              <td data-label="Status"><?= h($product['status']) ?></td>
              <td data-label="Price"><?= h($product['price'] !== '' ? $product['price'] : '—') ?><?= $product['currency'] !== '' && $product['price'] !== '' ? ' ' . h($product['currency']) : '' ?></td>
              <td data-label="Stock"><?= h($product['stock_status']) ?><?php if ($product['stock_quantity'] !== null): ?> · <?= h((string)$product['stock_quantity']) ?><?php endif; ?></td>
              <td data-label="Categories"><?= h($product['categories'] ? implode(', ', $product['categories']) : '—') ?></td>
              <td data-label="Updated"><?= h(fmt_iso_time($product['updated_at'])) ?></td>
              <td data-label="Actions">
                <div class="table-actions">
                  <a class="btn secondary" href="/woocommerce-product.php?id=<?= h((string)$product['id']) ?>&amp;back=<?= h(rawurlencode(build_woocommerce_url())) ?>">Review</a>
                  <a class="btn primary" href="/woocommerce-to-allegro.php?id=<?= h((string)$product['id']) ?>&amp;back=<?= h(rawurlencode(build_woocommerce_url())) ?>">To Allegro</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($pagination['total_pages'] > 1): ?>
        <div class="pagination" aria-label="Pagination">
          <?php if ($pagination['has_prev']): ?>
            <a class="page-link" href="<?= h(build_woocommerce_url(['page' => $pagination['prev_page']])) ?>">← Prev</a>
          <?php endif; ?>
          <?php for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
            <?php if ($i === $pagination['page']): ?>
              <span class="page-current"><?= h((string)$i) ?></span>
            <?php else: ?>
              <a class="page-link" href="<?= h(build_woocommerce_url(['page' => $i])) ?>"><?= h((string)$i) ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($pagination['has_next']): ?>
            <a class="page-link" href="<?= h(build_woocommerce_url(['page' => $pagination['next_page']])) ?>">Next →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <p class="helper">API base in use: <code><?= h((string)$productsPage['meta']['api_base']) ?></code></p>
    <?php elseif ($wooConfigured && !$error): ?>
      <p class="helper">No WooCommerce products matched the current filters.</p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
