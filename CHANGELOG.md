# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.1] - 2026-10-30

### Changed

- Dependencies updated. The runtime requirements are unchanged; for development,
  PHPStan 2.3.4, phpstan-phpunit 2.1.1, phpstan-strict-rules 2.1.0, Rector 2.8.3,
  ECS 13.3.12 and PHPUnit 11.5.57.

## [1.1.0] - 2026-10-27

### Fixed

- The human view fits phone screens: the stylesheet adds an XHTML viewport `<meta>` to the
  sitemap and stacks the date under each URL, and the script shows each row as a card instead
  of a table that overflowed the screen.

## [1.0.0] - 2026-10-27

First release.

### Added

- `/sitemap.xml` as a sitemap index with one child sitemap per segment: the
  homepage, singletons and static routes (`/sitemap-main.xml`), each ContentType
  (`/sitemap-<slug>.xml`, split at `chunk_size` URLs) and configured taxonomies.
- One `<url>` per page and locale with complete, reciprocal `hreflang`
  alternates and `x-default`.
- Singletons at their slugless listing URL, with slugless alternates, and
  without a duplicate listing entry; a custom `record_route` is kept as is
  (from bolt/sitemap-extension#5).
- Static `TemplateController` routes with their `{_locale}` twins as
  alternates, `exclude_static_routes` and `static_route_lastmod` (from
  bolt/sitemap-extension#4, without the template file time as `<lastmod>`).
- Removal-aware `<lastmod>` for listing pages and child sitemaps, kept in
  `var/data/tomvondracek-sitemap.json`.
- Images from configurable image and imagelist fields (`image:loc` only).
- Optional bolt-seo support: with appolodev/bolt-seo installed, pages it marks
  `noindex` or gives another canonical URL are left out. It is never required;
  a contract test runs bolt-seo's own `Seo` class to keep the two in step.
- `application/xml`, `X-Robots-Tag: noindex`, content-hash ETag and 304; the
  generated XML is cached until content changes.
- An opt-in view for browsers without XSLT: `/sitemap.css`, `/sitemap-ui.js`,
  or your own files.
- `SitemapUrlsEvent` to add, remove or change URLs.

[Unreleased]: https://github.com/Vondry/bolt-sitemap/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/Vondry/bolt-sitemap/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/Vondry/bolt-sitemap/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Vondry/bolt-sitemap/releases/tag/v1.0.0
