<?php
declare(strict_types=1);
$bootstrap = __DIR__ . '/bootstrap.php';
if (!file_exists($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/public/bootstrap.php';
}
require_once $bootstrap;

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$dashboard = null;
$dashboardMeta = null;
$error = null;
$refreshState = is_string($_GET['refresh'] ?? null) ? (string)$_GET['refresh'] : '';
$refreshMessage = is_string($_GET['refresh_message'] ?? null) ? (string)$_GET['refresh_message'] : '';
$refreshAt = is_string($_GET['refresh_at'] ?? null) ? (string)$_GET['refresh_at'] : '';
$refreshPid = is_string($_GET['refresh_pid'] ?? null) ? (string)$_GET['refresh_pid'] : '';
$refreshReturnTo = (string)($_SERVER['REQUEST_URI'] ?? '/');
$refreshMeta = allegro_read_dashboard_refresh_state();

if ($configured && $status['authorized']) {
    try {
        $token = $client->token();
        $status = allegro_safe_token_status($token);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function load_dashboard_cache(string $path): array {
    if (!is_file($path)) {
        return [null, ['error' => 'Dashboard cache not generated yet.', 'generated_at' => null, 'ok' => false]];
    }
    $raw = file_get_contents($path);
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        return [null, ['error' => 'Dashboard cache is unreadable.', 'generated_at' => null, 'ok' => false]];
    }
    return [$payload['data'] ?? null, [
        'error' => $payload['error'] ?? null,
        'generated_at' => $payload['generated_at'] ?? null,
        'ok' => (bool)($payload['ok'] ?? false),
    ]];
}
[$dashboard, $dashboardMeta] = load_dashboard_cache(data_root() . '/dashboard-summary.json');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Allegro Manager</title>
  <meta name="description" content="Allegro Manager — seller operations app for Allegro marketplace.">
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
    <p class="eyebrow">Sales dashboard</p>
    <h1>Allegro Manager</h1>
    <p>Sales, balance, shipment, and offer performance live here. Allegro authorization, token controls, and API connection details now live in <a href="/settings.php">Settings</a>.</p>
  </section>

  <?php if ($refreshState === 'started'): ?>
    <section class="card notice"><strong>Refresh started.</strong> Dashboard data is updating in the background<?php if ($refreshPid !== ''): ?> (PID <?= h($refreshPid) ?>)<?php endif; ?>. Reload this page in a few minutes to see the new cache.</section>
  <?php elseif ($refreshState === 'ok'): ?>
    <section class="card notice success"><strong>Dashboard data refreshed.</strong><?php if ($refreshAt !== ''): ?> Generated at <?= h(fmt_iso_time($refreshAt)) ?>.<?php endif; ?></section>
  <?php elseif ($refreshState === 'error'): ?>
    <section class="card notice error"><strong>Refresh failed:</strong> <?= h($refreshMessage !== '' ? $refreshMessage : 'Unknown error') ?></section>
  <?php elseif (($refreshMeta['status'] ?? '') === 'running'): ?>
    <section class="card notice"><strong>Refresh in progress.</strong><?php if (!empty($refreshMeta['started_at'])): ?> Started at <?= h(fmt_iso_time((string)$refreshMeta['started_at'])) ?>.<?php endif; ?></section>
  <?php endif; ?>

  <?php if ($dashboard || !empty($dashboardMeta['error'])): ?>
    <section class="card">
      <p class="eyebrow">Sales dashboard</p>
      <h2>Balance and sales summary</h2>
      <p class="helper">Last refresh: <?= h(fmt_iso_time($dashboardMeta['generated_at'] ?? null)) ?></p>
      <?php if (!$dashboard): ?>
        <p class="helper">Dashboard data is not available right now. Check <a href="/settings.php">Settings</a> for cache and connection details.</p>
      <?php else: ?>
      <?php
        $trend30 = is_array($dashboard['daily_trend_30'] ?? null) ? $dashboard['daily_trend_30'] : [];
        $trend7 = is_array($dashboard['daily_trend_7'] ?? null) ? $dashboard['daily_trend_7'] : [];
        $trend30Max = 0.0;
        foreach ($trend30 as $point) { $trend30Max = max($trend30Max, (float)($point['sales'] ?? 0)); }
        $trend7Max = 0.0;
        foreach ($trend7 as $point) { $trend7Max = max($trend7Max, (float)($point['sales'] ?? 0)); }
        $topRecentSkus = is_array($dashboard['top_recent_skus'] ?? null) ? $dashboard['top_recent_skus'] : [];
        $fallbackOffers = is_array($dashboard['active_offer_highlights'] ?? null) ? $dashboard['active_offer_highlights'] : [];
        $awaitingShipment = is_array($dashboard['awaiting_shipment'] ?? null) ? $dashboard['awaiting_shipment'] : [];
      ?>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">Balance</div>
          <div class="stat-value"><?= h(fmt_money($dashboard['balance'] ?? null, $dashboard['balance_currency'] ?? ($dashboard['currency'] ?? 'PLN'))) ?></div>
          <div class="stat-sub">Updated: <?= h(fmt_iso_time($dashboard['balance_updated_at'] ?? null)) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Sales today</div>
          <div class="stat-value"><?= h(fmt_money($dashboard['sales_today'] ?? null, $dashboard['currency'] ?? 'PLN')) ?></div>
          <div class="stat-sub">Orders: <?= h((string)($dashboard['orders_today'] ?? 0)) ?> · Items: <?= h((string)($dashboard['items_today'] ?? 0)) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Sales last 7 days</div>
          <div class="stat-value"><?= h(fmt_money($dashboard['sales_7d'] ?? null, $dashboard['currency'] ?? 'PLN')) ?></div>
          <div class="stat-sub">Orders: <?= h((string)($dashboard['orders_7d'] ?? 0)) ?> · Items: <?= h((string)($dashboard['items_7d'] ?? 0)) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Sales last 30 days</div>
          <div class="stat-value"><?= h(fmt_money($dashboard['sales_30d'] ?? null, $dashboard['currency'] ?? 'PLN')) ?></div>
          <div class="stat-sub">Orders: <?= h((string)($dashboard['orders_30d'] ?? 0)) ?> · Items: <?= h((string)($dashboard['items_30d'] ?? 0)) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Sales this month</div>
          <div class="stat-value"><?= h(fmt_money($dashboard['sales_month'] ?? null, $dashboard['currency'] ?? 'PLN')) ?></div>
          <div class="stat-sub">Orders: <?= h((string)($dashboard['orders_month'] ?? 0)) ?> · Items: <?= h((string)($dashboard['items_month'] ?? 0)) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Awaiting shipment</div>
          <div class="stat-value"><?= h((string)($dashboard['awaiting_shipment_count'] ?? 0)) ?></div>
          <div class="stat-sub">Pending dashboard count: <?= h((string)($dashboard['pending_orders'] ?? 0)) ?></div>
        </div>
      </div>
      <div class="summary-grid" style="margin-top: var(--gap);">
        <div class="card" style="padding: 22px; box-shadow: none;">
          <h2>Cashflow</h2>
          <div class="summary-list meta">
            <div class="row"><span>Income today</span><span><?= h(fmt_money($dashboard['income_today'] ?? null, $dashboard['currency'] ?? 'PLN')) ?></span></div>
            <div class="row"><span>Expenses today</span><span><?= h(fmt_money($dashboard['expenses_today'] ?? null, $dashboard['currency'] ?? 'PLN')) ?></span></div>
            <div class="row"><span>Income this month</span><span><?= h(fmt_money($dashboard['income_month'] ?? null, $dashboard['currency'] ?? 'PLN')) ?></span></div>
            <div class="row"><span>Expenses this month</span><span><?= h(fmt_money($dashboard['expenses_month'] ?? null, $dashboard['currency'] ?? 'PLN')) ?></span></div>
          </div>
        </div>
        <div class="card" style="padding: 22px; box-shadow: none;">
          <h2>Data window</h2>
          <div class="summary-list meta">
            <div class="row"><span>Day started</span><span><?= h(fmt_iso_time($dashboard['today_start'] ?? null)) ?></span></div>
            <div class="row"><span>7-day window</span><span><?= h(fmt_iso_time($dashboard['days7_start'] ?? null)) ?></span></div>
            <div class="row"><span>30-day window</span><span><?= h(fmt_iso_time($dashboard['days30_start'] ?? null)) ?></span></div>
            <div class="row"><span>Month started</span><span><?= h(fmt_iso_time($dashboard['month_start'] ?? null)) ?></span></div>
            <div class="row"><span>Payment ops / orders / offers</span><span><?= h((string)($dashboard['payments_loaded'] ?? 0)) ?> / <?= h((string)($dashboard['orders_loaded'] ?? 0)) ?> / <?= h((string)($dashboard['offers_loaded'] ?? 0)) ?></span></div>
          </div>
        </div>
      </div>
      <div class="chart-grid">
        <div class="card" style="padding: 22px; box-shadow: none;">
          <h2>Daily sales trend · 7 days</h2>
          <div class="mini-chart">
            <div class="bars">
              <?php foreach ($trend7 as $point): $height = $trend7Max > 0 ? max(4, (int)round(((float)$point['sales'] / $trend7Max) * 120)) : 4; ?>
                <div class="bar-wrap" title="<?= h(($point['label'] ?? '—') . ': ' . fmt_money($point['sales'] ?? 0, $dashboard['currency'] ?? 'PLN')) ?>">
                  <div class="bar alt" style="height: <?= $height ?>px"></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="bar-labels">
              <span><?= h($trend7[0]['label'] ?? '—') ?></span>
              <span><?= h(end($trend7)['label'] ?? '—') ?></span>
            </div>
            <div class="legend"><span><span class="swatch swatch-alt"></span>Gross sales per day</span></div>
          </div>
        </div>
        <div class="card" style="padding: 22px; box-shadow: none;">
          <h2>Daily sales trend · 30 days</h2>
          <div class="mini-chart">
            <div class="bars">
              <?php foreach ($trend30 as $point): $height = $trend30Max > 0 ? max(4, (int)round(((float)$point['sales'] / $trend30Max) * 120)) : 4; ?>
                <div class="bar-wrap" title="<?= h(($point['label'] ?? '—') . ': ' . fmt_money($point['sales'] ?? 0, $dashboard['currency'] ?? 'PLN')) ?>">
                  <div class="bar" style="height: <?= $height ?>px"></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="bar-labels">
              <span><?= h($trend30[0]['label'] ?? '—') ?></span>
              <span><?= h(end($trend30)['label'] ?? '—') ?></span>
            </div>
            <div class="legend"><span><span class="swatch swatch-main"></span>Gross sales per day</span></div>
          </div>
        </div>
      </div>
      <div class="panel-grid">
        <div class="card" style="padding: 22px; box-shadow: none;">
          <h2><?= count($topRecentSkus) ? 'Best recent offers / SKUs' : 'Active offer highlights' ?></h2>
          <?php if (count($topRecentSkus)): ?>
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr><th>Offer</th><th>SKU</th><th class="num">Qty</th><th class="num">Orders</th><th class="num">Revenue</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($topRecentSkus as $sku): ?>
                    <tr>
                      <td><strong><?= h($sku['name'] ?? '—') ?></strong><br><span class="muted">Offer ID: <?= h($sku['offer_id'] ?? '—') ?></span></td>
                      <td><?= h($sku['sku'] ?? '—') ?></td>
                      <td class="num"><?= h((string)($sku['qty'] ?? 0)) ?></td>
                      <td class="num"><?= h((string)($sku['orders'] ?? 0)) ?></td>
                      <td class="num"><?= h(fmt_money($sku['revenue'] ?? 0, $dashboard['currency'] ?? 'PLN')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr><th>Offer</th><th>SKU</th><th class="num">Visits</th><th class="num">Watchers</th><th class="num">Price</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($fallbackOffers as $offer): ?>
                    <tr>
                      <td><strong><?= h($offer['name'] ?? '—') ?></strong><br><span class="muted">Offer ID: <?= h($offer['offer_id'] ?? '—') ?></span></td>
                      <td><?= h($offer['sku'] ?? '—') ?></td>
                      <td class="num"><?= h((string)($offer['visits'] ?? 0)) ?></td>
                      <td class="num"><?= h((string)($offer['watchers'] ?? 0)) ?></td>
                      <td class="num"><?= h(fmt_money($offer['price'] ?? 0, $offer['currency'] ?? ($dashboard['currency'] ?? 'PLN'))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <div class="card" style="padding: 22px; box-shadow: none;">
          <h2>Orders awaiting shipment</h2>
          <p class="helper"><span class="pill">Count: <?= h((string)($dashboard['awaiting_shipment_count'] ?? 0)) ?></span></p>
          <?php if (count($awaitingShipment)): ?>
            <div class="stack">
              <?php foreach ($awaitingShipment as $shipment): ?>
                <div class="shipment-item">
                  <strong><?= h($shipment['buyer'] ?? 'Buyer') ?></strong>
                  <div class="muted">Order: <?= h($shipment['id'] ?? '—') ?></div>
                  <div class="muted">Method: <?= h($shipment['delivery_method'] ?? '—') ?></div>
                  <div class="muted">Status: <?= h($shipment['status'] ?? '—') ?> · Fulfillment: <?= h($shipment['fulfillment_status'] ?? '—') ?></div>
                  <div class="muted">Updated: <?= h(fmt_iso_time($shipment['updated_at'] ?? null)) ?> · Amount: <?= h(fmt_money($shipment['amount'] ?? 0, $shipment['currency'] ?? ($dashboard['currency'] ?? 'PLN'))) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p>No orders currently match the awaiting-shipment rule.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
