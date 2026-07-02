<?php
declare(strict_types=1);
$bootstrap = __DIR__ . '/bootstrap.php';
if (!file_exists($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/public/bootstrap.php';
}
require_once $bootstrap;

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/offer.php', PHP_URL_PATH) ?: '/offer.php';

$offerId = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$offer = null;
$error = null;
$updateError = null;
$updateResult = null;
$refreshState = is_string($_GET['refresh'] ?? null) ? (string)$_GET['refresh'] : '';
$refreshMessage = is_string($_GET['refresh_message'] ?? null) ? (string)$_GET['refresh_message'] : '';
$refreshAt = is_string($_GET['refresh_at'] ?? null) ? (string)$_GET['refresh_at'] : '';
$refreshPid = is_string($_GET['refresh_pid'] ?? null) ? (string)$_GET['refresh_pid'] : '';
$refreshReturnTo = '/offer.php' . ($offerId !== '' ? '?id=' . rawurlencode($offerId) : '');
$refreshMeta = allegro_read_dashboard_refresh_state();

if ($offerId === '') {
    $error = 'Offer ID is missing.';
} elseif ($configured && $status['authorized']) {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $name = trim((string)($_POST['name'] ?? ''));
            $externalId = trim((string)($_POST['external_id'] ?? ''));
            $priceAmount = trim((string)($_POST['price_amount'] ?? ''));
            $stockAvailableRaw = trim((string)($_POST['stock_available'] ?? ''));

            if ($name === '') {
                throw new InvalidArgumentException('Offer title is required.');
            }
            if (mb_strlen($name) < 12) {
                throw new InvalidArgumentException('Offer title must be at least 12 characters long.');
            }
            if ($priceAmount === '' || !is_numeric(str_replace(',', '.', $priceAmount))) {
                throw new InvalidArgumentException('Price must be a valid number.');
            }
            if ($stockAvailableRaw === '' || filter_var($stockAvailableRaw, FILTER_VALIDATE_INT) === false || (int)$stockAvailableRaw < 0) {
                throw new InvalidArgumentException('Stock must be a whole number greater than or equal to zero.');
            }

            $updateResult = $client->updateProductOffer($offerId, [
                'name' => $name,
                'external_id' => $externalId,
                'price_amount' => $priceAmount,
                'stock_available' => (int)$stockAvailableRaw,
            ]);
            $offer = $updateResult['offer'];
        } else {
            $offer = $client->getProductOffer($offerId);
        }
    } catch (Throwable $e) {
        $updateError = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $e->getMessage() : null;
        $error = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? null : $e->getMessage();
        try {
            $offer = $client->getProductOffer($offerId);
        } catch (Throwable) {
            // Keep the original error if reloading offer fails too.
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Allegro Manager — Edit offer</title>
  <meta name="description" content="Allegro Manager — edit an Allegro offer.">
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
    main { width: min(1280px, 100%); margin: 0 auto; display: grid; gap: var(--gap); }
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
    .notice { border: 1px solid #fed7aa; background: #fff7ed; color: #9a3412; border-radius: 18px; padding: clamp(16px, 3vw, 20px); }
    .success { border-color: #bbf7d0; background: #ecfdf3; color: #166534; }
    .error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(320px, .8fr); gap: var(--gap); }
    .meta { display: grid; gap: 0; }
    .row { display: flex; justify-content: space-between; gap: 18px; padding: 12px 0; border-top: 1px solid var(--line); }
    .row span:first-child { color: var(--muted); }
    .row span:last-child { text-align: right; font-weight: 750; overflow-wrap: anywhere; }
    .field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .field { display: grid; gap: 8px; }
    .field.full { grid-column: 1 / -1; }
    label { font-weight: 700; font-size: 14px; color: var(--ink); }
    input { width: 100%; min-height: 46px; border-radius: 14px; border: 1px solid var(--line); padding: 12px 14px; font: inherit; color: var(--ink); background: #fff; }
    .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
    .thumb-wrap { display: grid; gap: 16px; }
    .thumb { width: 100%; max-width: 360px; aspect-ratio: 1 / 1; object-fit: cover; border-radius: 22px; border: 1px solid var(--line); background: linear-gradient(135deg,#f8fafc,#fff7ed); }
    .pill-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:18px; }
    .pill { display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border-radius:999px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-size:13px; font-weight:800; }
    code { background:#f3f4f6; border:1px solid #e5e7eb; border-radius:7px; padding:1px 5px; }
    @media (max-width: 900px) {
      .grid, .field-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 440px) {
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
    <p class="eyebrow">Offer editor</p>
    <h1>Edit Allegro offer</h1>
    <p>Open an offer from the Offers list, edit the main listing fields here, then click <strong>Update offer</strong>.</p>
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

  <?php if (!$configured || !$status['authorized']): ?>
    <section class="card notice error"><strong>Allegro connection needs attention.</strong> Open <a href="/settings.php">Settings</a> to authorize Allegro before editing offers.</section>
  <?php endif; ?>

  <?php if ($error): ?>
    <section class="card notice error"><strong>Offer error:</strong> <?= h($error) ?></section>
  <?php endif; ?>

  <?php if ($updateError): ?>
    <section class="card notice error"><strong>Update failed:</strong> <?= h($updateError) ?></section>
  <?php endif; ?>

  <?php if ($updateResult && $offer): ?>
    <section class="card notice success"><strong>Offer updated.</strong> HTTP <?= h((string)$updateResult['status_code']) ?> from Allegro.<?php if (!empty($updateResult['trace_id'])): ?> Trace-Id: <code><?= h((string)$updateResult['trace_id']) ?></code><?php endif; ?></section>
  <?php endif; ?>

  <?php if ($offer): ?>
    <section class="grid">
      <div class="card">
        <div class="actions" style="margin-top:0; margin-bottom:18px;">
          <a class="btn secondary" href="/offers.php">← Back to offers</a>
          <?php if ($offer['allegro_url'] !== ''): ?>
            <a class="btn secondary" href="<?= h($offer['allegro_url']) ?>" target="_blank" rel="noopener noreferrer">Open on Allegro ↗</a>
          <?php endif; ?>
        </div>
        <h2>Editable fields</h2>
        <form method="post" action="/offer.php">
          <input type="hidden" name="id" value="<?= h($offer['id']) ?>">
          <div class="field-grid">
            <div class="field full">
              <label for="name">Offer title</label>
              <input id="name" name="name" type="text" required minlength="12" maxlength="75" value="<?= h($offer['name']) ?>">
            </div>
            <div class="field">
              <label for="external_id">External SKU</label>
              <input id="external_id" name="external_id" type="text" value="<?= h($offer['external_id']) ?>">
            </div>
            <div class="field">
              <label for="price_amount">Price (<?= h($offer['price_currency']) ?>)</label>
              <input id="price_amount" name="price_amount" type="text" inputmode="decimal" value="<?= h($offer['price_amount']) ?>">
            </div>
            <div class="field">
              <label for="stock_available">Available stock</label>
              <input id="stock_available" name="stock_available" type="number" min="0" step="1" value="<?= h((string)$offer['stock_available']) ?>">
            </div>
            <div class="field">
              <label for="stock_unit">Stock unit</label>
              <input id="stock_unit" type="text" value="<?= h($offer['stock_unit']) ?>" readonly>
            </div>
          </div>
          <div class="actions">
            <button class="btn primary" type="submit">Update offer</button>
            <a class="btn secondary" href="/offer.php?id=<?= h(rawurlencode($offer['id'])) ?>">Reload offer</a>
          </div>
        </form>
      </div>

      <div class="card thumb-wrap">
        <?php if ($offer['primary_image_url'] !== ''): ?>
          <img class="thumb" src="<?= h($offer['primary_image_url']) ?>" alt="<?= h($offer['name']) ?>">
        <?php else: ?>
          <div class="thumb" aria-hidden="true"></div>
        <?php endif; ?>
        <div>
          <h2>Offer info</h2>
          <div class="meta">
            <div class="row"><span>Offer ID</span><span><?= h($offer['id']) ?></span></div>
            <div class="row"><span>Status</span><span><?= h($offer['status']) ?></span></div>
            <div class="row"><span>Category</span><span><?= h($offer['category_id']) ?></span></div>
            <div class="row"><span>Format</span><span><?= h($offer['format']) ?></span></div>
            <div class="row"><span>Created</span><span><?= h(fmt_iso_time($offer['created_at'])) ?></span></div>
            <div class="row"><span>Updated</span><span><?= h(fmt_iso_time($offer['updated_at'])) ?></span></div>
            <div class="row"><span>Published</span><span><?= h(fmt_iso_time($offer['publication_started_at'] ?? $offer['publication_starting_at'] ?? $offer['publication_ended_at'])) ?></span></div>
            <div class="row"><span>Description sections</span><span><?= h((string)$offer['description_sections']) ?></span></div>
            <?php if (!empty($offer['trace_id'])): ?>
              <div class="row"><span>Trace-Id</span><span><code><?= h((string)$offer['trace_id']) ?></code></span></div>
            <?php endif; ?>
          </div>
          <div class="pill-row">
            <span class="pill">Editable here: title</span>
            <span class="pill">external SKU</span>
            <span class="pill">price</span>
            <span class="pill">available stock</span>
          </div>
        </div>
      </div>
    </section>
  <?php elseif (!$error): ?>
    <section class="card">
      <h2>Select an offer first</h2>
      <p>Open the <a href="/offers.php">Offers</a> page and click an offer title to load its individual editor.</p>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
