<?php
declare(strict_types=1);
require_once '/var/www/allegro-manager/app/AllegroClient.php';

$config = AllegroConfig::load();
$configured = AllegroConfig::isConfigured($config);
$client = new AllegroClient($config);
$token = $client->token();
$status = allegro_safe_token_status($token);
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$navTabs = [
    ['label' => 'Dashboard', 'href' => '/', 'match' => ['/']],
    ['label' => 'Offers', 'href' => '/offers.php', 'match' => ['/offers.php', '/offer.php']],
    ['label' => 'WooCommerce', 'href' => '/woocommerce.php', 'match' => ['/woocommerce.php', '/woocommerce-product.php', '/woocommerce-to-allegro.php']],
    ['label' => 'Settings', 'href' => '/settings.php', 'match' => ['/settings.php']],
];
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
[$dashboard, $dashboardMeta] = load_dashboard_cache('/var/www/allegro-manager/data/dashboard-summary.json');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Allegro Manager</title>
  <meta name="description" content="Allegro Manager — seller operations app for Allegro marketplace.">
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
    main { width: min(1120px, 100%); margin: 0 auto; display: grid; gap: var(--gap); }
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
    h1 { margin: 0; font-size: clamp(36px, 8vw, 64px); line-height: .96; letter-spacing: -.055em; }
    h2 { margin: 0 0 16px; font-size: clamp(21px, 4vw, 24px); letter-spacing: -.02em; }
    p { color: var(--muted); line-height: 1.65; margin: 16px 0 0; }
    code { background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 7px; padding: 1px 5px; }
    .grid { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr); gap: var(--gap); align-items: stretch; }
    .status { display: flex; align-items: center; gap: 12px; font-weight: 900; font-size: clamp(18px, 4vw, 20px); }
    .dot { flex: 0 0 auto; width: 14px; height: 14px; border-radius: 50%; background: var(--red); box-shadow: 0 0 0 7px rgba(185,28,28,.1); }
    .ok .dot { background: var(--green); box-shadow: 0 0 0 7px rgba(21,128,61,.12); }
    .warn .dot { background: #d97706; box-shadow: 0 0 0 7px rgba(217,119,6,.14); }
    .meta { display: grid; gap: 0; margin-top: 22px; }
    .row { display: flex; justify-content: space-between; gap: 18px; padding: 12px 0; border-top: 1px solid var(--line); }
    .row span:first-child { color: var(--muted); }
    .row span:last-child { text-align: right; font-weight: 750; overflow-wrap: anywhere; }
    .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 26px; }
    .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 12px 16px; border-radius: 14px; border: 1px solid transparent; background: var(--ink); color: #fff; text-decoration: none; font-weight: 800; }
    .btn[disabled] { opacity: .5; cursor: not-allowed; }
    .btn.primary { background: var(--amber); }
    .btn.secondary { background: #fff; color: var(--ink); border-color: var(--line); }
    .btn.danger { background: #fff; color: var(--red); border-color: #fecaca; }
    .notice { border: 1px solid #fed7aa; background: #fff7ed; color: #9a3412; border-radius: 18px; padding: clamp(16px, 3vw, 20px); }
    .notice p { color: #9a3412; }
    .success { border-color: #bbf7d0; background: #ecfdf3; color: #166534; }
    .success p { color: #166534; }
    .error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: #111827; color: #f9fafb; border-radius: 18px; padding: 16px; overflow: auto; font-size: 13px; line-height: 1.6; margin-top: 14px; }
    .modules { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    .chip { border: 1px solid #fed7aa; background: var(--soft); color: #9a3412; border-radius: 999px; padding: 8px 12px; font-size: 13px; font-weight: 800; }
    .helper { font-size: 14px; margin-top: 12px; }
    .stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--gap); }
    .stat-card { background: linear-gradient(180deg, #fff, #fffaf5); border: 1px solid #fde6d4; border-radius: 22px; padding: 18px; }
    .stat-label { color: var(--muted); font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
    .stat-value { margin-top: 10px; font-size: clamp(26px, 4vw, 36px); font-weight: 900; letter-spacing: -.04em; }
    .stat-sub { margin-top: 8px; color: var(--muted); font-size: 14px; line-height: 1.5; }
    .summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--gap); }
    .summary-list { display: grid; gap: 0; margin-top: 6px; }
    .summary-list .row:first-child { border-top: 0; }
    .panel-grid { display: grid; grid-template-columns: 1.25fr .95fr; gap: var(--gap); margin-top: var(--gap); }
    .table-wrap { overflow: auto; margin-top: 14px; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .data-table th, .data-table td { padding: 12px 10px; border-top: 1px solid var(--line); text-align: left; vertical-align: top; }
    .data-table th { color: var(--muted); font-size: 12px; letter-spacing: .08em; text-transform: uppercase; }
    .data-table td.num, .data-table th.num { text-align: right; white-space: nowrap; }
    .muted { color: var(--muted); }
    .chart-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--gap); margin-top: var(--gap); }
    .mini-chart { margin-top: 12px; }
    .bars { display: flex; align-items: end; gap: 6px; height: 140px; padding: 14px 0 6px; }
    .bar-wrap { flex: 1 1 0; min-width: 0; }
    .bar { width: 100%; border-radius: 10px 10px 4px 4px; background: linear-gradient(180deg, #fb923c, #ea580c); min-height: 4px; }
    .bar.alt { background: linear-gradient(180deg, #fdba74, #fb923c); }
    .bar-labels { display: flex; justify-content: space-between; gap: 8px; color: var(--muted); font-size: 12px; margin-top: 8px; }
    .legend { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; color: var(--muted); font-size: 13px; }
    .legend .swatch { display: inline-block; width: 10px; height: 10px; border-radius: 999px; margin-right: 6px; vertical-align: middle; }
    .swatch-main { background: #ea580c; }
    .swatch-alt { background: #fb923c; }
    .pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 800; background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
    .stack { display: grid; gap: 10px; }
    .shipment-item { border-top: 1px solid var(--line); padding-top: 12px; }
    .shipment-item:first-child { border-top: 0; padding-top: 0; }
    @media (max-width: 1100px) {
      .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .panel-grid, .chart-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 850px) {
      :root { --page-pad: 12px; --gap: 12px; --card-pad: 18px; --radius: 22px; }
      .grid { grid-template-columns: 1fr; }
      .summary-grid { grid-template-columns: 1fr; }
      .topbar { align-items: center; gap: 10px; margin-bottom: 18px; }
      .nav-shell { margin-bottom: 16px; }
      .tabs { gap: 8px; }
      .tab-link { min-height: 40px; padding: 10px 14px; }
      .logo { width: min(220px, 58vw); }
      .row { align-items: flex-start; }
      .actions { display: grid; grid-template-columns: 1fr; gap: 10px; }
      .btn { width: 100%; }
      .code { font-size: 12px; padding: 14px; border-radius: 16px; }
    }
    @media (max-width: 560px) {
      .stats-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 440px) {
      :root { --page-pad: 8px; --gap: 10px; --card-pad: 14px; --radius: 20px; }
      body { background-position: center top; }
      .topbar { flex-direction: row; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 16px; }
      .nav-shell { margin-bottom: 14px; }
      .tabs { gap: 6px; }
      .tab-link { width: auto; min-height: 38px; padding: 9px 13px; font-size: 14px; }
      .logo { width: min(190px, 56vw); }
      .header-actions .btn { padding: 10px 12px; font-size: 12px; }
      .eyebrow { margin-bottom: 8px; font-size: 12px; letter-spacing: .14em; }
      h1 { font-size: clamp(34px, 10vw, 44px); }
      p { margin-top: 14px; }
      .card { box-shadow: 0 18px 55px rgba(17,24,39,.07); }
      .row { flex-direction: column; gap: 4px; }
      .row span:last-child { text-align: left; }
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
