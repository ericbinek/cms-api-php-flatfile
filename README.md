# schema.org aligned CMS API (PHP)

[![Tests](https://github.com/ericbinek/cms-api-php-flatfile/actions/workflows/test.yml/badge.svg)](https://github.com/ericbinek/cms-api-php-flatfile/actions/workflows/test.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
![Version](https://img.shields.io/badge/version-0.1.0-blue.svg)
![Status](https://img.shields.io/badge/status-work_in_progress-orange.svg)
![Build in public](https://img.shields.io/badge/build-in_public-ff69b4.svg)
![PRs welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)
![PHP 8.5](https://img.shields.io/badge/PHP-8.5-blueviolet.svg)

A standalone, schema.org aligned CMS API written in plain PHP 8.5.

There is no Composer, no `vendor/` directory, and no framework. It runs on PHP's built in web server and the standard library, with `declare(strict_types=1)` throughout.

It exposes CRUD endpoints for 10 schema.org entity types such as BlogPosting, Person, and WebPage, backed by flat-file JSON storage, with validation, pagination, filtering, sorting, ETag caching, and reference embedding.

A conformance test suite defines the HTTP contract.

## Status: work in progress (v0.1.0)

This is an ongoing build-in-public project, shared only for community and communication purposes. Do not deploy it in production. Do not rely on its interfaces or data format remaining stable.

## No Composer

This is modern PHP without the dependency tree: no `composer install`, no `vendor/`, no framework. Strict types are on everywhere, the server is the built in `php -S`, and the `composer.json` here only describes the project, it does not pull anything in. Clone it and run it.

## Requirements

- PHP 8.5 or newer

## Installation

```sh
git clone https://github.com/ericbinek/cms-api-php-flatfile.git
cd cms-api-php-flatfile
cp .env.example .env
```

## Running

```sh
php -S 0.0.0.0:3002 -t public src/server.php
```

The server listens on `PORT` (default 3002).

## Usage

```sh
curl http://localhost:3002/blog-postings
```

All list endpoints return `{ items, total }`. See per-entity routes below.

## Entities

- `BlogPosting`
- `Person`
- `WebPage`
- `ImageObject`
- `CategoryCode`
- `CategoryCodeSet`
- `DefinedTerm`
- `DefinedTermSet`
- `Comment`
- `WebSite`

## Testing

```sh
php bin/test.php
```

## Contributing

Contributions are welcome. This is a build-in-public project, so issues, questions, and ideas count as much as pull requests. If you send code, keep it dependency free with `declare(strict_types=1)` and no Composer packages, and keep the conformance suite green, since the tests are the contract. Run them with `php bin/test.php`.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full guidelines.

## License

MIT. See [LICENSE](LICENSE).
