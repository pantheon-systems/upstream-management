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

This plugin provides three commands to be used by custom upstreams:

### upstream:require

Use it to require dependencies in your upstream-configuration folder.

### upstream:remove

Use it to remove dependencies in your upstream-configuration folder.

```
composer upstream-require drupal/ctools
```

### upstream:lock-dependencies

Use it command to lock global pinned dependencies to the versions in the upstream-configuration/composer.lock file. This command will also update unpinned packages in the custom-upstream.

```
composer upstream:lock-dependencies
```
