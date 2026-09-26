import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

/**
 * The admin menu is the one piece of this panel nobody can be trained out
 * of: an operator arriving from WHMCS reaches for Billing, and Billing has
 * to be where they reach. These tests exist so that a later refactor cannot
 * quietly rename or re-nest a group.
 *
 * The menu is across the top, in the two bars DESIGN.md names: `global-nav`
 * carries the destinations and `sub-nav-frosted` carries where you are.
 *
 * The dropdown is **teleported to the body**, so the assertions about it read
 * `document` rather than the wrapper. That is the whole point of it: the bar
 * is translucent, `backdrop-filter` creates a containing block, and a panel
 * left inside it would be clipped by the very thing that makes it glass.
 */
const navigateHandlers: Array<() => void> = []

/**
 * Modules, Servers, Licence and Import are gated by who somebody is rather
 * than by what they may do, so the mock has to be able to say both — and
 * `can` has to be able to say no, because the spanner is drawn from
 * permissions now rather than from being the owner.
 */
const permissionState = {
  superAdmin: true,
  can: (slug: string) => slug.length > 0,
}

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
    can: (slug: string) => permissionState.can(slug),
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

/** The group buttons, in the order the bar draws them. */
function groupTriggers(wrapper: ReturnType<typeof render>) {
  return wrapper.findAll('[data-admin-nav] nav button')
}

/** The teleported dropdown, wherever in the document it landed. */
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
    permissionState.can = () => true
    window.localStorage.clear()
  })

  it('lists the WHMCS groups in WHMCS order, across the top', () => {
    const wrapper = render()

    expect(groupTriggers(wrapper).map((button) => button.text().trim())).toEqual([
      'Clients',
      'Orders',
      'Billing',
      // Operations comes before Support in §3's order, so Infrastructure sits
      // between Billing and the helpdesk rather than beside Utilities.
      'Infrastructure',
      'Support',
      'Utilities',
    ])

    // Setup is not in the bar at all: everything that was in it lives on one
    // page, and that page hangs off the spanner with the other things somebody
    // configures once. The bar is for the screens an operator works in.
    expect(groupTriggers(wrapper).map((button) => button.text().trim())).not.toContain('Setup')
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

  /**
   * Setup used to be eight links in a dropdown, then one row in the rail. It
   * is neither now: it is a page, reached from the spanner, and the rail does
   * not mention it.
   */
  it('keeps Setup out of the rail and on the spanner', async () => {
    const wrapper = render()

    expect(wrapper.find('[data-admin-nav] nav a[href="/admin/apps"]').exists()).toBe(false)

    await wrapper.find('button[aria-label="Tools"]').trigger('click')

    expect(document.querySelector('a[href="/admin/apps"]')).not.toBeNull()
  })

  /**
   * Connect came back to Utilities, and it is a permission rather than the
   * owner-only gate: a support agent fixing a mailbox has to get into the
   * panel, and the alternative is emailing them a root password.
   */
  it('offers Connect from Utilities to anybody holding the permission', async () => {
    permissionState.superAdmin = false

    const wrapper = render()
    const utilities = groupTriggers(wrapper).find((button) => button.text().trim() === 'Utilities')

    await utilities?.trigger('click')

    expect(flyoutLink('/admin/apps/connect')).not.toBeNull()
  })

  /**
   * Addressing is what this installation has decided, beside the Explorer's
   * record of what it has discovered — and it is gated on its own permission,
   * because support reads it and most staff have no reason to.
   */
  it('offers Addressing from Infrastructure to anybody holding the permission', async () => {
    const wrapper = render()
    const group = groupTriggers(wrapper).find((button) => button.text().trim() === 'Infrastructure')

    await group?.trigger('click')

    expect(flyoutLink('/admin/network/addressing')).not.toBeNull()
  })

  it('leaves Addressing out for somebody without the permission', async () => {
    permissionState.superAdmin = false
    permissionState.can = (slug) => slug !== 'network.ipam.view'

    const wrapper = render()
    const group = groupTriggers(wrapper).find((button) => button.text().trim() === 'Infrastructure')

    await group?.trigger('click')

    expect(flyoutLink('/admin/network/addressing')).toBeNull()
  })

  it('leaves Connect out for somebody without the permission', async () => {
    permissionState.superAdmin = false
    permissionState.can = (slug) => slug !== 'infrastructure.connect'

    const wrapper = render()
    const utilities = groupTriggers(wrapper).find((button) => button.text().trim() === 'Utilities')

    await utilities?.trigger('click')

    expect(flyoutLink('/admin/apps/connect')).toBeNull()
  })

  /**
   * The rows stayed in the map even though the dropdown went, because the
   * palette is built from it. Somebody who knows they want Roles should be
   * able to press ⌘K and type it rather than learning where it moved.
   */
  it('still finds a moved screen in the command palette', () => {
    const wrapper = render()

    const destinations = wrapper
      .findComponent({ name: 'CommandPalette' })
      .props('destinations') as { label: string; href: string }[]

    const labels = destinations.map((destination) => destination.label)

    expect(labels).toContain('Roles')
    expect(labels).toContain('Licence')
    expect(labels).toContain('Apps & Integrations')
  })

  it('marks the group the current page belongs to', () => {
    const marked = groupTriggers(render()).filter((button) =>
      button.classes().includes('font-medium'),
    )

    // `/admin/invoices` lives under Billing, so Billing reads as the one you
    // are on even though its menu is shut.
    expect(marked).toHaveLength(1)
    expect(marked[0]?.text()).toContain('Billing')
  })

  /**
   * Expanded, the group you are in is already open. A rail that made somebody
   * press their own section to see where they are is a rail that forgot.
   */

  /**
   * Two levels, and the second opens beside the row that owns it rather than
   * pushing the rows below it down.
   */

  /**
   * Every destination in the group, one press away.
   *
   * The rail listed the parents and hid each filtered list behind a second
   * press; the bar lists them under their own heading, because a menu that is
   * already open has nothing to gain by hiding half of itself.
   */
  it('lists a group one level deep, with the filters behind a chevron', async () => {
    const wrapper = render()

    await groupTriggers(wrapper)[2]?.trigger('click')

    const rows = [...(flyout()?.querySelectorAll('a') ?? [])].map((row) =>
      (row.textContent ?? '').trim(),
    )

    // The six destinations, and not the seventeen filters underneath them:
    // listing those inline buries the ordinary rows.
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

  /**
   * The row stays a link to the whole list and the chevron opens the filters
   * beside it, which is the shape every panel this product replaces uses.
   */
  it('opens a filtered list beside the menu, not inside it', async () => {
    const wrapper = render()

    await groupTriggers(wrapper)[0]?.trigger('click')

    // The parent is a link in its own right: somebody who wanted the whole
    // list should not have to pick a filter first.
    expect(flyoutLink('/admin/services')).not.toBeNull()
    expect(document.querySelector('[data-rail-submenu]')).toBeNull()

    const chevron = flyout()?.querySelector(
      'button[aria-label="Products/Services submenu"]',
    ) as HTMLElement | null

    chevron?.click()
    await wrapper.vm.$nextTick()

    const submenu = document.querySelector('[data-rail-submenu]')

    expect(submenu).not.toBeNull()
    expect(submenu?.querySelector('a[href="/admin/services?product_type=vps"]')).not.toBeNull()

    // Beside the menu rather than inside it, for the reason the first panel
    // is teleported: an ancestor's overflow cannot clip what is not inside it.
    expect(submenu?.closest('[data-rail-flyout]')).toBeNull()
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
   * The map says "Review Queue" and the page heading says "Review queue".
   * That is one name, and comparing the two exactly drew it twice, side by
   * side, on every screen whose heading was worded in sentence case.
   */
  it('does not draw the screen name twice in a different case', () => {
    const trail = mount(AdminLayout, {
      props: { heading: 'invoices' },
      global: { stubs: { ThemeSwitch: true, AppAlert: true } },
    })
      .findAll('nav[aria-label="Breadcrumb"] li')
      .map((crumb) => crumb.text().trim())

    expect(trail).toEqual(['Billing', 'invoices'])
  })

  /**
   * The group's name is dropped by the same rule, and it is the case that
   * actually shipped: the Clients group holds a row called "View/Search
   * Clients" and the screen is headed "Clients", so the trail read
   * "Clients > View/Search Clients > Clients" — one word twice, with
   * something else wedged between the two.
   */
  it('does not draw the section name when the screen is called that', () => {
    const trail = mount(AdminLayout, {
      props: { heading: 'Billing' },
      global: { stubs: { ThemeSwitch: true, AppAlert: true } },
    })
      .findAll('nav[aria-label="Breadcrumb"] li')
      .map((crumb) => crumb.text().trim())

    expect(trail).toEqual(['Invoices', 'Billing'])
  })

  /**
   * The one gate that is not a permission. An administrator holds every staff
   * permission by design, so "only the owner of this installation" cannot be
   * expressed as one.
   */
  /**
   * The spanner used to be owner-only as a whole. Setup and Connect are
   * permissions now, so it is drawn when it has something on it and not
   * before — a menu that opens on nothing is worse than no menu.
   */
  it('hides the spanner from somebody with nothing on it', () => {
    permissionState.superAdmin = false
    permissionState.can = () => false

    expect(render().find('button[aria-label="Tools"]').exists()).toBe(false)
  })

  it('shows the spanner to anybody who can reach something on it', () => {
    expect(render().find('button[aria-label="Tools"]').exists()).toBe(true)
  })

  it('offers the palette and the help menu to everybody', () => {
    const wrapper = render()

    // The trigger shows its own shortcut, which is the only way anybody
    // learns a shortcut exists.
    expect(wrapper.find('kbd').text()).toContain('K')
    expect(wrapper.find('button[aria-label="Help"]').exists()).toBe(true)
  })

  /**
   * One bar carries both now, so the rule is about the menu rather than about
   * the rail: what belongs to the session is in the bar, and a group's
   * dropdown holds destinations and nothing else.
   */
  it('puts the session controls in the bar and nothing else in the menu', async () => {
    const wrapper = render()

    expect(wrapper.find('header').find('kbd').exists()).toBe(true)

    await groupTriggers(wrapper)[0]?.trigger('click')

    expect(flyout()?.querySelector('kbd')).toBeNull()
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
