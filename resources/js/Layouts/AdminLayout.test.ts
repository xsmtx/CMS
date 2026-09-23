import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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
  await wrapper.find('[data-admin-nav] > button').trigger('click')
}

describe('AdminLayout navigation', () => {
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
    expect(render().findAll('[data-admin-nav] a[href="/admin/customers"]')).toHaveLength(0)
  })

  it('opens a group on click and closes it when another opens', async () => {
    const wrapper = render()
    const buttons = groupTriggers(wrapper)

    await buttons[0]?.trigger('click')
    expect(wrapper.find('[data-admin-nav] a[href="/admin/customers"]').exists()).toBe(true)

    // A second group replaces the first rather than joining it.
    await buttons[1]?.trigger('click')
    expect(wrapper.find('[data-admin-nav] a[href="/admin/customers"]').exists()).toBe(false)
    expect(wrapper.find('[data-admin-nav] a[href="/admin/orders"]').exists()).toBe(true)
  })

  it('shuts the rail and its group when the page navigates', async () => {
    const wrapper = render()

    await groupTriggers(wrapper)[0]?.trigger('click')
    expect(wrapper.find('[data-admin-nav] a[href="/admin/customers"]').exists()).toBe(true)

    // A panel still hanging over the page it just went to is the commonest
    // way navigation feels broken.
    navigateHandlers.forEach((handler) => handler())
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-admin-nav] a[href="/admin/customers"]').exists()).toBe(false)
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

    const rows = groupsOf(wrapper)[2]
      ?.findAll('a')
      .map((row) => row.text())

    expect(rows).toEqual([
      'Transactions List',
      'Add Transaction',
      'Invoices',
      'Gateway Log',
      'Unpaid invoice sequence',
      'Currencies',
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
