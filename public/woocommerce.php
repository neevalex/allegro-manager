<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/public/bootstrap.php';

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
  <style>
    :root {
      --ink:#111827; --muted:#667085; --line:#e5e7eb; --surface:#fff; --soft:#fff7ed;
      --amber:#ff6b00; --amber-dark:#c2410c; --green:#15803d; --red:#b91c1c; --bg:#f8fafc;
      --page-pad: clamp(16px, 3vw, 32px); --gap: clamp(16px, 2.4vw, 22px);
      --card-pad: clamp(20px, 4vw, 42px); --radius: clamp(22px, 4vw, 28px);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--ink);
      background: radial-gradient(circle at 12% 12%, rgba(255,107,0,.13), transparent 30rem), linear-gradient(135deg, #fff 0%, var(--bg) 55%, #fff7ed 100%);
      padding: var(--page-pad);
    }
    main { width: min(1380px, 100%); margin: 0 auto; display: grid; gap: var(--gap); }
    .card { background: rgba(255,255,255,.92); border: 1px solid rgba(229,231,235,.95); border-radius: var(--radius); box-shadow: 0 24px 80px rgba(17,24,39,.08); padding: var(--card-pad); }
    .topbar { display: flex; align-items: center; justify-content: space-between; gap: var(--gap); margin-bottom: clamp(18px, 3vw, 28px); }
    .header-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
    .header-actions form { margin: 0; }
    .nav-shell { margin-bottom: clamp(18px, 3vw, 28px); }
    .tabs { display: flex; flex-wrap: wrap; gap: 10px; }
    .tab-link { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 11px 16px; border-radius: 999px; border: 1px solid var(--line); background: rgba(255,255,255,.92); color: var(--muted); text-decoration: none; font-weight: 800; transition: all .18s ease; }
    .tab-link:hover { color: var(--ink); border-color: #fdba74; background: #fffaf5; }
    .tab-link.active { background: linear-gradient(180deg, #ff8a1f, #ff6b00); border-color: #ff6b00; color: #fff; box-shadow: 0 14px 30px rgba(255,107,0,.22); }
    .logo { width: min(260px, 58vw); height: auto; display: block; }
    .eyebrow { margin: 0 0 10px; color: var(--amber-dark); font-size: 13px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
    h1 { margin: 0; font-size: clamp(34px, 7vw, 58px); line-height: .96; letter-spacing: -.055em; }
    h2 { margin: 0 0 16px; font-size: clamp(21px, 4vw, 24px); letter-spacing: -.02em; }
    p { color: var(--muted); line-height: 1.65; margin: 16px 0 0; }
    .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 12px 16px; border-radius: 14px; border: 1px solid transparent; background: var(--ink); color: #fff; text-decoration: none; font-weight: 800; cursor: pointer; }
    .btn.primary { background: var(--amber); }
    .btn.secondary { background: #fff; color: var(--ink); border-color: var(--line); }
    .btn[disabled] { opacity: .5; cursor: not-allowed; }
    .pill-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:18px; }
    .pill { display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border-radius:999px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-size:13px; font-weight:800; }
    .notice { border: 1px solid #fed7aa; background: #fff7ed; color: #9a3412; border-radius: 18px; padding: clamp(16px, 3vw, 20px); }
    .notice a { color: inherit; font-weight: 800; }
    .error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
    .success { border-color:#bbf7d0; background:#ecfdf3; color:#166534; }
    .helper { font-size: 14px; }
    .search-grid { display:grid; grid-template-columns: minmax(0,1.4fr) minmax(0,1fr) minmax(180px,.7fr) minmax(220px,.8fr) auto; gap:14px; align-items:end; margin-top:18px; }
    .field { display:grid; gap:8px; }
    label { font-size:13px; font-weight:800; color:var(--amber-dark); letter-spacing:.08em; }
    input, select { width:100%; min-height:46px; border-radius:14px; border:1px solid var(--line); padding:12px 14px; font:inherit; background:#fff; color:var(--ink); }
    table { width:100%; border-collapse: collapse; margin-top: 22px; }
    th, td { padding: 14px 12px; border-top: 1px solid var(--line); vertical-align: top; text-align: left; }
    th { font-size: 12px; letter-spacing: .08em; color: var(--amber-dark); text-transform: uppercase; }
    .product-cell { display:grid; grid-template-columns: 68px minmax(0,1fr); gap:14px; align-items:start; }
    .thumb { width:68px; height:68px; border-radius:16px; object-fit:cover; background:linear-gradient(135deg,#f8fafc,#fff7ed); border:1px solid var(--line); }
    .product-title { margin:0; font-weight:800; line-height:1.35; }
    .product-title a { color: inherit; text-decoration: none; }
    .product-title a:hover { text-decoration: underline; }
    .product-meta { display:flex; flex-wrap:wrap; gap:8px 12px; margin-top:8px; color:var(--muted); font-size:13px; }
    .table-actions { display:flex; flex-wrap:wrap; gap:10px; }
    .table-actions .btn { min-height:38px; padding:10px 12px; font-size:13px; }
    .pagination { display:flex; flex-wrap:wrap; gap:10px; margin-top:24px; align-items:center; }
    .page-link, .page-current { display:inline-flex; align-items:center; justify-content:center; min-width:44px; min-height:44px; padding:0 14px; border-radius:14px; border:1px solid var(--line); text-decoration:none; font-weight:800; }
    .page-link { color:var(--ink); background:#fff; }
    .page-current { color:#fff; background:var(--amber); border-color:var(--amber); }
    code { background:#f3f4f6; border:1px solid #e5e7eb; border-radius:7px; padding:1px 5px; }
    @media (max-width: 1100px) {
      .search-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
    }
    @media (max-width: 900px) {
      table, thead, tbody, tr, th, td { display:block; }
      thead { display:none; }
      tr { border-top:1px solid var(--line); padding:14px 0; }
      td { border-top:none; padding:8px 0; }
      td::before { content: attr(data-label); display:block; font-size:12px; font-weight:800; letter-spacing:.08em; color:var(--amber-dark); text-transform:uppercase; margin-bottom:6px; }
      .search-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 440px) {
      .logo { width: min(190px, 56vw); }
      .header-actions .btn { padding: 10px 12px; font-size: 12px; }
      h1 { font-size: clamp(32px, 10vw, 42px); }
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
