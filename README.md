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

### Composer-based WordPress (Bedrock & co.)

```bash
composer require mindtwo/wordpress-monitoring
wp plugin activate wordpress-monitoring
```

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

## Security

If you discover a security issue, please email [info@mindtwo.de](mailto:info@mindtwo.de)
instead of opening a public issue.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
