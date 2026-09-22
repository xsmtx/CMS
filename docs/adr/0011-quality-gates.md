# 0011 — Quality gates and architecture tests

Status: accepted
Date: 2026-09-22

## Context

This codebase is intended to be maintained for years by people who did not
write it. Conventions that live only in a document are followed until the
first deadline. Layering rules in particular are invisible in a diff: nothing
about `use App\Infrastructure\...` inside a `Domain` class looks wrong on the
screen.

## Decision

Five gates, all blocking in CI:

1. **Pint** with the `laravel` preset plus `declare_strict_types`,
   `strict_comparison` and `strict_param`.
2. **Rector** in `--dry-run`. A failure means the code has drifted from the
   idioms the rest of the platform uses; it is also the upgrade path across
   PHP and Laravel majors. Rules that conflict with house style are skipped
   explicitly in `rector.php` with a reason.
3. **PHPStan level 8** via Larastan, with `checkModelProperties`,
   `treatPhpDocTypesAsCertain: false` and `reportUnmatchedIgnoredErrors`, so a
   stale ignore fails the build.
4. **Pest**, including architecture tests that enforce the layering rules in
   ADR 0001 and forbid debug helpers and provider SDKs in tests.
5. **Front end**: ESLint, Prettier, `vue-tsc --noEmit` and Vitest.

Tests run against **MariaDB**, never SQLite. Collation, JSON functions and
foreign-key behaviour differ enough that a green SQLite suite would not tell
us the product works.

`tests/` is excluded from PHPStan: it resolves `$this` inside a Pest closure
to `Pest\PendingCalls\TestCall` rather than to the bound `TestCase`, producing
false "undefined method" errors on every HTTP assertion. The test suite is
covered by Pint, Rector and its own architecture tests instead. This is
revisited when Pest ships a PHPStan scope extension.

## Consequences

- The gates are strict enough that they occasionally need an explicit,
  commented exemption. That is preferable to a permissive baseline nobody
  reads.
- Coverage thresholds are not enforced in Phase 0 because no coverage driver
  is installed on the build host; CI installs pcov and enforces a minimum.
