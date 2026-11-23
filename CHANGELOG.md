# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.5] - 2026-11-23

### Development

- CI checks that every PHP file parses on each supported PHP version, runs
  `composer audit` and actionlint, and cancels the older run when a newer push
  arrives, like the other extensions.
- `SECURITY.md`, and `support` and `funding` in `composer.json`.

## [1.1.4] - 2026-11-22

### Changed

- The Composer package leaves out the tests, CI and tool configuration (`.gitattributes` with
  `export-ignore`).

### Fixed

- An image whose file name is `0` is listed; it was taken for an empty image field.

### Development

- Dependencies updated. The runtime requirements are unchanged; for development, PHPStan 2.3.7,
  phpstan-phpunit 2.1.2, phpstan-strict-rules 2.1.1 and Rector 2.8.5.
- ECS, PHPStan, PHPUnit and Rector use the same configuration as the other extensions.
- GitHub Actions are pinned to commit SHAs.

## [1.1.3] - 2026-11-21

### Changed

- Dependencies updated. The runtime requirements are unchanged; for
  development, PHPUnit 11.5.58 and ECS 13.3.23.
- Dependabot proposes updates to the Composer dependencies and GitHub Actions
  once a month.

## [1.1.2] - 2026-11-09

### Changed

- The documentation now says `human_view` is for development only: Google Search Console
  does not recognize the `<meta>` and `<script>` elements it adds and reports validation errors.

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

[Unreleased]: https://github.com/Vondry/bolt-sitemap/compare/v1.1.5...HEAD
[1.1.5]: https://github.com/Vondry/bolt-sitemap/compare/v1.1.4...v1.1.5
[1.1.4]: https://github.com/Vondry/bolt-sitemap/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/Vondry/bolt-sitemap/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/Vondry/bolt-sitemap/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/Vondry/bolt-sitemap/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/Vondry/bolt-sitemap/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Vondry/bolt-sitemap/releases/tag/v1.0.0
