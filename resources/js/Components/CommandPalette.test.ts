import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

/**
 * The palette is the one piece of chrome an operator uses with their hands
 * off the mouse, so the keyboard is the surface under test: the shortcut
 * that opens it, the keys that move through it, and the matching that makes
 * three letters enough.
 */
const visited: string[] = []

vi.mock('@inertiajs/vue3', () => ({
  router: {
    get: () => {},
    visit: (href: string) => visited.push(href),
  },
}))

const { default: CommandPalette } = await import('./CommandPalette.vue')

const DESTINATIONS = [
  { label: 'View/Search Clients', href: '/admin/customers', group: 'Clients', icon: 'clients' },
  { label: 'Add New Client', href: '/admin/clients/create', group: 'Clients', icon: 'clients' },
  { label: 'List All Orders', href: '/admin/orders', group: 'Orders', icon: 'orders' },
  { label: 'Invoices', href: '/admin/invoices', group: 'Billing', icon: 'billing' },
] as const

function render() {
  return mount(CommandPalette, {
    props: { destinations: [...DESTINATIONS] },
    attachTo: document.body,
  })
}

function press(key: string, options: KeyboardEventInit = {}): void {
  window.dispatchEvent(new KeyboardEvent('keydown', { key, ...options }))
}

/**
 * The panel is teleported to `<body>`, so it is not inside the wrapper —
 * which is the whole reason it escapes the table's overflow in the first
 * place. Queries go to the document.
 */
function dialog(): HTMLElement | null {
  return document.body.querySelector('[role="dialog"]')
}

function rows(): HTMLElement[] {
  return Array.from(document.body.querySelectorAll('[role="dialog"] li'))
}

function input(): HTMLInputElement {
  const field = document.body.querySelector<HTMLInputElement>('[role="dialog"] input')

  if (field === null) throw new Error('The palette is not open.')

  return field
}

async function type(value: string): Promise<void> {
  const field = input()

  field.value = value
  field.dispatchEvent(new Event('input'))
  await Promise.resolve()
}

function key(name: string): void {
  input().dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }))
}

/**
 * The panel is teleported, so it outlives the wrapper unless somebody says
 * otherwise — and a palette left open by the previous test is eight rows
 * the next one did not put there.
 */
enableAutoUnmount(afterEach)

afterEach(() => {
  document.body.innerHTML = ''
})

describe('CommandPalette', () => {
  it('shows the shortcut on the trigger, which is how anybody learns it', () => {
    expect(render().find('kbd').text()).toContain('K')
  })

  it('opens on the shortcut and on slash, and closes on escape', async () => {
    const wrapper = render()

    expect(dialog()).toBeNull()

    press('k', { metaKey: true })
    await wrapper.vm.$nextTick()
    expect(dialog()).not.toBeNull()

    key('Escape')
    await wrapper.vm.$nextTick()
    expect(dialog()).toBeNull()

    // `/` is the one every operator tries first.
    press('/')
    await wrapper.vm.$nextTick()
    expect(dialog()).not.toBeNull()
  })

  /**
   * A subsequence, not a substring: `adnc` is how somebody who uses one of
   * these actually types, and a palette that needed `add new` would be a
   * palette they stop opening.
   */
  it('matches scattered letters and ranks a prefix first', async () => {
    const wrapper = render()

    press('k', { metaKey: true })
    await wrapper.vm.$nextTick()

    await type('adnc')
    await wrapper.vm.$nextTick()
    expect(rows()[0]?.textContent).toContain('Add New Client')

    // `inv` is a prefix of Invoices and appears scattered in nothing else
    // that should beat it.
    await type('inv')
    await wrapper.vm.$nextTick()
    expect(rows()[0]?.textContent).toContain('Invoices')
  })

  it('moves with the arrows, wraps, and opens what is selected', async () => {
    const wrapper = render()

    press('k', { metaKey: true })
    await wrapper.vm.$nextTick()

    key('ArrowDown')
    key('ArrowUp')
    // Back where it started: wrapping means ↑ from the first row is the last
    // row, not a dead key.
    key('ArrowUp')
    await wrapper.vm.$nextTick()

    visited.length = 0
    key('Enter')
    await wrapper.vm.$nextTick()

    expect(visited).toHaveLength(1)
    expect(dialog()).toBeNull()
  })

  it('says so rather than showing an empty box', async () => {
    const wrapper = render()

    press('k', { metaKey: true })
    await wrapper.vm.$nextTick()

    await type('zzzzzz')
    await wrapper.vm.$nextTick()

    expect(rows()).toHaveLength(0)
    expect(dialog()?.textContent).toContain('Nothing matches')
  })
})
