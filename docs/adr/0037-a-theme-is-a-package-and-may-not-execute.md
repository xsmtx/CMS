# 0037 — A theme is a package, and it may not execute

**Status:** accepted
**Date:** 2026-09-23
**Supersedes:** nothing. Constrains what Phase 12 modules may be used for.

## Context

The handoff asks for theme packages with a four-level override chain:

```text
installation override -> child theme -> parent theme -> core fallback
```

Two questions have to be answered before any of that can be built, and
both have an obvious wrong answer.

**What does "override" mean?** The tempting reading is *merge*: take the
header from the child, the footer from the parent, the rest from core. It
sounds helpful and it produces theme bugs nobody can reason about — a
template that half-exists, rendered from two files neither of whose authors
saw the other.

**What may a theme contain?** The tempting answer is "whatever it needs",
which is what WHMCS answers: its template files are PHP. That makes
"install this free theme" a remote-code-execution vector, and it is a known
one. A theme is content an operator downloads from somebody they have never
met.

## Decision

**A theme is a package of templates, assets, translations and a JSON
manifest. It may not contain PHP that runs.**

1. **Templates resolve; they do not merge.** A template is found at the
   first level that has it, whole. The chain is registered as **view paths
   in precedence order**, which is exactly Laravel's own semantics for a
   namespaced view — and it means **no controller changes at all**. Every
   storefront controller already renders `storefront::catalog`; pointing
   that namespace at four directories instead of one is the entire feature.

2. **Settings *do* merge.** A child theme that wants to change one colour
   should not have to restate twenty. Settings are data; templates are
   documents. The distinction is the whole rule.

3. **Raw PHP is refused at install, by file.** `<?php`, `<?=` and `<%` in a
   theme template mean the theme is not installed, and the operator is told
   which file — a refusal that does not say why is a refusal somebody works
   around by trying a different theme. Blade directives are fine: they
   compile with the same escaping and the same restrictions as any other
   view in this application.

4. **A theme that needs behaviour is a module, not a theme.** Phase 12's
   modules are a different trust decision with a different review. Keeping
   the two apart is what lets an operator install a theme without reading
   it and install a module only after somebody has.

5. **The manifest is JSON**, not a PHP file that returns an array. A
   manifest that executes is the same hole through a smaller door.

6. **A theme is refused when it does not fit**: a compatibility range that
   excludes this platform version, a parent that is not installed, a slug
   that is a path. A manifest that will not parse is **skipped** rather
   than thrown — one bad file an operator dropped in a directory must not
   take the storefront down.

7. **The installation override lives outside the theme directory.** An
   operator changing one line of one template should not have to fork a
   theme, and their change has to survive that theme being upgraded.

8. **The core templates are themselves a theme.** They moved into
   `themes/storefront/core`, which is the parent every other storefront
   theme inherits from. That proves the chain rather than leaving it
   theoretical: if core were special, the first child theme anybody wrote
   would discover it.

## Consequences

A theme author cannot write logic. That is the point, and it is a real
constraint: anything a template needs must already be in the data the
controller passes, or it does not happen. In practice this pushes work into
presenters, where it is testable, rather than into templates, where it is
not.

The fallback is always available. A theme chosen and then deleted from disk
leaves the storefront on core rather than on a white page, because the last
path in the chain is always there.

`ThemeRegistry::FALLBACK` is `'core'` and the chain is resolved per request
and memoised — a page renders several views and each one asks which theme
is active.
