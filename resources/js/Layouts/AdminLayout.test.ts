import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

/**
 * The admin menu is the one piece of this panel nobody can be trained out
 * of: an operator arriving from WHMCS reaches for Billing, and Billing has
 * to be where they reach. These tests exist so that a later refactor cannot
 * quietly rename or re-nest a group.
 *
 * The rail has two shapes and both are covered, because they are where a
 * sidebar actually goes wrong: collapsed it must open a flyout (72px has
 * nowhere to put a nested list), expanded it must open in place and stay
 * open when somebody clicks the page.
 *
 * The collapsed flyout is **teleported to the body**, so the assertions
 * about it read `document` rather than the wrapper. That is the whole point
 * of it: a panel still inside the bar is a panel the bar's own `overflow`
 * cuts off at 72px, which is exactly the bug this shape shipped with.
 */
const navigateHandlers: Array<() => void> = []

/**
 * Apps and Integrations is the one area gated by who somebody is rather
 * than by what they may do, so the mock has to be able to say both.
 */
const permissionState = { superAdmin: true }

vi.mock('@inertiajs/vue3', () => ({
  Link: {
    props: ['href', 'method', 'as'],
    template: '<a :href="href"><slot /></a>',
  },
  router: {
    on: (event: string, handler: () => void) => {
      if (event === 'navigate') navigateHandlers.push(handler)

      return () => {}
    },
  },
  usePage: () => ({
    props: {
      // The full brand shape, because the shell writes its colours onto
      // the document on mount. A trimmed fixture would pass here and fail
      // in a browser.
      brand: {
        name: 'InfraCMS',
        legalName: null,
        portalName: 'InfraCMS',
        supportEmail: null,
        supportPhone: null,
        websiteUrl: null,
        logoUrl: null,
        logoDarkUrl: null,
        faviconUrl: null,
        legalLinks: [],
        hideVendorMark: false,
        css: {},
      },
      flash: {},
      auth: { user: { email: 'operator@example.test' } },
      help: {
        documentation: 'https://docs.example.test',
        bug: 'https://bugs.example.test',
        contact: 'https://example.test/contact',
        license: 'https://example.test/licence',
      },
    },
    url: '/admin/invoices',
  }),
}))

vi.mock('../composables/usePermissions', () => ({
  // Everything visible, so the tests are about the map rather than about
  // one role's slice of it.
  //
  // A real `ref`, not `{ value }`: Vue unwraps a ref in a template and
  // leaves a plain object alone, so `v-if="isSuperAdmin"` on the latter is
  // always truthy — which is how this mock passed while hiding nothing.
  usePermissions: () => ({
    can: () => true,
    isSuperAdmin: ref(permissionState.superAdmin),
  }),
}))

const { default: AdminLayout } = await import('./AdminLayout.vue')

function render() {
  return mount(AdminLayout, {
    props: { heading: 'Invoices' },
    global: { stubs: { ThemeSwitch: true, AppAlert: true } },
  })
}

/** The rail's own groups, in the order the rail draws them. */
function groupsOf(wrapper: ReturnType<typeof render>) {
  return wrapper.findAll('[data-admin-nav] nav ul > li')
}

function groupTriggers(wrapper: ReturnType<typeof render>) {
  return wrapper.findAll('[data-admin-nav] nav ul > li > button')
}

async function expandRail(wrapper: ReturnType<typeof render>): Promise<void> {
  await wrapper.find('[data-admin-nav] [data-rail-toggle]').trigger('click')
}

/** The teleported flyout, wherever in the document it landed. */
function flyout(): HTMLElement | null {
  return document.querySelector('[data-rail-flyout]')
}

function flyoutLink(href: string): Element | null {
  return flyout()?.querySelector('a[href="' + href + '"]') ?? null
}

describe('AdminLayout navigation', () => {
  // A teleported panel is appended to the body and outlives the wrapper
  // that made it, so one test's flyout would still be in the document when
  // the next one asked.
  enableAutoUnmount(afterEach)

  afterEach(() => {
    document.body.innerHTML = ''
  })

  beforeEach(() => {
    navigateHandlers.length = 0
    permissionState.superAdmin = true
    window.localStorage.clear()
  })

  it('lists the WHMCS groups in WHMCS order, under the handoff sections', async () => {
    const wrapper = render()

    expect(groupTriggers(wrapper).map((button) => button.text().trim())).toEqual([
      'Clients',
      'Orders',
      'Billing',
      'Support',
      'Utilities',
      'Setup',
    ])

    // The handoff's categories are the headings those groups sit under, which
    // is how WHMCS's words and §3's structure can both be true.
    await expandRail(wrapper)

    const sections = wrapper
      .findAll('[data-admin-nav] nav p')
      .map((heading) => heading.text().trim())

    expect(sections).toEqual(['Business', 'Support', 'System'])
  })

  /**
   * Collapsed is the default: 72px of icons, and the width goes to the data.
   * An operator who wants labels presses once and it is remembered.
   */
  it('ships collapsed and remembers being opened', async () => {
    const wrapper = render()

    expect(wrapper.find('[data-rail="closed"]').exists()).toBe(true)

    await expandRail(wrapper)

    expect(wrapper.find('[data-rail="open"]').exists()).toBe(true)
    expect(window.localStorage.getItem('admin.rail')).toBe('open')

    // A second shell, mounted fresh, finds the preference.
    expect(render().find('[data-rail="open"]').exists()).toBe(true)
  })

  it('keeps every group shut until it is asked for', () => {
    render()

    expect(flyout()).toBeNull()
    expect(document.querySelector('a[href="/admin/customers"]')).toBeNull()
  })

  /**
   * The bug this shape shipped with: the flyout was `absolute` inside a
   * `nav` that scrolls, so it opened *inside* a 72px bar and was clipped at
   * its edge. Teleporting it to the body is what makes it impossible for an
   * ancestor's `overflow` to cut it off, so that is what is asserted — not a
   * class name, which would have passed the whole time the menu was
   * invisible.
   */
  it('opens the collapsed flyout outside the bar, not inside it', async () => {
    const wrapper = render()

    await groupTriggers(wrapper)[0]?.trigger('click')

    const panel = flyout()

    expect(panel).not.toBeNull()
    expect(panel?.closest('[data-admin-nav]')).toBeNull()
    expect(panel?.style.position).toBe('fixed')
  })

  /**
   * The other half of the same report: the control was at the foot of a list
   * long enough to scroll, which made it findable only by somebody who
   * already knew it was there. It belongs in the header, at both widths.
   */
  it('puts the collapse control in the rail header, at both widths', async () => {
    const wrapper = render()
    const toggles = wrapper.findAll('[data-admin-nav] [data-rail-toggle]')

    expect(toggles).toHaveLength(1)
    expect(toggles[0]?.attributes('aria-label')).toBe('Expand the sidebar')

    // Above the scrolling list rather than after it.
    const nav = wrapper.find('[data-admin-nav] nav').element
    const toggle = toggles[0]?.element as HTMLElement

    expect(nav.compareDocumentPosition(toggle) & Node.DOCUMENT_POSITION_PRECEDING).toBeTruthy()

    await expandRail(wrapper)

    expect(wrapper.find('[data-admin-nav] [data-rail-toggle]').attributes('aria-label')).toBe(
      'Collapse the sidebar',
    )
  })

  it('opens a group on click and closes it when another opens', async () => {
    const wrapper = render()
    const buttons = groupTriggers(wrapper)

    await buttons[0]?.trigger('click')
    expect(flyoutLink('/admin/customers')).not.toBeNull()

    // A second group replaces the first rather than joining it. One panel,
    // so this also asserts a second one is never left hanging about.
    await buttons[1]?.trigger('click')
    expect(document.querySelectorAll('[data-rail-flyout]')).toHaveLength(1)
    expect(flyoutLink('/admin/customers')).toBeNull()
    expect(flyoutLink('/admin/orders')).not.toBeNull()
  })

  it('shuts the flyout when the same group is pressed again', async () => {
    const wrapper = render()

    await groupTriggers(wrapper)[0]?.trigger('click')
    expect(flyout()).not.toBeNull()

    await groupTriggers(wrapper)[0]?.trigger('click')
    expect(flyout()).toBeNull()
  })

  it('shuts the rail and its group when the page navigates', async () => {
    const wrapper = render()

    await groupTriggers(wrapper)[0]?.trigger('click')
    expect(flyoutLink('/admin/customers')).not.toBeNull()

    // A panel still hanging over the page it just went to is the commonest
    // way navigation feels broken.
    navigateHandlers.forEach((handler) => handler())
    await wrapper.vm.$nextTick()

    expect(flyout()).toBeNull()
  })

  it('marks the group the current page belongs to', () => {
    const groups = groupsOf(render())

    // `/admin/invoices` lives under Billing, so Billing reads as the one you
    // are on even though it is shut.
    const marked = groups.filter((group) => group.find('[class*="bg-surface-selected"]').exists())

    expect(marked).toHaveLength(1)
    expect(marked[0]?.text()).toContain('Billing')
  })

  /**
   * Expanded, the group you are in is already open. A rail that made somebody
   * press their own section to see where they are is a rail that forgot.
   */
  it('opens the current group when the rail is already expanded', async () => {
    window.localStorage.setItem('admin.rail', 'open')

    const wrapper = render()

    // `onMounted` decides this, so the render that shows it is the next one.
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-admin-nav] a[href="/admin/invoices"]').exists()).toBe(true)
  })

  /**
   * Two levels, and the second opens beside the row that owns it rather than
   * pushing the rows below it down.
   */
  it('opens a submenu without moving what was under it', async () => {
    const wrapper = render()

    window.localStorage.setItem('admin.rail', 'open')

    const expanded = render()

    await groupTriggers(expanded)[0]?.trigger('click')

    // The parent is a link in its own right: an operator who wanted the whole
    // list should not have to pick a filter first.
    expect(expanded.find('[data-admin-nav] a[href="/admin/services"]').exists()).toBe(true)
    expect(
      expanded.find('[data-admin-nav] a[href="/admin/services?product_type=vps"]').exists(),
    ).toBe(false)

    await expanded
      .find('[data-admin-nav] button[aria-label="Products/Services submenu"]')
      .trigger('click')

    expect(
      expanded.find('[data-admin-nav] a[href="/admin/services?product_type=vps"]').exists(),
    ).toBe(true)

    wrapper.unmount()
  })

  it('lists a group flat, one level, until a submenu is opened', async () => {
    const wrapper = render()

    await groupTriggers(wrapper)[2]?.trigger('click')

    const rows = [...(flyout()?.querySelectorAll('a') ?? [])].map((row) =>
      (row.textContent ?? '').trim(),
    )

    expect(rows).toEqual([
      'Transactions List',
      'Add Transaction',
      'Invoices',
      'Gateway Log',
      'Unpaid invoice sequence',
      'Currencies',
      // Phase 16 added the monthly review here, because an operator looking
      // for money looks under Billing.
      'Reports',
    ])
  })

  it('makes the wordmark the way to the dashboard', () => {
    const wrapper = render()
    const home = wrapper.findAll('a[href="/admin"]')

    // One link, in the rail's own header, and titled — a logo doing
    // navigation duty silently is a link nobody can place.
    expect(home).toHaveLength(1)
    expect(home[0]?.attributes('title')).toContain('dashboard')
  })

  /**
   * The breadcrumb is the only place that says which record is on screen: a
   * detail page has no entry in any menu.
   */
  it('says where you are in the topbar', () => {
    const trail = render()
      .findAll('nav[aria-label="Breadcrumb"] li')
      .map((crumb) => crumb.text().trim())

    expect(trail[0]).toBe('Billing')
    expect(trail.at(-1)).toBe('Invoices')
  })

  /**
   * The one gate that is not a permission. An administrator holds every staff
   * permission by design, so "only the owner of this installation" cannot be
   * expressed as one.
   */
  it('hides the spanner from anybody but the owner', () => {
    permissionState.superAdmin = false

    expect(render().find('button[aria-label="Tools"]').exists()).toBe(false)
  })

  it('shows the owner the spanner', () => {
    expect(render().find('button[aria-label="Tools"]').exists()).toBe(true)
  })

  it('offers the palette and the help menu to everybody', () => {
    const wrapper = render()

    // The trigger shows its own shortcut, which is the only way anybody
    // learns a shortcut exists.
    expect(wrapper.find('kbd').text()).toContain('K')
    expect(wrapper.find('button[aria-label="Help"]').exists()).toBe(true)
  })

  it('puts the session controls on the topbar, not in the rail', () => {
    const wrapper = render()
    const topbar = wrapper.find('header')

    expect(topbar.find('kbd').exists()).toBe(true)
    expect(wrapper.find('[data-admin-nav] kbd').exists()).toBe(false)
  })

  /**
   * A link nobody configured is left out rather than shown pointing nowhere —
   * the same rule the help menu follows.
   */
  it('puts the configured help links in the footer, in reading order', () => {
    const wrapper = render()
    const links = wrapper.findAll('footer a').map((link) => link.text())

    expect(links).toEqual(['Report a Bug', 'Documentation', 'Contact us'])
    expect(wrapper.find('footer p').text()).toContain('InfraCMS')
  })
})
