/**
 * Drive every page, in both appearances, and say what is wrong with it.
 *
 * `visual-quality-review` says compilation is not completion, and until now
 * the only way to honour that was a person opening screens one at a time.
 * This does the mechanical half: it visits every parameterless page as a
 * signed-in operator, captures it light and dark at the four widths the
 * responsive skill names, and runs axe against each one.
 *
 * It does **not** decide whether a screen looks right. Contrast, focus rings
 * and missing labels are machine-checkable and that is what axe is for; "the
 * heading says the same thing twice" is not, and never will be.
 *
 * Credentials come from the environment and are never written down here:
 *
 *   DESIGN_EMAIL=… DESIGN_PASS=… node tools/design-review.mjs
 *
 * Optional: DESIGN_BASE (default http://infracms.test), DESIGN_OUT,
 * DESIGN_WIDTHS, DESIGN_ONLY (a substring filter over the paths), and
 * DESIGN_PORTAL=1 with DESIGN_CLIENT_EMAIL / DESIGN_CLIENT_PASS to drive the
 * client area instead of the admin.
 */
import { chromium } from 'playwright'
import AxeBuilder from '@axe-core/playwright'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import os from 'node:os'

const BASE = process.env.DESIGN_BASE ?? 'http://infracms.test'
const OUT = process.env.DESIGN_OUT ?? path.join(os.tmpdir(), 'infracms-design')
const EMAIL = process.env.DESIGN_EMAIL
const PASS = process.env.DESIGN_PASS

/** Sign in to the client area rather than the admin, for the portal's screens. */
const PORTAL = process.env.DESIGN_PORTAL === '1'

/**
 * The widths `responsive-enterprise-ui` names. The first is the one every
 * page is captured at; the rest are checked for horizontal overflow only,
 * because 86 pages times 4 widths times 2 appearances is 688 screenshots
 * nobody will look at.
 */
const WIDTHS = (process.env.DESIGN_WIDTHS ?? '1440,1280,1366,1920')
  .split(',')
  .map((w) => Number.parseInt(w, 10))

/** Pages that are a redirect, a download, or a dead end for a signed-in operator. */
const SKIP = new Set([
  '/admin/login',
  '/admin/forgot-password',
  '/admin/two-factor-challenge',
  // Correctly a 404 until enrolment has begun: the setup page exists only
  // between starting two-factor and confirming it.
  '/admin/security/two-factor',
  '/security/two-factor',
  '/login',
  '/register',
])

const PATHS = JSON.parse(process.env.DESIGN_PATHS ?? '[]').filter(
  (p) => !SKIP.has(p) && (!process.env.DESIGN_ONLY || p.includes(process.env.DESIGN_ONLY)),
)

const report = { base: BASE, generatedAt: new Date().toISOString(), pages: [] }

/**
 * Sign in, to whichever area the run is about.
 *
 * The portal cannot be reached from a staff session: the guard is resolved
 * per area and only one account is signed in on each, so `/client` answers
 * with its own sign-in screen - correct of the product, and useless as a
 * screenshot.
 *
 * An operator drives the portal through impersonation, which is right for a
 * person and poor for a script: the control is a row action on a contact,
 * revealed on hover, inside a menu, behind a confirmation. A run that has to
 * find it breaks the first time somebody moves it. So the tool signs in as a
 * contact instead, with credentials the caller passes - which for a review
 * pass means a throwaway account created for the run and deleted after it.
 */
async function signIn(page) {
  const email = PORTAL ? process.env.DESIGN_CLIENT_EMAIL : EMAIL
  const password = PORTAL ? process.env.DESIGN_CLIENT_PASS : PASS

  if (!email || !password) {
    throw new Error(
      PORTAL
        ? 'DESIGN_CLIENT_EMAIL and DESIGN_CLIENT_PASS are required for the portal.'
        : 'DESIGN_EMAIL and DESIGN_PASS are required.',
    )
  }

  await page.goto(`${BASE}${PORTAL ? '/login' : '/admin/login'}`, {
    waitUntil: 'domcontentloaded',
  })
  await page.fill('input[type="email"]', email)
  await page.fill('input[type="password"]', password)
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 }),
    page.click('button[type="submit"]'),
  ])
}

/*
 * The appearance comes from the context's `colorScheme`, which sets
 * `prefers-color-scheme` - not from writing the theme preference into
 * storage. An init script would be an inline script, and this product's CSP
 * is `script-src 'self'` with no exceptions on purpose: the browser refuses
 * it, the console fills with the refusal, and the report reads as though the
 * application were broken.
 */

async function capture(page, target, theme, width) {
  const file = path.join(
    OUT,
    `${target.replace(/\W+/g, '-').replace(/^-|-$/g, '') || 'home'}-${theme}-${width}.png`,
  )

  await page.screenshot({ path: file, fullPage: true })

  return file
}

const browser = await chromium.launch()
await mkdir(OUT, { recursive: true })

for (const theme of ['light', 'dark']) {
  const context = await browser.newContext({
    viewport: { width: WIDTHS[0], height: 900 },
    colorScheme: theme,
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
  })

  const page = await context.newPage()
  const consoleErrors = []
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text())
  })

  await signIn(page)

  for (const target of PATHS) {
    const entry = { path: target, theme, violations: [], overflow: [], console: [], status: null }
    consoleErrors.length = 0

    try {
      const response = await page.goto(`${BASE}${target}`, {
        waitUntil: 'networkidle',
        timeout: 30000,
      })

      entry.status = response?.status() ?? null

      if (entry.status && entry.status >= 400) {
        report.pages.push(entry)
        continue
      }

      entry.screenshot = await capture(page, target, theme, WIDTHS[0])

      const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze()

      entry.violations = axe.violations.map((violation) => ({
        id: violation.id,
        impact: violation.impact,
        help: violation.help,
        nodes: violation.nodes.slice(0, 4).map((node) => ({
          target: node.target.join(' '),
          summary: node.failureSummary?.split('\n').slice(0, 3).join(' ').trim(),
        })),
      }))

      // A page that scrolls sideways at a laptop width is the single most
      // common way a dense layout breaks, and it is invisible in a full-page
      // screenshot because the shot is as wide as the content.
      for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 })
        const overflows = await page.evaluate(
          () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        )
        if (overflows) entry.overflow.push(width)
      }
      await page.setViewportSize({ width: WIDTHS[0], height: 900 })

      entry.console = [...consoleErrors]
    } catch (error) {
      entry.error = String(error).split('\n')[0]
    }

    report.pages.push(entry)
    process.stdout.write(
      `${theme} ${target} ${entry.status ?? 'ERR'} a11y:${entry.violations.length} overflow:${entry.overflow.join(',') || '-'}\n`,
    )
  }

  await context.close()
}

await browser.close()
await writeFile(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2))

const totals = report.pages.reduce(
  (acc, page) => {
    acc.violations += page.violations.length
    acc.overflow += page.overflow.length > 0 ? 1 : 0
    acc.errors += page.error || (page.status ?? 200) >= 400 ? 1 : 0
    return acc
  },
  { violations: 0, overflow: 0, errors: 0 },
)

console.log(
  `\n${report.pages.length} page renders · ${totals.violations} accessibility violations · ${totals.overflow} overflowing · ${totals.errors} failed`,
)
console.log(`report: ${path.join(OUT, 'report.json')}`)
