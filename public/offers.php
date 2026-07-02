<?php
declare(strict_types=1);
$bootstrap = __DIR__ . '/bootstrap.php';
if (!file_exists($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/public/bootstrap.php';
}
require_once $bootstrap;

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/offers.php', PHP_URL_PATH) ?: '/offers.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$sort = is_string($_GET['sort'] ?? null) ? (string)$_GET['sort'] : 'created_desc';
$titleQuery = is_string($_GET['title'] ?? null) ? trim((string)$_GET['title']) : '';
$skuQuery = is_string($_GET['sku'] ?? null) ? trim((string)$_GET['sku']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? strtoupper(trim((string)$_GET['status'])) : '';
$offersPage = null;
$refreshState = is_string($_GET['refresh'] ?? null) ? (string)$_GET['refresh'] : '';
$refreshMessage = is_string($_GET['refresh_message'] ?? null) ? (string)$_GET['refresh_message'] : '';
$refreshAt = is_string($_GET['refresh_at'] ?? null) ? (string)$_GET['refresh_at'] : '';
$refreshPid = is_string($_GET['refresh_pid'] ?? null) ? (string)$_GET['refresh_pid'] : '';
$refreshReturnTo = (string)($_SERVER['REQUEST_URI'] ?? '/offers.php');
$refreshMeta = allegro_read_dashboard_refresh_state();

if ($configured && $status['authorized']) {
    try {
        $token = $client->token();
        $status = allegro_safe_token_status($token);
        $offersPage = $client->listOffersPage($page, 25, $sort, $titleQuery, $skuQuery, $statusFilter);
        $sort = $offersPage['sort'];
        $titleQuery = (string)($offersPage['filters']['title'] ?? $titleQuery);
        $skuQuery = (string)($offersPage['filters']['sku'] ?? $skuQuery);
        $statusFilter = (string)($offersPage['filters']['status'] ?? $statusFilter);
    } catch (Throwable) {
        $offersPage = null;
    }
}

function build_offers_url(int $page, string $sort, string $title = '', string $sku = '', string $status = ''): string {
    $params = ['page' => $page, 'sort' => $sort];
    if ($title !== '') $params['title'] = $title;
    if ($sku !== '') $params['sku'] = $sku;
    if ($status !== '') $params['status'] = $status;
    return '/offers.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Allegro Manager — Offers</title>
  <meta name="description" content="Allegro Manager — paginated offers list for Allegro marketplace.">
  <link rel="icon" href="assets/allegro-manager-logo.svg" type="image/svg+xml">
  <style>
    :root {
      --ink:#111827; --muted:#667085; --line:#e5e7eb; --surface:#fff; --soft:#fff7ed;
      --amber:#ff6b00; --amber-dark:#c2410c; --green:#15803d; --red:#b91c1c; --bg:#f8fafc;
      --page-pad: clamp(16px, 3vw, 32px);
      --gap: clamp(16px, 2.4vw, 22px);
      --card-pad: clamp(20px, 4vw, 42px);
      --radius: clamp(22px, 4vw, 28px);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--ink);
      background: radial-gradient(circle at 12% 12%, rgba(255,107,0,.13), transparent 30rem),
                  linear-gradient(135deg, #fff 0%, var(--bg) 55%, #fff7ed 100%);
      padding: var(--page-pad);
    }
    main { width: min(1280px, 100%); margin: 0 auto; display: grid; gap: var(--gap); }
    .card {
      background: rgba(255,255,255,.92); border: 1px solid rgba(229,231,235,.95);
      border-radius: var(--radius); box-shadow: 0 24px 80px rgba(17,24,39,.08);
      padding: var(--card-pad);
    }
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
    .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 26px; }
    .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 12px 16px; border-radius: 14px; border: 1px solid transparent; background: var(--ink); color: #fff; text-decoration: none; font-weight: 800; }
    .btn[disabled] { opacity: .5; cursor: not-allowed; }
    .btn.primary { background: var(--amber); }
    .btn.secondary { background: #fff; color: var(--ink); border-color: var(--line); }
    .notice { border: 1px solid #fed7aa; background: #fff7ed; color: #9a3412; border-radius: 18px; padding: clamp(16px, 3vw, 20px); }
    .notice p { color: #9a3412; }
    .success { border-color: #bbf7d0; background: #ecfdf3; color: #166534; }
    .success p { color: #166534; }
    .error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: #111827; color: #f9fafb; border-radius: 18px; padding: 16px; overflow: auto; font-size: 13px; line-height: 1.6; margin-top: 14px; }
    .helper { font-size: 14px; margin-top: 12px; }
    .hero-meta { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    .pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 800; background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
    .toolbar { display: flex; flex-wrap: wrap; align-items: end; justify-content: space-between; gap: 16px; margin-top: 8px; }
    .toolbar form { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
    .search-grid { display: grid; grid-template-columns: minmax(240px, 1.5fr) minmax(180px, 1fr) minmax(180px, 1fr) minmax(220px, 1fr); gap: 12px; width: 100%; }
    .field-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .field { display: grid; gap: 6px; min-width: 240px; }
    .field label { color: var(--muted); font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
    .field input,
    .field select {
      min-height: 44px; border-radius: 14px; border: 1px solid var(--line); background: #fff; padding: 10px 14px;
      font: inherit; color: var(--ink);
    }
    .table-wrap { overflow: auto; margin-top: 18px; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 980px; }
    .data-table th, .data-table td { padding: 14px 10px; border-top: 1px solid var(--line); text-align: left; vertical-align: top; }
    .data-table th { color: var(--muted); font-size: 12px; letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; }
    .data-table td.num, .data-table th.num { text-align: right; white-space: nowrap; }
    .offer-cell { display: grid; grid-template-columns: 72px minmax(240px, 1fr); gap: 14px; align-items: start; }
    .thumb { width: 72px; height: 72px; border-radius: 16px; object-fit: cover; background: #f3f4f6; border: 1px solid #e5e7eb; }
    .offer-title { margin: 0; font-weight: 800; color: var(--ink); }
    .offer-title a { color: inherit; text-decoration: none; }
    .offer-title a:hover { text-decoration: underline; }
    .offer-meta { display: grid; gap: 4px; margin-top: 6px; color: var(--muted); font-size: 13px; }
    .offer-meta a { color: var(--amber-dark); font-weight: 700; text-decoration: none; }
    .offer-meta a:hover { text-decoration: underline; }
    .muted { color: var(--muted); }
    .status-chip { display: inline-flex; align-items: center; justify-content: center; padding: 6px 10px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; font-size: 12px; font-weight: 800; }
    .status-active { background: #ecfdf3; border-color: #bbf7d0; color: #166534; }
    .status-inactive, .status-ended { background: #f9fafb; color: #475467; }
    .status-activating { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
    .pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin-top: 18px; }
    .page-links { display: flex; flex-wrap: wrap; gap: 8px; }
    .page-link {
      display: inline-flex; align-items: center; justify-content: center; min-width: 42px; min-height: 42px; padding: 0 14px;
      border-radius: 12px; border: 1px solid var(--line); background: #fff; color: var(--ink); text-decoration: none; font-weight: 800;
    }
    .page-link.active { background: linear-gradient(180deg, #ff8a1f, #ff6b00); border-color: #ff6b00; color: #fff; }
    .page-link.disabled { pointer-events: none; opacity: .45; }
    @media (max-width: 850px) {
      :root { --page-pad: 12px; --gap: 12px; --card-pad: 18px; --radius: 22px; }
      .topbar { align-items: center; gap: 10px; margin-bottom: 18px; }
      .nav-shell { margin-bottom: 16px; }
      .tabs { gap: 8px; }
      .tab-link { min-height: 40px; padding: 10px 14px; }
      .logo { width: min(220px, 58vw); }
      .actions { display: grid; grid-template-columns: 1fr; gap: 10px; }
      .btn { width: 100%; }
      .toolbar { align-items: stretch; }
      .toolbar form { width: 100%; }
      .search-grid { grid-template-columns: 1fr; }
      .field { min-width: 100%; }
    }
    @media (max-width: 560px) {
      .offer-cell { grid-template-columns: 1fr; }
      .thumb { width: 96px; height: 96px; }
    }
    @media (max-width: 440px) {
      :root { --page-pad: 8px; --gap: 10px; --card-pad: 14px; --radius: 20px; }
      .topbar { flex-direction: row; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 16px; }
      .nav-shell { margin-bottom: 14px; }
      .tabs { gap: 6px; }
      .tab-link { min-height: 38px; padding: 9px 13px; font-size: 14px; }
      .logo { width: min(190px, 56vw); }
      .header-actions .btn { padding: 10px 12px; font-size: 12px; }
      h1 { font-size: clamp(32px, 10vw, 42px); }
      .pagination { align-items: flex-start; }
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
    <p class="eyebrow">Seller operations workspace</p>
    <h1>Offers</h1>
    <p>Browse all Allegro offers with pagination. By default, this page uses Allegro's newest-by-creation order. You can switch to other supported sorts at any time.</p>
    <div class="hero-meta">
      <?php if ($offersPage): ?>
        <span class="pill">Total offers: <?= h((string)$offersPage['pagination']['total_count']) ?></span>
        <span class="pill">Showing: <?= h((string)$offersPage['pagination']['from']) ?>–<?= h((string)$offersPage['pagination']['to']) ?></span>
        <span class="pill">Sort: <?= h($offersPage['sort_options'][$offersPage['sort']]['label'] ?? $offersPage['sort']) ?></span>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($refreshState === 'started'): ?>
    <section class="card notice"><strong>Refresh started.</strong> Dashboard data is updating in the background<?php if ($refreshPid !== ''): ?> (PID <?= h($refreshPid) ?>)<?php endif; ?>. Reload in a few minutes to see the updated cache-backed dashboard numbers.</section>
  <?php elseif ($refreshState === 'ok'): ?>
    <section class="card notice success"><strong>Dashboard data refreshed.</strong><?php if ($refreshAt !== ''): ?> Generated at <?= h(fmt_iso_time($refreshAt)) ?>.<?php endif; ?></section>
  <?php elseif ($refreshState === 'error'): ?>
    <section class="card notice error"><strong>Refresh failed:</strong> <?= h($refreshMessage !== '' ? $refreshMessage : 'Unknown error') ?></section>
  <?php elseif (($refreshMeta['status'] ?? '') === 'running'): ?>
    <section class="card notice"><strong>Refresh in progress.</strong><?php if (!empty($refreshMeta['started_at'])): ?> Started at <?= h(fmt_iso_time((string)$refreshMeta['started_at'])) ?>.<?php endif; ?></section>
  <?php endif; ?>

  <?php if ($offersPage): ?>
    <?php
      $pagination = $offersPage['pagination'];
      $windowStart = max(1, $pagination['page'] - 2);
      $windowEnd = min($pagination['total_pages'], $pagination['page'] + 2);
    ?>
    <section class="card">
      <div class="toolbar">
        <div>
          <p class="eyebrow">Offer catalog</p>
          <h2>Paginated offers list</h2>
          <p class="helper">Default order is Allegro's offer creation date descending. Search by title or exact SKU, filter by status, and switch sort modes using the official <code>/sale/offers</code> options.</p>
        </div>
        <form method="get" action="/offers.php">
          <div class="search-grid">
            <div class="field">
              <label for="title">Title search</label>
              <input id="title" name="title" type="text" value="<?= h($titleQuery) ?>" placeholder="Search offer title">
            </div>
            <div class="field">
              <label for="sku">SKU search</label>
              <input id="sku" name="sku" type="text" value="<?= h($skuQuery) ?>" placeholder="Exact external SKU">
            </div>
            <div class="field">
              <label for="status">Status</label>
              <select id="status" name="status">
                <?php foreach (($offersPage['filters']['status_options'] ?? []) as $statusKey => $statusLabel): ?>
                  <option value="<?= h($statusKey) ?>"<?= $statusFilter === $statusKey ? ' selected' : '' ?>><?= h($statusLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="sort">Sort offers</label>
              <select id="sort" name="sort">
                <?php foreach ($offersPage['sort_options'] as $sortKey => $sortMeta): ?>
                  <option value="<?= h($sortKey) ?>"<?= $sort === $sortKey ? ' selected' : '' ?>><?= h($sortMeta['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field-actions">
            <input type="hidden" name="page" value="1">
            <button class="btn secondary" type="submit">Apply filters</button>
            <?php if ($titleQuery !== '' || $skuQuery !== '' || $statusFilter !== '' || $sort !== 'created_desc'): ?>
              <a class="btn secondary" href="/offers.php">Reset</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Offer</th>
              <th>Status</th>
              <th>Published</th>
              <th class="num">Price</th>
              <th class="num">Sold</th>
              <th class="num">Stock</th>
              <th class="num">Visits</th>
              <th class="num">Watchers</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($offersPage['items'] as $offer): ?>
              <?php
                $statusClass = match ($offer['status']) {
                    'ACTIVE' => 'status-active',
                    'ACTIVATING' => 'status-activating',
                    'INACTIVE', 'ENDED' => 'status-inactive',
                    default => '',
                };
                $publishedAt = $offer['started_at'] ?? $offer['starting_at'] ?? $offer['ended_at'];
              ?>
              <tr>
                <td>
                  <div class="offer-cell">
                    <?php if ($offer['image_url'] !== ''): ?>
                      <img class="thumb" src="<?= h($offer['image_url']) ?>" alt="<?= h($offer['name']) ?>">
                    <?php else: ?>
                      <div class="thumb" aria-hidden="true"></div>
                    <?php endif; ?>
                    <div>
                      <p class="offer-title"><a href="/offer.php?id=<?= h(rawurlencode($offer['id'])) ?>"><?= h($offer['name']) ?></a></p>
                      <div class="offer-meta">
                        <span>Offer ID: <?= h($offer['id']) ?></span>
                        <span>SKU: <?= h($offer['sku']) ?></span>
                        <span>Category: <?= h($offer['category_id']) ?> · Format: <?= h($offer['format']) ?></span>
                        <?php if ($offer['allegro_url'] !== ''): ?>
                          <span><a href="<?= h($offer['allegro_url']) ?>" target="_blank" rel="noopener noreferrer">Open on Allegro ↗</a></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td><span class="status-chip <?= h($statusClass) ?>"><?= h($offer['status']) ?></span></td>
                <td><?= h(fmt_iso_time($publishedAt)) ?></td>
                <td class="num"><?= h(fmt_money($offer['price'], $offer['currency'])) ?></td>
                <td class="num"><?= h((string)$offer['sold']) ?></td>
                <td class="num"><?= h((string)$offer['available']) ?></td>
                <td class="num"><?= h((string)$offer['visits']) ?></td>
                <td class="num"><?= h((string)$offer['watchers']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <div class="muted">
          Showing <strong><?= h((string)$pagination['from']) ?></strong>–<strong><?= h((string)$pagination['to']) ?></strong>
          of <strong><?= h((string)$pagination['total_count']) ?></strong> offers.
        </div>
        <div class="page-links">
          <a class="page-link<?= $pagination['has_prev'] ? '' : ' disabled' ?>" href="<?= h(build_offers_url($pagination['prev_page'], $sort, $titleQuery, $skuQuery, $statusFilter)) ?>">← Prev</a>
          <?php if ($windowStart > 1): ?>
            <a class="page-link" href="<?= h(build_offers_url(1, $sort, $titleQuery, $skuQuery, $statusFilter)) ?>">1</a>
            <?php if ($windowStart > 2): ?><span class="page-link disabled">…</span><?php endif; ?>
          <?php endif; ?>
          <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
            <a class="page-link<?= $p === $pagination['page'] ? ' active' : '' ?>" href="<?= h(build_offers_url($p, $sort, $titleQuery, $skuQuery, $statusFilter)) ?>"><?= h((string)$p) ?></a>
          <?php endfor; ?>
          <?php if ($windowEnd < $pagination['total_pages']): ?>
            <?php if ($windowEnd < $pagination['total_pages'] - 1): ?><span class="page-link disabled">…</span><?php endif; ?>
            <a class="page-link" href="<?= h(build_offers_url($pagination['total_pages'], $sort, $titleQuery, $skuQuery, $statusFilter)) ?>"><?= h((string)$pagination['total_pages']) ?></a>
          <?php endif; ?>
          <a class="page-link<?= $pagination['has_next'] ? '' : ' disabled' ?>" href="<?= h(build_offers_url($pagination['next_page'], $sort, $titleQuery, $skuQuery, $statusFilter)) ?>">Next →</a>
        </div>
      </div>
      <?php if (!empty($offersPage['trace_id'])): ?>
        <p class="helper">Trace-Id: <code><?= h((string)$offersPage['trace_id']) ?></code></p>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section class="card">
      <p class="eyebrow">Offer catalog</p>
      <h2>Offers unavailable right now</h2>
      <p class="helper">Open <a href="/settings.php">Settings</a> to inspect Allegro authorization, API status, and dashboard refresh health.</p>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
