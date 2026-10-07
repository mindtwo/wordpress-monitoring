# mindtwo/wordpress-monitoring

[![Tests](https://github.com/mindtwo/wordpress-monitoring/actions/workflows/tests.yml/badge.svg)](https://github.com/mindtwo/wordpress-monitoring/actions/workflows/tests.yml)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](phpstan.neon.dist)
[![PHP 8.0+](https://img.shields.io/badge/php-%5E8.0-blue)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-lightgrey)](LICENSE.md)

WordPress plugin of the mindtwo monitoring suite. On top of
[`mindtwo/base-monitoring`](https://github.com/mindtwo/base-monitoring) — which collects OS,
web server, database, Node.js, system stats, Composer/npm packages, security audits, licenses
and git status — this plugin adds:

- **WordPress collectors** — core version (matched against endoflife.date), installed plugins
  and themes with versions, activity state and **available updates**, plus operational state
  (environment type, debug flags, multisite, language).
- **Push** — a WP-Cron scheduled push of signed snapshots to the central endpoint.
- **Pull** — a signed `GET /api/app-monitoring` endpoint with rate limiting, optional IP
  allow-listing and transient-cached snapshots.
- **Settings screen** — Settings → Monitoring, with the spec'd priority chain:
  admin backend → `MONITORING_*` constants/environment variables → secure defaults.

## Installation

### Classic WordPress without Composer (ZIP upload)

For sites that are not versioned and have no Composer setup. Every
[GitHub release](https://github.com/mindtwo/wordpress-monitoring/releases) carries a ready-to-install
`wordpress-monitoring-<version>.zip` with all dependencies bundled. Use that asset — the
auto-generated "Source code (zip)" lacks `vendor/` and leaves the plugin silently inactive.

1. **Plugins → Add New → Upload Plugin**, choose the ZIP, install and activate.
2. **Settings → Monitoring**: enter project key and secret from the dashboard.
3. Low-traffic site? WP-Cron only runs on visits — either configure a
   [real cron](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/)
   or let the dashboard pull.

Requirements: WordPress 6.0+, PHP 8.0+.

**Updates** appear like any other plugin update under *Plugins* and *Dashboard → Updates*,
including one-click and automatic updates. The plugin asks the latest GitHub release (cached for
6 hours, failures for 1 hour; *Check again* clears the cache). This only happens for ZIP installs —
Composer-managed installs never self-update. Disable it with
`define('MONITORING_SELF_UPDATE', false);`.

### Composer-based WordPress (Bedrock & co.)

```bash
composer require mindtwo/wordpress-monitoring
wp plugin activate wordpress-monitoring
```

### Custom Composer setups (non-Bedrock)

If your project manages WordPress via Composer (e.g. `johnpbloch/wordpress`) but does **not**
load the project-root `vendor/autoload.php` globally, the plugin's classes are unavailable when
WordPress loads the plugin file. The result is silent: no admin menu, no pull endpoint, no push
— everything is a no-op.

**Fix:** ensure `vendor/autoload.php` is included before plugins run. Two options:

**Option A — `wp-config.php`** (preferred when you control the file):

```php
require_once dirname(__DIR__) . '/vendor/autoload.php';
```

**Option B — must-use plugin** (tracked in git, survives redeployments):

Create `public/wp-content/mu-plugins/autoload.php`:

```php
<?php
$autoloader = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (! class_exists(Composer\Autoload\ClassLoader::class) && is_readable($autoloader)) {
    require $autoloader;
}
```

Adjust the `dirname` depth to match your directory layout (`mu-plugins/` → `wp-content/` →
`public/` → project root = depth 3).

After loading the autoloader, **flush the rewrite rules once** so the pull endpoint is
registered. Either:

```bash
wp rewrite flush --hard
```

or go to **Settings → Permalinks → Save Changes** in WP Admin.

### Configuration

Preferred: constants in `wp-config.php` (or environment variables) so secrets never live in
the database:

```php
define('MONITORING_PROJECT_KEY', 'prj_live_8f3a…');
define('MONITORING_SECRET', getenv('MONITORING_SECRET'));
```

Alternatively use the **Settings → Monitoring** screen. Backend values win over constants;
blank backend fields fall through. Every setting is overridable:

| Key (option / constant) | Default | Purpose |
| --- | --- | --- |
| `enabled` / `MONITORING_ENABLED` | `true` | Master switch |
| `project_key` / `MONITORING_PROJECT_KEY` | – | Project key from the dashboard |
| `secret` / `MONITORING_SECRET` | – | Shared secret (never transmitted) |
| `endpoint` / `MONITORING_ENDPOINT` | central endpoint | Push target |
| `ip_allow_list` / `MONITORING_IP_ALLOW_LIST` | – | Comma-separated IPs / CIDR ranges |
| `route_enabled` / `MONITORING_ROUTE_ENABLED` | `true` | Expose the pull endpoint |
| `schedule_enabled` / `MONITORING_SCHEDULE_ENABLED` | `true` | WP-Cron push |
| `MONITORING_SCHEDULE_RECURRENCE` | `daily` | Any registered cron recurrence |
| `MONITORING_ROUTE_CACHE` | `300` | Pull snapshot cache seconds (`0` disables) |
| `MONITORING_RATE_LIMIT` | `10` | Pull requests per minute per IP |
| `MONITORING_SIGNATURE_TOLERANCE` | `300` | Signature timestamp window (seconds) |
| `MONITORING_PROJECT_ROOT` | auto | Where composer.lock & git live (auto-detects Bedrock layouts) |
| `MONITORING_SELF_UPDATE` | `true` | Update checks against GitHub releases (ZIP installs only) |

## The pull endpoint

`GET /api/app-monitoring` returns the current snapshot as JSON. Requests must be signed
exactly like every endpoint of the suite:

```text
X-Monitoring-Key:       <project key>
X-Monitoring-Timestamp: <unix timestamp>
X-Monitoring-Signature: hex( hmac_sha256( "<timestamp>.<raw request body>", secret ) )
```

Request order: rate limit (429) → IP allow-list (403) → configuration guard (503) →
signature + replay window (401) → cached snapshot (200). The endpoint is registered through a
rewrite rule — it is flushed automatically on activation/deactivation.

> Behind a proxy/load balancer make sure `REMOTE_ADDR` carries the real client IP before
> relying on the allow-list.

## How pushing works

On activation (and kept in sync on every request) a `mindtwo_monitoring_push` event is
scheduled via WP-Cron. When it fires, a snapshot is built — every collector individually
fault-isolated — signed, and POSTed to the configured endpoint.

WP-Cron only runs on traffic; for reliably timed pushes on low-traffic sites use a
[real cron for wp-cron.php](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/).

## Architecture note

All logic is unit-tested against a `WordPressApi` interface — `get_option`, plugin lists,
transients, cron calls all pass through it, with a guarded native implementation. The
WordPress glue ([`Plugin`](src/Plugin.php), the settings screen) only wires hooks.

## Development

```bash
composer install
composer check    # pint --test + phpstan (level 8, wordpress-stubs) + pest
```

## Releasing

1. Bump `Version:` in [`wordpress-monitoring.php`](wordpress-monitoring.php) and add the
   [CHANGELOG](CHANGELOG.md) entry; commit. Raising the minimum PHP or WordPress version? Change
   `Requires PHP` / `Requires at least` in the same header — the ZIP build resolves its
   dependencies against it, and installed sites read it from the tag before (auto-)updating.
2. Tag and push: `git tag v1.2.0 && git push origin v1.2.0`.

The [release workflow](.github/workflows/release.yml) builds the ZIP with
[`bin/build-zip.sh`](bin/build-zip.sh) and attaches it to the GitHub release (creating the release
if needed). The build fails when the tag and the plugin header disagree — a mismatch would make
WordPress offer the same update forever. To build locally: `bin/build-zip.sh 1.2.0` (packages the
committed `HEAD` into `dist/`).

## Security

If you discover a security issue, please email [info@mindtwo.de](mailto:info@mindtwo.de)
instead of opening a public issue.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
