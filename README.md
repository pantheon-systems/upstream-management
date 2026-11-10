Pantheon Upstream Management
============================

This package provides a series of scripts to be used to manage upstreams in [Pantheon](https://pantheon.io).

## Installation

Install it as usual with composer:

```
composer require pantheon-systems/upstream-management
```

It will prompt you to authorize the plugin, please do so.

## Usage

This plugin provides two commands to be used by custom upstreams:

### upstream:require

Use it to require dependencies in your upstream-configuration folder.

```
composer upstream-require drupal/ctools
```

### upstream:update-dependencies

Use it to use version locked dependencies in your upstream (and to update those versions). This way you can pin the versions for your upstream dependencies.

```
composer upstream:update-dependencies
```

## Development

### Requirements

- PHP 8.1+
- Composer 2.9+

### Setup

```bash
composer install
```

### Running tests

```bash
# Run coding standards check
composer cs

# Run functional tests
composer test
```

## Releasing

This package is published on [Packagist](https://packagist.org/packages/pantheon-systems/upstream-management).
Create a GitHub release with a new semver tag (no `v` prefix, matching `1.0.0` and `1.0.1`); Packagist
picks it up through its webhook. Merging to `main` alone releases nothing. Details in
[DEPLOYMENT.md](DEPLOYMENT.md).

## Rollback

- **When:** a new release breaks `upstream:require` or `upstream:update-dependencies` for custom upstreams.
- **Recover:** revert the change on `main` and publish a new patch release so caret constraints resolve to
  good code. Never delete or move a published tag.
- **Verify:** packagist.org lists the new tag with the expected commit.
- **Who:** `#ask-hosting` (developer-experience).

Details: [DEPLOYMENT.md](DEPLOYMENT.md#rollback).
