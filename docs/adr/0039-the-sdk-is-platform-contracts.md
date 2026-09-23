# 0039 — The SDK is platform contracts, versioned apart from the platform

- **Status:** Accepted
- **Date:** 2026-09-23
- **Phase:** 12 — Module SDK
- **Supersedes:** nothing
- **Related:** [0038 — A module may execute](0038-a-module-may-execute.md)

## Context

A module written by somebody else has to be able to survive the platform
changing. Two things make that hard, and they are different problems:

- **What a module is handed.** If a module receives an Eloquent model, then
  a column rename in core breaks a package its author cannot see. If it
  receives the container, every internal binding becomes public API by
  accident, discovered only when somebody upgrades.

- **When a module should refuse.** The product will release all year without
  the extension surface moving. When the surface *does* move, a module built
  against the old one must say so — loudly, at install time — rather than
  break at the moment a customer is waiting.

The second is the reason a single version number is not enough. Tying a
module to "platform 2.4" means either refusing modules that would have
worked fine, or accepting modules that will not.

## Decision

**The SDK is the set of contracts in `app/Domain`, and it carries its own
version.**

### What a module is handed

Everything on the `Module` interface is a **platform contract**. No method
takes or returns an Eloquent model, a facade or a framework class.
`app/Domain` has no framework imports at all and an architecture test
enforces it — which is what makes it possible to change how any of this is
stored without breaking a module somebody else wrote.

The interface is **declarative**: a module answers questions and core does
the wiring. `gateways()`, `registrars()`, `healthChecks()`, `permissions()`,
`configSchema()`. Every method returns a list, `BaseModule` returns empty
ones, and so a module that provides one gateway implements one method.

A module never renders. `configSchema()` returns `ConfigField` objects and
core draws the form, validates it, encrypts the secrets and stores them — a
module that drew its own form would be a module that could draw anything.

`ModuleContext` is what a module gets at boot: its own configuration and its
own logger, and nothing else. Not the container, not the request, not a
database connection.

### The version

`Sdk::VERSION` is separate from the platform's version, and a manifest
declares a range against each. They fail for different reasons and an
operator needs to know which: `platform` is "this was built for a different
version of the product", `sdk` is "this was built against a different set of
contracts".

The rule for changing it:

- **Adding** a method to `Module` with a default in `BaseModule`, or adding
  a member to an enum modules only read, is a **minor** bump.
- Changing or removing anything a module implements or calls is a **major**
  one.

A major bump is a decision, not a consequence. It makes every existing
module refuse until its author has looked, which is the point.

### Refusing

A range that cannot be parsed is refused rather than assumed permissive.
"Nobody can read this" and "your platform is too old" send an operator to
different places, and a range nobody can read is a typo in a file somebody
is about to trust with code execution.

Compatibility is checked **before construction**, while the manifest is
still only a string in a JSON file.

## Consequences

`app/Domain` is now public API. A change there is a change somebody else's
package can see, and the only honest way to make a breaking one is to bump
`Sdk::VERSION` and let every module refuse. That is a real constraint on
core and it is meant to be felt.

`BaseModule` is the one class in `app/Domain` that is not `final`, and the
architecture test names it as the exception. It exists to be subclassed by
code this repository does not contain; `final` would make the SDK unusable.

A module cannot reach anything core has not put on a contract. Authors will
ask for more, and the answer is a new extension point rather than a hole —
`ExtensionPoint` exists so "what does this package actually do" has an
answer that can be shown to an operator and checked against the type it
claims.

The SDK version will move slower than the product, which is the intent: the
value of a stable extension surface is entirely in how rarely it changes.
