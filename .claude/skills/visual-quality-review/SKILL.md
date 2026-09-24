---
name: visual-quality-review
description: The internal UI reviewer for InfraCMS. Run it before declaring ANY significant frontend change done — a new or redesigned page, a changed primitive, a layout or token change. Builds the app, inspects the rendered screens in the browser at 1920/1440/1366/1280 widths in light and dark, walks every state, checks against the design system, then fixes what it finds. Also use when asked to "review the UI", "polish", "QA the frontend" or when a screen "looks off".
---

# Visual quality review

Compilation is not completion. A frontend task is done when the **rendered**
screen has been looked at, compared with the design system, and fixed.

## Workflow

1. **Purpose.** State in one sentence what an operator does on this screen.
   Every finding is judged against that.
2. **Gates.** From `infracms/`:
   `npm run typecheck && npm run lint && npm run test:unit && npm run build`.
   (The app is served by Herd at `http://infracms.test`; built assets are what
   it serves unless `public/hot` exists.)
3. **Static sweep** of the files you touched:
   ```bash
   grep -nE "text-(xs|sm|base|lg|[2-9]?xl)\b|text-\[[0-9]|#[0-9a-fA-F]{3,8}\b|shadow-(sm|md|lg|xl|2xl)\b|rounded-(2xl|3xl)|rounded-\[var|bg-gradient|backdrop-blur|font-bold|bg-(white|black)|(bg|text|border)-(red|green|blue|amber|yellow|gray|slate)-[0-9]" <files>
   grep -n "AppBadge" <files>     # each one: is it a status? then it must be AppStatus
   grep -n "<AppCard" <files>     # each one: would a DetailSection do?
   grep -nE "class=\"[^\"]*(px|py|p)-[0-9.]+[^\"]*\"" <files> | grep "<td"   # table cells should not pad themselves
   ```
4. **Render.** Load the `claude-in-chrome` skill (or `browser-skills:ego-browser`),
   open the screen, and take screenshots. The user's Chrome is normally signed
   in to the admin; never enter credentials yourself.
5. **Widths.** Test **1920×1080, 1440×900, 1366×768, 1280×800**. The OS
   window may not resize below the display; emulate with CSS zoom, which is
   faithful here because the app uses no `2xl:` breakpoint:
   ```js
   document.documentElement.style.zoom = innerWidth / 1280   // then screenshot
   ```
   (The app sends frame-blocking headers, so iframes of other widths do not
   work.) Below `lg` (tablet/phone) needs real resizing or DevTools device
   mode — say so if you could not test it.
6. **Themes.** Check dark and light without changing the operator's saved
   preference: `document.documentElement.dataset.theme = 'light'` (or `'dark'`).
7. **States.** Walk: hover a row, focus with Tab (is the ring visible?), open
   every menu and dialog (in-app dialogs are safe to open and Escape — never
   press a confirming button on real data), empty list (filter to nothing),
   long values (truncation), a disabled control, validation errors, loading.
   Use URL params (`?tab=`, filters) to reach states quickly.
8. **Review** against the checklist below. Write findings as
   `where — what is wrong — fix`.
9. **Fix** everything that is wrong, rebuild, re-screenshot the affected area.
   Do not report a problem you could have fixed.
10. **Accessibility and responsive** pass with those skills' checklists.
11. Only then call the task complete, listing what was verified and anything
    that could not be (e.g. tablet widths).

## Checklist

**Structure & hierarchy**
- One `h1`; title → meta → actions band is intact; the four questions
  (where / what / what matters / what can I do) are answered above the fold.
- Page → section → content. No card in a card. Every frame means something.
- The most important thing is the most prominent thing (attention/failures
  before totals; the primary action is the only brand-filled button).

**Alignment & spacing**
- Left edges line up down the page (header, filters, table, sections).
- Controls in one row share a height (all `--control-h`) and baseline.
- Section gaps `gap-8`, in-section `gap-4/5`; no stray `mt-7`, `p-[13px]`.
- No large dead zones; no content running full width that should be
  constrained (prose ≤ 80ch, label columns ≤ 12rem).

**Typography**
- Only `text-page/title/body/chrome/label`; weights 500/600.
- Identifiers in mono; figures tabular and right-aligned in tables.
- Muted/subtle text is still readable (contrast, see accessibility).

**Density**
- Table rows ~32px at default density; a 1366×768 screen shows ≥ 12 rows
  of a list.
- No oversized KPI numbers, heroes, or padding-heavy panels.

**Colour**
- Neutral surfaces; brand only for primary action/selection/links/focus;
  status colours only on statuses; no two greens.
- Dark mode: borders visible, hover and selected rows distinguishable,
  status marks readable, inputs visibly bordered.
- Light mode: native controls light (checkboxes, selects, date pickers).

**Components & states**
- Status shows shape + word (`AppStatus`), never a coloured chip alone.
- Buttons: secondary buttons have a visible edge; one primary per context;
  destructive entry is `danger-subtle`, solid red only in the confirm.
- Hover, active, focus-visible, disabled, selected look distinct.
- Empty, loading, error, permission-denied, partial states exist and are
  distinguishable from each other.
- Menus/popovers are not clipped by table overflow (they use
  `useAnchoredPanel`).
- Icons: Phosphor regular, sizes 12/14/16(/20), consistent within a row.

**Tables**
- Identity column first and linked; status second; numeric columns
  right-aligned; header sticky; no empty columns of `—` by default (make
  them optional/offByDefault).
- Row actions are hidden until hover/focus (not a wall of buttons).
- Horizontal overflow scrolls inside the table frame, not the page.

## Output

Report: screens reviewed × widths × themes × states, findings fixed (with the
file), findings deliberately left (with the reason), and what could not be
verified. Save before/after screenshots only if the user wants them.
