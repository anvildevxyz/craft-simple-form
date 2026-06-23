# Upgrade Guide

Breaking changes and the steps to adopt them, newest first. Day-to-day changes
(features, fixes) live in the [CHANGELOG](../CHANGELOG.md); this guide covers only
changes that need action when upgrading across a **major** version.

What counts as breaking is defined by the [API Stability policy](extending/api-stability.md):
only the documented **public API** carries a backward-compatibility promise.

## Upgrading within 1.x

No breaking changes. Every 1.x release is backward-compatible with the public
API — `composer update` and run `php craft migrate/all`. New developer events,
field types, and helpers are additive (see the [CHANGELOG](../CHANGELOG.md)).

<!--
When a breaking change ships in a future major, document it here as:

## 2.0.0

### <short title of the change>
- **What changed:** …
- **Why:** …
- **Migrate:** … (concrete before/after)
-->
