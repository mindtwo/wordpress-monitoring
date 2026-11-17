# Changelog

All notable changes to `mindtwo/wordpress-monitoring` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Fixed

- Local copies no longer report to the production target. A production database pulled to a laptop brought the live project key and the enabled option along, and without `WP_ENVIRONMENT_TYPE` WordPress counts as `production`, so the copy pushed its own PHP, Node.js and OS versions. Installations with environment type `local` or `development` now stay silent unless `MONITORING_ENABLED` is set to true, and `MONITORING_ENABLED=false` now wins over the backend option. The settings screen says when monitoring is paused this way.
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
