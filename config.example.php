<?php
return [
    // Register the app in Allegro developer portal and set the redirect URI to:
    // https://allegro.neevalex.com/auth.php?action=callback
    'client_id' => 'PASTE_ALLEGRO_CLIENT_ID_HERE',
    'client_secret' => 'PASTE_ALLEGRO_CLIENT_SECRET_HERE',

    // prod = allegro.pl, sandbox = allegro.pl.allegrosandbox.pl
    'environment' => 'prod',

    // Leave null to auto-detect from current request; explicit value is safer behind proxies.
    'redirect_uri' => null,

    // Allegro requires a stable, identifiable User-Agent.
    'user_agent' => 'AllegroManager/0.1 (+https://allegro.neevalex.com/)',

    // Optional WooCommerce REST API settings.
    'woo_site_url' => 'https://example.com',
    'woo_consumer_key' => 'PASTE_WOOCOMMERCE_CONSUMER_KEY_HERE',
    'woo_consumer_secret' => 'PASTE_WOOCOMMERCE_CONSUMER_SECRET_HERE',
    'woo_namespace' => 'wc/v3',
    'woo_timeout' => 20,
    'woo_verify_ssl' => true,

    // Optional pricing/exchange settings.
    'exchange_rate_pln_uah' => 0,
    'exchange_rate_source' => '',
    'exchange_rate_updated_at' => '',
    'nacenka_percent' => 50,
    'delivery_cost_pln' => 0,
];
