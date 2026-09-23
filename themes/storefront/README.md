# Storefront themes

Each directory here is a theme. `core` is the one this platform ships and
the parent every other storefront theme should declare.

```text
themes/storefront/<slug>/
  theme.json        the manifest — JSON, never PHP
  views/            Blade templates, overriding the parent's by filename
  assets/theme.css  loaded after the core stylesheet
```

A template is resolved at the **first level that has it, whole**:

```text
installation override  ->  child theme  ->  parent theme  ->  core
```

So a theme that wants a different header copies `layout.blade.php` and
changes it. It does not get to splice one part of a template out of
another — that produces bugs nobody can reason about.

**Settings are the exception: they merge.** A child theme that wants to
change one setting does not restate its parent's.

## What a theme may not contain

Raw PHP. `<?php`, `<?=` and short tags are refused at install time with the
file named. A theme is content an operator downloads; a theme that can
execute is a remote-code-execution feature with a friendly name.

Blade's own directives — `@if`, `@foreach`, `@include` — are fine: they
compile under the same escaping and the same restrictions as any other view
in this application.

If a theme genuinely needs new behaviour, that is a **module**, which is a
different trust decision with a different review.

## Making a child theme

```json
{
  "slug": "aurora",
  "name": "Aurora",
  "version": "1.0.0",
  "parent": "core",
  "compatibility": ">=1.0",
  "surfaces": ["storefront"],
  "settings": { "footer_note": "Hosted in Frankfurt" }
}
```

Copy only the templates you change. Everything else falls through to
`core`, which means a core upgrade reaches your theme for free.
