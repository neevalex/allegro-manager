<?php
declare(strict_types=1);
require_once '/var/www/allegro-manager/app/AllegroClient.php';

$config = AllegroConfig::load();
$configured = AllegroConfig::isConfigured($config);
$client = new AllegroClient($config);
$token = $client->token();
$status = allegro_safe_token_status($token);
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/offers.php', PHP_URL_PATH) ?: '/offers.php';
$navTabs = [
    ['label' => 'Dashboard', 'href' => '/', 'match' => ['/']],
    ['label' => 'Offers', 'href' => '/offers.php', 'match' => ['/offers.php', '/offer.php']],
    ['label' => 'WooCommerce', 'href' => '/woocommerce.php', 'match' => ['/woocommerce.php', '/woocommerce-product.php', '/woocommerce-to-allegro.php']],
    ['label' => 'Settings', 'href' => '/settings.php', 'match' => ['/settings.php']],
];

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

function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fmt_time(?int $ts): string { return $ts ? date('Y-m-d H:i:s T', $ts) : '—'; }
function fmt_duration(?int $seconds): string {
    if ($seconds === null) return '—';
    $d = intdiv($seconds, 86400); $seconds %= 86400;
    $h = intdiv($seconds, 3600); $seconds %= 3600;
    $m = intdiv($seconds, 60);
    return ($d ? $d . 'd ' : '') . ($h ? $h . 'h ' : '') . $m . 'm';
}
function fmt_money($amount, ?string $currency): string {
    if ($amount === null || !is_numeric($amount)) return '—';
    return number_format((float)$amount, 2, '.', ' ') . ' ' . ($currency ?: 'PLN');
}
function fmt_iso_time(?string $value): string {
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s T');
    } catch (Throwable) {
        return $value;
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
