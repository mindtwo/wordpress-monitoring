# Changelog

All notable changes to `mindtwo/wordpress-monitoring` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.1 - Unreleased

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
