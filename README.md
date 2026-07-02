# Allegro Manager

Manager-facing PHP app for operating an Allegro seller account and preparing WooCommerce products for Allegro offers.

## What is included

- **Dashboard** for sales, balance, shipments, and offer summaries
- **Offer views** for existing Allegro listings
- **Settings** screen for Allegro + WooCommerce runtime configuration
- **WooCommerce catalog browser**
- **WooCommerce → Allegro draft workflow** with:
  - collapsible variation cards
  - structured product fields instead of raw editable JSON
  - category/parameter editing
  - draft persistence
  - send-selection controls
  - stock mapping (`instock -> 40`, `outofstock -> 0`)

## Repository layout

This repository is a safe-to-publish copy of the live app code.

```text
app/                          Shared PHP classes
public/                       Web root for allegro.neevalex.com
public/assets/                Static assets
refresh_dashboard_summary.php Dashboard cache refresh script
data/                         Runtime state only (kept empty in git)
config.example.php            Example config without secrets
```

## Live deployment mapping

The live server currently uses two paths:

- `app/` and runtime files are deployed under `/var/www/allegro-manager/`
- `public/` files are deployed under `/var/www/html/allegro/`

This repo keeps those code areas together so they can be versioned safely.

## Sensitive files and runtime data

This repository intentionally **does not track**:

- `config.php`
- `data/settings.json`
- `data/tokens.json`
- `data/oauth-state.json`
- dashboard caches
- generated drafts/logs
- any other runtime JSON or log output

Use `config.example.php` as the starting point for a real deployment.

## Setup

1. Copy `config.example.php` to `config.php`
2. Fill in Allegro app credentials
3. Configure runtime settings in the Settings page or via `data/settings.json`
4. Deploy:
   - `app/` to `/var/www/allegro-manager/app/`
   - `public/` to `/var/www/html/allegro/`
   - `refresh_dashboard_summary.php` to `/var/www/allegro-manager/`
5. Make sure the runtime `data/` directory is writable by the web app

## Runtime data directories

Create these directories on the server if they do not exist:

```text
data/
data/woo-allegro-drafts/
data/woo-allegro-logs/
```

## Notes

- The web UI uses absolute server paths in a few places for the current deployment layout.
- `config.php` is for local/static defaults; runtime settings are expected in `data/settings.json`.
- Never commit secrets, tokens, or exported runtime data.
