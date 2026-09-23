# 0038 — A module may execute, and enabling is the moment it does

- **Status:** Accepted
- **Date:** 2026-09-23
- **Phase:** 12 — Module SDK
- **Supersedes:** nothing
- **Related:** [0037 — A theme is a package and may not execute](0037-a-theme-is-a-package-and-may-not-execute.md)

## Context

Phase 11 gave the platform themes, and drew a hard line: a theme is markup
and settings, raw PHP in a template is refused at install, by file, and a
theme that needs behaviour is a module rather than a theme.

Phase 12 is the other side of that line. A module exists precisely to run
code — a payment gateway that talks to a provider, a registrar that speaks
EPP, a tax calculator that knows a country's rules. Refusing execution here
would refuse the feature.

So the question is not *whether* somebody else's code runs. It is **when**,
**on whose say-so**, and **what it is allowed to reach**.

Three things pushed the answer around while this was being written:

1. The phase plan had `InstallModule` run the module's migrations and sync
   its permissions, and described that as "not consent yet". That is false.
   A migration is a PHP class the module author wrote. Running one *is*
   running the package. Consent cannot be split across two steps where the
   earlier one already executes the code.

2. A manifest has to be read before anybody has decided to trust the
   package — it is what the decision is made *from*. A manifest that is a
   PHP file returning an array runs the package at the moment the platform
   first looks at it, which is the decision the install screen exists to put
   in front of an operator.

3. Uninstall has to be able to refuse. "This module still provides the
   gateway three subscriptions are paying through" is an answer an operator
   needs *before* the module is gone — and asking the package itself would
   mean loading a package somebody is trying to remove.

## Decision

**A module may execute. Enabling is the one moment it does, and that moment
is audited.**

Concretely:

- **The manifest is JSON.** `module.json` is read by `ModuleCatalogue`,
  which reads files and does nothing else: it never loads a class, never
  calls a line of a package and never decides anything is trustworthy. An
  operator opening the modules screen has, by that act, agreed to nothing.

- **Installing writes a row.** `InstallModule` reads the manifest, checks it
  fits this platform and this SDK, and records it. Nothing of the package
  runs. A refusal leaves no trace — the compatibility check happens before
  the row, not after.

- **Enabling runs it, in an order where each step is a reason to stop
  before the next.** `EnableModule` checks dependencies are enabled, checks
  the configuration is complete, runs the module's migrations, constructs
  the entrypoint, **inspects what it returned before any of it reaches a
  registry**, boots it, and writes down what it registered.

- **`ModuleLoader` is the only place a module's PHP enters the process.**
  It registers the autoload prefix itself rather than through
  `composer.json`, because `composer.json` is the platform's and a module is
  not — an operator installing one must not have to run
  `composer dump-autoload` as root on a production box.

- **What was registered is stored on the row.** A `Registration` value
  object, written at enable time, so that uninstall can refuse — "this
  module provides the gateway on three live subscriptions" — **without
  loading the package** it is being asked to remove.

- **Anything thrown leaves the module `failed`, with the reason.** Not
  `enabled`, not half-registered, not silent. One bad package must not take
  an installation down, and an operator who gets "it did not work" with no
  sentence attached reinstalls it three times.

- **The type is checked against what the module registers.** A package that
  declares itself a report and registers a payment gateway is refused. The
  type is the one thing most operators will actually read, so a package that
  can quietly become something else is a package whose type means nothing.
  `addon` is the honest escape hatch and says so.

- **`platform.modules.enabled => false` is a real setting.** An installation
  that has decided no third-party code runs on it says so once, in config,
  rather than by an operator remembering not to press a button.

## Consequences

An operator can see what a package claims to be, what it says it will
register, and which SDK it was built against, all before anything of it has
run. That is the whole value of the split, and it costs a manifest format
that cannot express anything clever.

A module cannot do something core has not anticipated. There is no service
provider handed over and no container: a module cannot register middleware,
replace a binding, add a global scope or reorder the pipeline, because it is
never given the chance to try. An extension point added later is a normal
change; an extension point that turned out to mean "anything at all" cannot
be taken back once modules depend on it.

Enabling is slower than installing, and visibly so — it runs migrations and
constructs code. That is correct. The screen should feel like a decision.

A module that fails leaves a row saying so. That row is the bug report.
