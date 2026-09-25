import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

/**
 * The portal's navigation is two lists, and which list a destination is in
 * is the decision worth pinning.
 *
 * The bar holds what a customer came for — services, domains, orders,
 * billing, support — and the account menu holds what they came to change.
 * All eleven in one bar wrapped onto a second line on a laptop, and a
 * customer looking for their invoices read past "Webhooks" to find them.
 *
 * Labels are read through `useTranslations`, which answers with the key when
 * no translations block was rendered, so these assertions are about hrefs
 * and about `aria-current`: the wording is covered by the language-file
 * tests, and the structure is not covered anywhere else.
 */
const permissions: string[] = [
  'portal.services.view',
  'portal.domains.view',
  'portal.orders.view',
  'portal.billing.view',
  'portal.tickets.view',
  'portal.contacts.manage',
  'portal.tokens.manage',
]

const pageState = { url: '/client', permissions }

vi.mock('@inertiajs/vue3', () => ({
  Link: {
    props: ['href', 'method', 'as'],
    template: '<a :href="href"><slot /></a>',
  },
  router: { delete: () => {} },
  usePage: () => ({
    props: {
      brand: {
        name: 'Northwind Hosting',
        legalName: null,
        portalName: 'Northwind',
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
      auth: {
        user: { name: 'Ines Caetano', email: 'ines@example.test' },
        permissions: pageState.permissions,
      },
      impersonation: null,
    },
    url: pageState.url,
  }),
}))

const { default: ClientLayout } = await import('./ClientLayout.vue')

enableAutoUnmount(afterEach)

function render() {
  return mount(ClientLayout, {
    props: { heading: 'Overview' },
    global: { stubs: { ThemeSwitch: true, AppMenu: false } },
  })
}

function barLinks(wrapper: ReturnType<typeof render>): string[] {
  return wrapper
    .findAll('header nav a')
    .map((link) => link.attributes('href') ?? '')
    .filter(Boolean)
}

describe('ClientLayout navigation', () => {
  it('puts what a customer came for in the bar, in order', () => {
    expect(barLinks(render())).toEqual([
      '/client',
      '/client/services',
      '/client/domains',
      '/client/orders',
      '/client/billing',
      '/client/support',
    ])
  })

  it('keeps the account screens out of the bar', () => {
    const bar = barLinks(render())

    for (const href of ['/client/profile', '/security', '/client/developer/tokens']) {
      expect(bar).not.toContain(href)
    }
  })

  it('leaves out a destination the contact may not open', () => {
    pageState.permissions = permissions.filter((slug) => slug !== 'portal.billing.view')

    expect(barLinks(render())).not.toContain('/client/billing')

    pageState.permissions = permissions
  })

  it('marks the destination somebody is on', () => {
    pageState.url = '/client/services'

    const current = render()
      .findAll('header nav a')
      .filter((link) => link.attributes('aria-current') === 'page')
      .map((link) => link.attributes('href'))

    expect(current).toEqual(['/client/services'])

    pageState.url = '/client'
  })

  /**
   * Overview is the only destination whose href is a prefix of every other
   * one, so matching it by prefix would underline two tabs at once.
   */
  it('does not call Overview current on a screen under it', () => {
    pageState.url = '/client/billing/invoices/INV-000042'

    const current = render()
      .findAll('header nav a')
      .filter((link) => link.attributes('aria-current') === 'page')
      .map((link) => link.attributes('href'))

    expect(current).toEqual(['/client/billing'])

    pageState.url = '/client'
  })
})
