import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import AppDrawer from './AppDrawer.vue'

/**
 * The context drawer (§8). It replaces a navigation, so the tests are about
 * the things a navigation gets right for free and a panel has to be told:
 * a way out with the keyboard, focus that goes somewhere and comes back, and
 * the way to the whole record so the drawer never has to grow into one.
 */
enableAutoUnmount(afterEach)

afterEach(() => {
  document.body.innerHTML = ''
})

function drawer(): HTMLElement | null {
  return document.querySelector('[role="dialog"]')
}

describe('AppDrawer', () => {
  it('shows nothing until it is opened', () => {
    mount(AppDrawer, { props: { open: false, title: 'INV-1043' } })

    expect(drawer()).toBeNull()
  })

  it('names itself to a screen reader and traps nothing it should not', async () => {
    const wrapper = mount(AppDrawer, {
      props: { open: true, title: 'INV-1043', subtitle: 'Acme Ltd' },
    })

    await wrapper.vm.$nextTick()

    const panel = drawer()
    const labelledBy = panel?.getAttribute('aria-labelledby')

    expect(panel?.getAttribute('aria-modal')).toBe('true')
    // Labelled by the heading that is actually on screen, not by a duplicate
    // string a translator would have to keep in step.
    expect(panel?.querySelector(`#${labelledBy}`)?.textContent).toContain('INV-1043')
  })

  /**
   * Escape, because a drawer over a list is a thing somebody opened by
   * accident at least once a day.
   */
  it('closes on escape', async () => {
    const wrapper = mount(AppDrawer, { props: { open: true, title: 'INV-1043' } })

    await wrapper.vm.$nextTick()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])
  })

  /**
   * Focus goes in, and comes back to whatever opened it. Without the second
   * half, closing the drawer leaves the caret at the top of the document and
   * an operator tabbing from the row they were on starts again from the
   * skip link.
   */
  it('takes focus and gives it back', async () => {
    const opener = document.createElement('button')
    document.body.append(opener)
    opener.focus()

    const wrapper = mount(AppDrawer, { props: { open: false, title: 'INV-1043' } })

    await wrapper.setProps({ open: true })
    await wrapper.vm.$nextTick()

    expect(document.activeElement).toBe(drawer())

    await wrapper.setProps({ open: false })
    await wrapper.vm.$nextTick()

    expect(document.activeElement).toBe(opener)
  })

  /**
   * The drawer is for a glance. Anything that takes a decision belongs on the
   * record's own page, so the way there is always offered — a drawer that
   * could be the detail page would be two screens to keep in step.
   */
  it('always offers the way to the whole record', async () => {
    const wrapper = mount(AppDrawer, {
      props: { open: true, title: 'INV-1043', href: '/admin/invoices/1' },
      slots: { default: '<p>Two lines, 40.00 owed</p>' },
    })

    await wrapper.vm.$nextTick()

    const link = drawer()?.querySelector('footer a')

    expect(link?.getAttribute('href')).toBe('/admin/invoices/1')
  })

  it('holds the panel\'s shape while the record is on its way', async () => {
    const wrapper = mount(AppDrawer, {
      props: { open: true, title: 'Invoice', loading: true },
      slots: { default: '<p>Arrived</p>' },
    })

    await wrapper.vm.$nextTick()

    // Bars in the shape of what is coming, and one announcement — not a
    // spinner, and not the content and the spinner at once.
    expect(drawer()?.querySelectorAll('.skeleton').length).toBeGreaterThan(0)
    expect(drawer()?.textContent).not.toContain('Arrived')
    expect(drawer()?.querySelectorAll('[role="status"]')).toHaveLength(1)
  })
})
