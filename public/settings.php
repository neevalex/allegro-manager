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
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/settings.php', PHP_URL_PATH) ?: '/settings.php';
$navTabs = [
    ['label' => 'Dashboard', 'href' => '/', 'match' => ['/']],
    ['label' => 'Offers', 'href' => '/offers.php', 'match' => ['/offers.php', '/offer.php']],
    ['label' => 'WooCommerce', 'href' => '/woocommerce.php', 'match' => ['/woocommerce.php', '/woocommerce-product.php', '/woocommerce-to-allegro.php']],
    ['label' => 'Settings', 'href' => '/settings.php', 'match' => ['/settings.php']],
];

$me = null;
$error = null;
$wooError = null;
$wooSuccess = null;
$wooProbe = null;
$pricingError = null;
$pricingSuccess = null;
$refreshState = is_string($_GET['refresh'] ?? null) ? (string)$_GET['refresh'] : '';
$refreshMessage = is_string($_GET['refresh_message'] ?? null) ? (string)$_GET['refresh_message'] : '';
$refreshAt = is_string($_GET['refresh_at'] ?? null) ? (string)$_GET['refresh_at'] : '';
$refreshPid = is_string($_GET['refresh_pid'] ?? null) ? (string)$_GET['refresh_pid'] : '';
$refreshReturnTo = (string)($_SERVER['REQUEST_URI'] ?? '/settings.php');
$refreshMeta = allegro_read_dashboard_refresh_state();
$dashboardCacheMeta = null;

function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fmt_time(?int $ts): string { return $ts ? date('Y-m-d H:i:s T', $ts) : '—'; }
function fmt_duration(?int $seconds): string {
    if ($seconds === null) return '—';
    $d = intdiv($seconds, 86400); $seconds %= 86400;
    $h = intdiv($seconds, 3600); $seconds %= 3600;
    $m = intdiv($seconds, 60);
    return ($d ? $d . 'd ' : '') . ($h ? $h . 'h ' : '') . $m . 'm';
}
function fmt_iso_time(?string $value): string {
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s T');
    } catch (Throwable) {
        return $value;
    }
}
function mask_secret(?string $value, int $prefix = 4, int $suffix = 4): string {
    $value = trim((string)$value);
    if ($value === '') return 'not saved';
    $len = strlen($value);
    if ($len <= ($prefix + $suffix)) return str_repeat('•', max(6, $len));
    return substr($value, 0, $prefix) . str_repeat('•', max(6, $len - $prefix - $suffix)) . substr($value, -$suffix);
}
function fmt_decimal(float $value, int $precision = 4): string {
    $formatted = number_format($value, $precision, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}
function fetch_pln_uah_exchange_rate(): array {
    $url = 'https://open.er-api.com/v6/latest/PLN';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: AllegroManager/0.1 (+https://allegro.neevalex.com/)',
        ],
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Could not fetch exchange rate: ' . $message);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Exchange-rate source returned HTTP ' . $status . '.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || ($decoded['result'] ?? '') !== 'success') {
        throw new RuntimeException('Exchange-rate source returned an invalid payload.');
    }
    $rate = (float)($decoded['rates']['UAH'] ?? 0);
    if ($rate <= 0) {
        throw new RuntimeException('UAH rate missing in exchange-rate payload.');
    }
    return [
        'rate' => $rate,
        'source' => 'open.er-api.com/v6/latest/PLN',
        'updated_at' => gmdate('c'),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['settings_action'] ?? '');
    if ($action === 'save_woocommerce' || $action === 'clear_woocommerce') {
        try {
            $runtime = AllegroConfig::runtimeSettings();
            if ($action === 'clear_woocommerce') {
                unset(
                    $runtime['woo_site_url'],
                    $runtime['woo_consumer_key'],
                    $runtime['woo_consumer_secret'],
                    $runtime['woo_namespace'],
                    $runtime['woo_timeout'],
                    $runtime['woo_verify_ssl']
                );
                AllegroConfig::saveRuntimeSettings($runtime);
                $wooSuccess = 'WooCommerce settings cleared.';
            } else {
                $siteUrl = rtrim(trim((string)($_POST['woo_site_url'] ?? '')), '/');
                $namespace = trim((string)($_POST['woo_namespace'] ?? 'wc/v3'), '/');
                $consumerKey = trim((string)($_POST['woo_consumer_key'] ?? ''));
                $consumerSecret = trim((string)($_POST['woo_consumer_secret'] ?? ''));
                $timeout = max(3, min(120, (int)($_POST['woo_timeout'] ?? 20)));
                $verifySsl = isset($_POST['woo_verify_ssl']);

                if ($siteUrl === '') {
                    throw new InvalidArgumentException('WooCommerce site URL is required.');
                }
                if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
                    throw new InvalidArgumentException('WooCommerce site URL must be a valid absolute URL.');
                }
                if ($namespace === '') {
                    $namespace = 'wc/v3';
                }

                $runtime['woo_site_url'] = $siteUrl;
                $runtime['woo_namespace'] = $namespace;
                $runtime['woo_timeout'] = $timeout;
                $runtime['woo_verify_ssl'] = $verifySsl;
                if ($consumerKey !== '') {
                    $runtime['woo_consumer_key'] = $consumerKey;
                } elseif (!isset($runtime['woo_consumer_key']) && !empty($config['woo_consumer_key'])) {
                    $runtime['woo_consumer_key'] = (string)$config['woo_consumer_key'];
                }
                if ($consumerSecret !== '') {
                    $runtime['woo_consumer_secret'] = $consumerSecret;
                } elseif (!isset($runtime['woo_consumer_secret']) && !empty($config['woo_consumer_secret'])) {
                    $runtime['woo_consumer_secret'] = (string)$config['woo_consumer_secret'];
                }

                AllegroConfig::saveRuntimeSettings($runtime);
                $wooSuccess = 'WooCommerce settings saved.';
            }

            $config = AllegroConfig::load();
            $configured = AllegroConfig::isConfigured($config);
            $wooConfigured = AllegroConfig::isWooConfigured($config);
            $client = new AllegroClient($config);
            $token = $client->token();
            $status = allegro_safe_token_status($token);
            $wooClient = new WooCommerceClient($config);
        } catch (Throwable $e) {
            $wooError = $e->getMessage();
        }
    } elseif ($action === 'save_pricing' || $action === 'refresh_exchange_rate') {
        try {
            $runtime = AllegroConfig::runtimeSettings();
            $exchangeRate = max(0, (float)($_POST['exchange_rate_pln_uah'] ?? ($config['exchange_rate_pln_uah'] ?? 0)));
            $nacenkaPercent = max(0, (float)($_POST['nacenka_percent'] ?? ($config['nacenka_percent'] ?? 50)));
            $deliveryCostPln = max(0, (float)($_POST['delivery_cost_pln'] ?? ($config['delivery_cost_pln'] ?? 0)));
            $source = trim((string)($config['exchange_rate_source'] ?? ''));
            $updatedAt = trim((string)($config['exchange_rate_updated_at'] ?? ''));

            if ($action === 'refresh_exchange_rate') {
                $ratePayload = fetch_pln_uah_exchange_rate();
                $exchangeRate = (float)$ratePayload['rate'];
                $source = (string)$ratePayload['source'];
                $updatedAt = (string)$ratePayload['updated_at'];
                $pricingSuccess = 'Exchange rate updated from the external source.';
            } else {
                $pricingSuccess = 'Pricing settings saved.';
            }

            $runtime['exchange_rate_pln_uah'] = $exchangeRate;
            $runtime['exchange_rate_source'] = $source;
            $runtime['exchange_rate_updated_at'] = $updatedAt;
            $runtime['nacenka_percent'] = $nacenkaPercent;
            $runtime['delivery_cost_pln'] = $deliveryCostPln;

            AllegroConfig::saveRuntimeSettings($runtime);

            $config = AllegroConfig::load();
            $configured = AllegroConfig::isConfigured($config);
            $wooConfigured = AllegroConfig::isWooConfigured($config);
            $client = new AllegroClient($config);
            $token = $client->token();
            $status = allegro_safe_token_status($token);
            $wooClient = new WooCommerceClient($config);
        } catch (Throwable $e) {
            $pricingError = $e->getMessage();
        }
    }
}

if ($configured && $status['authorized']) {
    try {
        $me = $client->me();
        $token = $client->token();
        $status = allegro_safe_token_status($token);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($wooConfigured) {
    try {
        $wooProbe = $wooClient->probe();
    } catch (Throwable $e) {
        $wooError = $wooError ?: $e->getMessage();
    }
}

$dashboardCachePath = '/var/www/allegro-manager/data/dashboard-summary.json';
if (is_file($dashboardCachePath)) {
    $raw = file_get_contents($dashboardCachePath);
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($payload)) {
        $dashboardCacheMeta = [
            'generated_at' => $payload['generated_at'] ?? null,
            'ok' => (bool)($payload['ok'] ?? false),
            'error' => $payload['error'] ?? null,
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Allegro Manager — Settings</title>
  <meta name="description" content="Allegro Manager — Allegro API, WooCommerce API, OAuth token, and dashboard refresh settings.">
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
      background: radial-gradient(circle at 12% 12%, rgba(255,107,0,.13), transparent 30rem), linear-gradient(135deg, #fff 0%, var(--bg) 55%, #fff7ed 100%);
      padding: var(--page-pad);
    }
    main { width: min(1320px, 100%); margin: 0 auto; display: grid; gap: var(--gap); }
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
    code { background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 7px; padding: 1px 5px; }
    .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--gap); align-items: stretch; }
    .status { display: flex; align-items: center; gap: 12px; font-weight: 900; font-size: clamp(18px, 4vw, 20px); }
    .dot { flex: 0 0 auto; width: 14px; height: 14px; border-radius: 50%; background: var(--red); box-shadow: 0 0 0 7px rgba(185,28,28,.1); }
    .ok .dot { background: var(--green); box-shadow: 0 0 0 7px rgba(21,128,61,.12); }
    .warn .dot { background: #d97706; box-shadow: 0 0 0 7px rgba(217,119,6,.14); }
    .meta { display: grid; gap: 0; margin-top: 22px; }
    .row { display: flex; justify-content: space-between; gap: 18px; padding: 12px 0; border-top: 1px solid var(--line); }
    .row span:first-child { color: var(--muted); }
    .row span:last-child { text-align: right; font-weight: 750; overflow-wrap: anywhere; }
    .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 26px; }
    .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 12px 16px; border-radius: 14px; border: 1px solid transparent; background: var(--ink); color: #fff; text-decoration: none; font-weight: 800; cursor: pointer; }
    .btn[disabled] { opacity: .5; cursor: not-allowed; }
    .btn.primary { background: var(--amber); }
    .btn.secondary { background: #fff; color: var(--ink); border-color: var(--line); }
    .btn.danger { background: #fff; color: var(--red); border-color: #fecaca; }
    .notice { border: 1px solid #fed7aa; background: #fff7ed; color: #9a3412; border-radius: 18px; padding: clamp(16px, 3vw, 20px); }
    .notice a { color: inherit; font-weight: 800; }
    .notice p { color: inherit; }
    .success { border-color: #bbf7d0; background: #ecfdf3; color: #166534; }
    .error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: #111827; color: #f9fafb; border-radius: 18px; padding: 16px; overflow: auto; font-size: 13px; line-height: 1.6; margin-top: 14px; }
    .modules { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    .chip { border: 1px solid #fed7aa; background: var(--soft); color: #9a3412; border-radius: 999px; padding: 8px 12px; font-size: 13px; font-weight: 800; }
    .helper { font-size: 14px; margin-top: 12px; }
    .field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 18px; }
    .field { display: grid; gap: 8px; }
    .field.full { grid-column: 1 / -1; }
    label { font-weight: 800; font-size: 14px; color: var(--ink); }
    input { width: 100%; min-height: 46px; border-radius: 14px; border: 1px solid var(--line); padding: 12px 14px; font: inherit; color: var(--ink); background: #fff; }
    .checkbox { display: flex; align-items: center; gap: 10px; min-height: 46px; }
    .checkbox input { width: 18px; min-height: 18px; }
    @media (max-width: 900px) {
      .grid, .field-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 850px) {
      :root { --page-pad: 12px; --gap: 12px; --card-pad: 18px; --radius: 22px; }
      .topbar { align-items: center; gap: 10px; margin-bottom: 18px; }
      .nav-shell { margin-bottom: 16px; }
      .tabs { gap: 8px; }
      .tab-link { min-height: 40px; padding: 10px 14px; }
      .logo { width: min(220px, 58vw); }
      .actions { display: grid; grid-template-columns: 1fr; gap: 10px; }
      .btn { width: 100%; }
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
    <p class="eyebrow">API + token management</p>
    <h1>Settings</h1>
    <p>All Allegro authorization, token, API connection, WooCommerce credentials, and dashboard-refresh management lives here.</p>
  </section>

  <?php if ($refreshState === 'started'): ?>
    <section class="card notice"><strong>Refresh started.</strong> Dashboard data is updating in the background<?php if ($refreshPid !== ''): ?> (PID <?= h($refreshPid) ?>)<?php endif; ?>. Reload this page in a few minutes to see the updated cache state.</section>
  <?php elseif ($refreshState === 'ok'): ?>
    <section class="card notice success"><strong>Dashboard data refreshed.</strong><?php if ($refreshAt !== ''): ?> Generated at <?= h(fmt_iso_time($refreshAt)) ?>.<?php endif; ?></section>
  <?php elseif ($refreshState === 'error'): ?>
    <section class="card notice error"><strong>Refresh failed:</strong> <?= h($refreshMessage !== '' ? $refreshMessage : 'Unknown error') ?></section>
  <?php elseif (($refreshMeta['status'] ?? '') === 'running'): ?>
    <section class="card notice"><strong>Refresh in progress.</strong><?php if (!empty($refreshMeta['started_at'])): ?> Started at <?= h(fmt_iso_time((string)$refreshMeta['started_at'])) ?>.<?php endif; ?></section>
  <?php endif; ?>

  <?php if ($error): ?>
    <section class="card notice error"><strong>Allegro error:</strong> <?= h($error) ?></section>
  <?php endif; ?>

  <?php if ($wooSuccess): ?>
    <section class="card notice success"><strong><?= h($wooSuccess) ?></strong><?php if ($wooProbe): ?> Connected to <code><?= h((string)$wooProbe['api_base']) ?></code>.<?php endif; ?></section>
  <?php endif; ?>

  <?php if ($wooError): ?>
    <section class="card notice error"><strong>WooCommerce error:</strong> <?= h($wooError) ?></section>
  <?php endif; ?>

  <?php if ($pricingSuccess): ?>
    <section class="card notice success"><strong><?= h($pricingSuccess) ?></strong><?php if (!empty($config['exchange_rate_pln_uah'])): ?> 1 PLN = <?= h(fmt_decimal((float)$config['exchange_rate_pln_uah'], 4)) ?> UAH.<?php endif; ?></section>
  <?php endif; ?>

  <?php if ($pricingError): ?>
    <section class="card notice error"><strong>Pricing settings error:</strong> <?= h($pricingError) ?></section>
  <?php endif; ?>

  <?php if (!$configured): ?>
    <section class="card notice">
      <h2>Configuration needed</h2>
      <p>Create the private config file below, then register this redirect URI in Allegro Developer Portal.</p>
      <p class="helper">Developer portal: <a href="https://developer.allegro.pl/" target="_blank" rel="noopener noreferrer">https://developer.allegro.pl/</a></p>
      <div class="code">cp /var/www/allegro-manager/config.example.php /var/www/allegro-manager/config.php<br>nano /var/www/allegro-manager/config.php<br><br>Redirect URI: <?= h(AllegroConfig::redirectUri($config)) ?></div>
    </section>
  <?php endif; ?>

  <section class="grid">
    <div class="card">
      <?php $statusClass = $status['authorized'] ? (($status['expires_in_seconds'] ?? 0) < 900 ? 'warn' : 'ok') : ''; ?>
      <div class="status <?= h($statusClass) ?>"><span class="dot"></span><?= $status['authorized'] ? 'Authorized with Allegro' : 'Not authorized yet' ?></div>
      <div class="meta">
        <div class="row"><span>Environment</span><span><?= h((string)$config['environment']) ?></span></div>
        <div class="row"><span>Token expires</span><span><?= fmt_time($status['expires_at'] ?? null) ?></span></div>
        <div class="row"><span>Time remaining</span><span><?= fmt_duration($status['expires_in_seconds'] ?? null) ?></span></div>
        <div class="row"><span>Refresh token saved</span><span><?= !empty($status['has_refresh_token']) ? 'yes' : 'no' ?></span></div>
        <div class="row"><span>Redirect URI</span><span><?= h(AllegroConfig::redirectUri($config)) ?></span></div>
      </div>
      <div class="actions">
        <a class="btn primary" href="auth.php?action=start">Open Allegro authorization</a>
        <?php if ($status['authorized']): ?>
          <a class="btn secondary" href="auth.php?action=refresh">Refresh token now</a>
          <a class="btn danger" href="auth.php?action=logout">Forget token</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2>Account probe</h2>
      <?php if ($me): ?>
        <div class="meta">
          <div class="row"><span>User ID</span><span><?= h($me['id'] ?? '—') ?></span></div>
          <div class="row"><span>Login</span><span><?= h($me['login'] ?? '—') ?></span></div>
          <div class="row"><span>Email</span><span><?= h($me['email'] ?? '—') ?></span></div>
          <div class="row"><span>Company</span><span><?= h(isset($me['company']) ? json_encode($me['company'], JSON_UNESCAPED_UNICODE) : '—') ?></span></div>
        </div>
      <?php else: ?>
        <p>After authorization, this panel calls <code>GET /me</code>. That confirms the saved token works and auto-refreshes when required.</p>
      <?php endif; ?>
      <div class="modules"><span class="chip">OAuth</span><span class="chip">Auto refresh</span><span class="chip">GET /me</span><span class="chip">Private token file</span></div>
    </div>
  </section>

  <section class="grid">
    <div class="card">
      <h2>WooCommerce connection</h2>
      <p>Save the WooCommerce store URL and REST API keys here. The WooCommerce page uses these settings to load products with pagination, search, and sorts.</p>
      <form method="post" action="/settings.php">
        <input type="hidden" name="settings_action" value="save_woocommerce">
        <div class="field-grid">
          <div class="field full">
            <label for="woo_site_url">Store URL</label>
            <input id="woo_site_url" name="woo_site_url" type="url" value="<?= h((string)($config['woo_site_url'] ?? '')) ?>" placeholder="https://nosisvoe.com.ua" required>
          </div>
          <div class="field">
            <label for="woo_namespace">REST namespace</label>
            <input id="woo_namespace" name="woo_namespace" type="text" value="<?= h((string)($config['woo_namespace'] ?? 'wc/v3')) ?>" placeholder="wc/v3">
          </div>
          <div class="field">
            <label for="woo_timeout">Timeout (seconds)</label>
            <input id="woo_timeout" name="woo_timeout" type="number" min="3" max="120" step="1" value="<?= h((string)($config['woo_timeout'] ?? 20)) ?>">
          </div>
          <div class="field">
            <label for="woo_consumer_key">Consumer key</label>
            <input id="woo_consumer_key" name="woo_consumer_key" type="text" value="" placeholder="<?= h(mask_secret((string)($config['woo_consumer_key'] ?? ''))) ?>">
          </div>
          <div class="field">
            <label for="woo_consumer_secret">Consumer secret</label>
            <input id="woo_consumer_secret" name="woo_consumer_secret" type="password" value="" placeholder="<?= h(mask_secret((string)($config['woo_consumer_secret'] ?? ''))) ?>">
          </div>
          <div class="field full">
            <label class="checkbox"><input type="checkbox" name="woo_verify_ssl" value="1"<?= !empty($config['woo_verify_ssl']) ? ' checked' : '' ?>> Verify SSL certificates for WooCommerce API requests</label>
          </div>
        </div>
        <p class="helper">Leave key/secret blank when saving if you want to keep the currently stored values unchanged.</p>
        <div class="actions">
          <button class="btn primary" type="submit">Save WooCommerce settings</button>
        </div>
      </form>
      <form method="post" action="/settings.php" onsubmit="return confirm('Clear WooCommerce settings?');">
        <input type="hidden" name="settings_action" value="clear_woocommerce">
        <div class="actions" style="margin-top:12px;">
          <button class="btn danger" type="submit">Clear WooCommerce settings</button>
        </div>
      </form>
    </div>

    <div class="card">
      <?php $wooStatusClass = $wooProbe ? 'ok' : ($wooConfigured ? '' : ''); ?>
      <div class="status <?= h($wooStatusClass) ?>"><span class="dot"></span><?= $wooProbe ? 'WooCommerce connection is working' : ($wooConfigured ? 'WooCommerce settings saved, connection needs attention' : 'WooCommerce not configured yet') ?></div>
      <div class="meta">
        <div class="row"><span>Store URL</span><span><?= h((string)($config['woo_site_url'] ?? '—')) ?></span></div>
        <div class="row"><span>API base</span><span><code><?= h(AllegroConfig::wooApiBase($config) ?: '—') ?></code></span></div>
        <div class="row"><span>Consumer key</span><span><?= h(mask_secret((string)($config['woo_consumer_key'] ?? ''))) ?></span></div>
        <div class="row"><span>Consumer secret</span><span><?= h(mask_secret((string)($config['woo_consumer_secret'] ?? ''))) ?></span></div>
        <div class="row"><span>SSL verification</span><span><?= !empty($config['woo_verify_ssl']) ? 'enabled' : 'disabled' ?></span></div>
        <div class="row"><span>Timeout</span><span><?= h((string)($config['woo_timeout'] ?? 20)) ?>s</span></div>
        <div class="row"><span>Products found</span><span><?= $wooProbe ? h((string)$wooProbe['product_count']) : '—' ?></span></div>
        <div class="row"><span>Total pages</span><span><?= $wooProbe ? h((string)$wooProbe['total_pages']) : '—' ?></span></div>
      </div>
      <div class="modules"><span class="chip">Basic Auth</span><span class="chip">/wp-json/<?= h((string)($config['woo_namespace'] ?? 'wc/v3')) ?></span><span class="chip">Products API</span></div>
    </div>
  </section>

  <section class="grid">
    <div class="card">
      <h2>Pricing + exchange settings</h2>
      <p>Keep the PLN ↔ UAH exchange rate and the default pricing modifiers here. These values are stored in runtime settings so they can be updated without editing PHP config files.</p>
      <form method="post" action="/settings.php">
        <input type="hidden" name="settings_action" value="save_pricing">
        <div class="field-grid">
          <div class="field">
            <label for="exchange_rate_pln_uah">PLN → UAH exchange rate</label>
            <input id="exchange_rate_pln_uah" name="exchange_rate_pln_uah" type="number" min="0" step="0.0001" value="<?= h(fmt_decimal((float)($config['exchange_rate_pln_uah'] ?? 0), 4)) ?>" placeholder="10.5000">
            <p class="helper">Manual exchange rate used for price conversions. Enter how many UAH equals <strong>1 PLN</strong>. The inverse rate is shown in the summary card.</p>
          </div>
          <div class="field">
            <label for="nacenka_percent">Nacenka (%)</label>
            <input id="nacenka_percent" name="nacenka_percent" type="number" min="0" step="0.01" value="<?= h(fmt_decimal((float)($config['nacenka_percent'] ?? 50), 2)) ?>">
            <p class="helper">Default markup percentage applied on top of the base cost. This now defaults to <strong>50%</strong>.</p>
          </div>
          <div class="field">
            <label for="delivery_cost_pln">Delivery Cost (PLN)</label>
            <input id="delivery_cost_pln" name="delivery_cost_pln" type="number" min="0" step="0.01" value="<?= h(fmt_decimal((float)($config['delivery_cost_pln'] ?? 0), 2)) ?>">
            <p class="helper">Fixed delivery cost amount in PLN that should be included in pricing calculations or offer preparation.</p>
          </div>
          <div class="field">
            <label>External rate update</label>
            <p class="helper">Use the button below to fetch the current PLN → UAH rate from <code>open.er-api.com</code>. It updates the input field and stores the source + timestamp.</p>
          </div>
        </div>
        <div class="actions">
          <button class="btn primary" type="submit">Save pricing settings</button>
        </div>
      </form>
      <form method="post" action="/settings.php">
        <input type="hidden" name="settings_action" value="refresh_exchange_rate">
        <input type="hidden" name="exchange_rate_pln_uah" value="<?= h(fmt_decimal((float)($config['exchange_rate_pln_uah'] ?? 0), 4)) ?>">
        <input type="hidden" name="nacenka_percent" value="<?= h(fmt_decimal((float)($config['nacenka_percent'] ?? 50), 2)) ?>">
        <input type="hidden" name="delivery_cost_pln" value="<?= h(fmt_decimal((float)($config['delivery_cost_pln'] ?? 0), 2)) ?>">
        <div class="actions" style="margin-top:12px;">
          <button class="btn secondary" type="submit">Update exchange rate from external source</button>
        </div>
      </form>
    </div>

    <div class="card">
      <?php $inverseRate = !empty($config['exchange_rate_pln_uah']) ? (1 / (float)$config['exchange_rate_pln_uah']) : 0; ?>
      <h2>Pricing summary</h2>
      <div class="meta">
        <div class="row"><span>Stored PLN → UAH rate</span><span><?= !empty($config['exchange_rate_pln_uah']) ? h(fmt_decimal((float)$config['exchange_rate_pln_uah'], 4)) : '—' ?></span></div>
        <div class="row"><span>Stored UAH → PLN inverse</span><span><?= $inverseRate > 0 ? h(fmt_decimal($inverseRate, 6)) : '—' ?></span></div>
        <div class="row"><span>Rate source</span><span><?= h((string)($config['exchange_rate_source'] ?: 'manual / not fetched yet')) ?></span></div>
        <div class="row"><span>Rate updated</span><span><?= h(fmt_iso_time((string)($config['exchange_rate_updated_at'] ?? ''))) ?></span></div>
        <div class="row"><span>Nacenka</span><span><?= h(fmt_decimal((float)($config['nacenka_percent'] ?? 50), 2)) ?>%</span></div>
        <div class="row"><span>Delivery Cost</span><span><?= h(fmt_decimal((float)($config['delivery_cost_pln'] ?? 0), 2)) ?> PLN</span></div>
      </div>
      <div class="modules"><span class="chip">PLN ↔ UAH</span><span class="chip">Markup</span><span class="chip">Delivery cost</span></div>
    </div>
  </section>

  <section class="grid">
    <div class="card">
      <h2>API connection</h2>
      <div class="meta">
        <div class="row"><span>Developer portal</span><span><a href="https://developer.allegro.pl/" target="_blank" rel="noopener noreferrer">developer.allegro.pl</a></span></div>
        <div class="row"><span>OAuth authorize</span><span><code>https://allegro.pl/auth/oauth/authorize</code></span></div>
        <div class="row"><span>OAuth token</span><span><code>https://allegro.pl/auth/oauth/token</code></span></div>
        <div class="row"><span>API base</span><span><code><?= h((string)AllegroConfig::apiBase($config)) ?></code></span></div>
        <div class="row"><span>Public User-Agent URL</span><span><code><?= h((string)($config['user_agent_info_url'] ?? 'https://allegro.neevalex.com/')) ?></code></span></div>
      </div>
    </div>

    <div class="card">
      <h2>Dashboard cache + refresh</h2>
      <div class="meta">
        <div class="row"><span>Refresh state</span><span><?= h((string)($refreshMeta['status'] ?? 'idle')) ?></span></div>
        <div class="row"><span>Refresh started</span><span><?= h(fmt_iso_time(isset($refreshMeta['started_at']) ? (string)$refreshMeta['started_at'] : null)) ?></span></div>
        <div class="row"><span>Refresh finished</span><span><?= h(fmt_iso_time(isset($refreshMeta['finished_at']) ? (string)$refreshMeta['finished_at'] : null)) ?></span></div>
        <div class="row"><span>Dashboard cache time</span><span><?= h(fmt_iso_time(isset($dashboardCacheMeta['generated_at']) ? (string)$dashboardCacheMeta['generated_at'] : null)) ?></span></div>
        <div class="row"><span>Cache status</span><span><?= !empty($dashboardCacheMeta['ok']) ? 'ok' : 'not ready' ?></span></div>
      </div>
      <?php if (!empty($dashboardCacheMeta['error'])): ?>
        <p class="helper">Last cache error: <?= h((string)$dashboardCacheMeta['error']) ?></p>
      <?php else: ?>
        <p class="helper">Use the header Refresh data button to force a background rebuild of the dashboard cache.</p>
      <?php endif; ?>
    </div>
  </section>
</main>
</body>
</html>
