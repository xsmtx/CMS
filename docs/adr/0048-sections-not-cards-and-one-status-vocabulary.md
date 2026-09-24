# 0048 — Sections, not cards; one status vocabulary; skills govern the UI

- **Status:** Accepted
- **Date:** 2026-09-24
- **Handoff:** #3 §0, §6, §7, §8, §14
- **Related:** [0040 — The UI is primitives, and the tokens are the API](0040-the-ui-is-primitives.md)

## Context

ADR 0040 chose owned primitives and made the tokens public API. A browser
audit on 2026-09-24 (`docs/design/audit-2026-09-24.md`) found that the
primitives were sound but the screens built from them drifted:

- every region of a screen was an `AppCard`, so the dashboard and detail
  pages read as a grid of equal boxes with nothing more important than
  anything else;
- 25 pages mapped status words to colours in their own `tone()` function and
  disagreed with each other, and rendered status as a colour-tinted badge —
  colour alone, which §7 forbids;
- 578 uses of Tailwind's default `text-xs`/`text-sm` bypassed the type scale;
  labels were 14px over 13px body text;
- inputs (~42px) and buttons (~38px) had no shared height, and the density
  tokens existed but no component read them;
- three tokens referenced in `app.css` (`--color-surface`, `-sunken`,
  `-raised`) did not exist, so the page background, row hover and sticky
  table header silently had no colour.

The rules that would have prevented this lived in a handoff and a design
document that a new session does not necessarily read before writing markup.

## Decision

1. **Sections, not cards.** A page is Page → Section → Content. The default
   grouping is `DetailSection` (heading + hairline, no box). A framed surface
   is reserved for objects (tables, the metric strip, the danger zone, a
   filter panel), and framing is at most one level deep. In-flow surfaces are
   flat; only floating layers carry a shadow.
2. **One status vocabulary.** Status words map to tones in
   `resources/js/status.ts` only; screens call `statusTone()` and render
   `AppStatus`. `AppBadge` is for labels that are not a status. Two tones
   were added: `info` (moving) and `neutral` (out of play).
3. **One control height, density that works.** `--control-h`/`--control-h-sm`
   size every control; `--row-y` pads every table cell through a
   zero-specificity rule; both move with `data-density`.
4. **The type scale is the only type scale.** `text-page/title/body/chrome/label`.
5. **Destructive actions are entered quietly and confirmed loudly.** Entry
   points use `danger-subtle`; solid `danger` is the final press inside
   `AppConfirm`; destructive actions live in the Danger Zone, never in a
   page header.
6. **Claude Code skills govern frontend work.** Six project skills in
   `.claude/skills/` (`enterprise-design-system`, `enterprise-cms-ux`,
   `frontend-architecture`, `visual-quality-review`, `accessibility`,
   `responsive-enterprise-ui`) carry these rules and a mandatory rendered-UI
   review. They apply the handoff and `docs/design/design-system.md`; they do
   not override them.

## Consequences

- Colours changed on screens where the old per-page mappings were wrong
  (e.g. a refunded invoice is neutral, not green; an expired domain is
  critical everywhere). That is the point, and it is listed in the audit.
- Dashboard, Clients list and Client detail are the reference
  implementations. Other screens still use `AppCard` for grouping and
  hand-padded table cells; they follow as they are touched, per the audit's
  propagation list.
- New tokens (`--control-h*`) and changed defaults (`--row-y` 10→8px) are
  visible to third-party themes. Both are additive for a theme that does not
  set them.
