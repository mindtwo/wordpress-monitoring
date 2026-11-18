# Changelog

All notable changes to `mindtwo/wordpress-monitoring` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Changed

- `MONITORING_*` constants and environment variables now win over values saved under Settings → Monitoring (before: the backend won). A key or secret saved in the backend travelled along with every database copy, so a local copy of a live site reported to the live target. The settings screen shows overridden fields read-only and the value in effect for switches. On the first request after the update the plugin removes stored keys, secrets and other text values that are identical to the wp-config constant overriding them. Values that differ from the constant, and values overridden only by an environment variable (which CLI cron may not see), stay stored.
- **Check before updating:** where a site has both a constant (or a variable set by the hosting) and a different backend value, the constant now wins. This concerns e.g. multisite networks with one network-wide constant and per-site keys. ZIP installs update themselves, so check such sites first.
- A `false` constant counts as unset for text settings, so `define('MONITORING_SECRET', getenv('MONITORING_SECRET'))` without the variable keeps the stored secret. For switches `false` still switches off.

### Fixed

- Environment variables are found when `$_ENV` holds an empty entry for them; an empty entry hid `$_SERVER` and `getenv()` before.
- Unchecked boxes on the settings screen stay off on the very first save. WordPress runs the sanitize callback twice when it creates the option, and the second run read the stored `false` as "submitted".
- The README lists the constants the plugin actually reads: `MONITORING_CACHE_SECONDS` and `MONITORING_RATE_LIMIT_PER_MINUTE` (not `MONITORING_ROUTE_CACHE` / `MONITORING_RATE_LIMIT`), plus `MONITORING_TIMEOUT`.
- Projects with `johnpbloch/wordpress` report their Composer packages and the Composer audit again without `MONITORING_PROJECT_ROOT`. The project root detection took the core's own `composer.json` in `public/wp/` and found no `composer.lock` there; it now prefers the nearest directory with a `composer.lock` and only falls back to the nearest `composer.json` when no lock exists. `MONITORING_PROJECT_ROOT` still wins.

## 1.1.1 - 2026-10-07

### Fixed

- The pull endpoint answers `/api/app-monitoring` without a trailing slash again. With permalinks ending in `/`, WordPress' canonical redirect answered first with a 301, which the dashboard does not follow.

## 1.1.0 - 2026-10-07

### Added

- Installable release ZIP with bundled dependencies for WordPress sites without Composer, built and attached to every GitHub release by `bin/build-zip.sh` and the release workflow.
- Update notifications and one-click updates for ZIP installs via the `Update URI` header and the latest GitHub release (`MONITORING_SELF_UPDATE` to opt out). Composer-managed installs are unaffected.

## 1.0.0 - 2026-06-13

Initial release.

### Added

- WordPress plugin: core/plugin/theme collectors with update detection, WP-Cron push, signed rewrite-based pull endpoint (/api/app-monitoring) with transient caching and rate limiting, settings screen with option→constant/env→default priority chain, fully testable WordPressApi adapter.
- HMAC-SHA256 request authentication with replay protection, shared with the whole suite.
