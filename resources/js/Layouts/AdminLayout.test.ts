import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

/**
 * The admin menu is the one piece of this panel nobody can be trained out
 * of: an operator arriving from WHMCS reaches for Billing, and Billing has
 * to be where they reach. These tests exist so that a later refactor cannot
 * quietly rename or re-nest a group.
 *
 * They also cover the two ways a top navigation goes wrong in practice — a
 * dropdown that will not open, and one that stays open over the page it has
 * just navigated to.
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

describe('AdminLayout navigation', () => {
  beforeEach(() => {
    navigateHandlers.length = 0
    permissionState.superAdmin = true
  })

  it('puts the WHMCS groups across the top, in WHMCS order', () => {
    const labels = render()
      .findAll('nav[data-admin-nav] > ul > li')
      .map((item) => item.text().trim())

    expect(labels).toEqual([
      'Dashboard',
      'Clients',
      'Orders',
      'Billing',
      'Services',
      'Domains',
      'Support',
      'Utilities',
      'Setup',
    ])
  })

  it('keeps every dropdown shut until it is asked for', () => {
    expect(render().findAll('nav[data-admin-nav] a[href="/admin/customers"]')).toHaveLength(0)
  })

  it('opens a group on click and closes it when another opens', async () => {
    const wrapper = render()
    const buttons = wrapper.findAll('nav[data-admin-nav] > ul > li button')

    await buttons[0]?.trigger('click')
    expect(wrapper.find('nav[data-admin-nav] a[href="/admin/customers"]').exists()).toBe(true)

    // A second menu replaces the first rather than joining it.
    await buttons[1]?.trigger('click')
    expect(wrapper.find('nav[data-admin-nav] a[href="/admin/customers"]').exists()).toBe(false)
    expect(wrapper.find('nav[data-admin-nav] a[href="/admin/orders"]').exists()).toBe(true)
  })

  it('shuts the menu when the page navigates', async () => {
    const wrapper = render()

    await wrapper.findAll('nav[data-admin-nav] > ul > li button')[0]?.trigger('click')
    expect(wrapper.find('nav[data-admin-nav] a[href="/admin/customers"]').exists()).toBe(true)

    // A panel still hanging over the page it just went to is the commonest
    // way a top nav feels broken.
    navigateHandlers.forEach((handler) => handler())
    await wrapper.vm.$nextTick()

    expect(wrapper.find('nav[data-admin-nav] a[href="/admin/customers"]').exists()).toBe(false)
  })

  it('marks the group the current page belongs to', () => {
    const billing = render().findAll('nav[data-admin-nav] > ul > li')[3]

    // `/admin/invoices` lives under Billing, so Billing carries the marker
    // even though the menu is shut.
    expect(billing?.text()).toContain('Billing')
    expect(billing?.find('span[aria-hidden="true"]').exists()).toBe(true)
  })

  it('sub-heads the long menus rather than listing fourteen links flat', async () => {
    const wrapper = render()
    const setup = wrapper.findAll('nav[data-admin-nav] > ul > li').at(-1)

    await setup?.find('button').trigger('click')

    const headings = setup?.findAll('p').map((heading) => heading.text())

    expect(headings).toEqual(['Products and services', 'Staff', 'Platform'])
  })

  /**
   * The one gate that is not a permission. An administrator holds every
   * staff permission by design, so "only the owner of this installation"
   * cannot be expressed as one — and Apps and Integrations lives behind
   * the spanner in the header rather than in the map, because it is not
   * somewhere an operator goes to do their job.
   */
  it('hides the spanner from anybody but the owner', () => {
    permissionState.superAdmin = false

    expect(render().find('button[aria-label="Tools"]').exists()).toBe(false)
  })

  it('shows the owner the spanner', () => {
    expect(render().find('button[aria-label="Tools"]').exists()).toBe(true)
  })

  it('offers the search box and the help menu to everybody', () => {
    const wrapper = render()

    expect(wrapper.find('button[aria-label="Search"]').exists()).toBe(true)
    expect(wrapper.find('button[aria-label="Help"]').exists()).toBe(true)
  })
})
