import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'

import { useFocusTrap } from '../composables/useFocusTrap'
import { httpTone, statusTone } from '../status'
import AppTable from './AppTable.vue'
import AppTabs from './AppTabs.vue'
import { type TableSort } from './tableContext'

/**
 * The primitives the enterprise design system added. Each test names the way
 * the thing goes wrong, not the feature it has.
 */

enableAutoUnmount(afterEach)

describe('one status vocabulary', () => {
  it('means the same thing on every screen that says the same word', () => {
    // The drift this replaced: a closed customer was red and a closed
    // ticket grey; an expired domain was red on the list, amber on its page.
    expect(statusTone('closed')).toBe('neutral')
    expect(statusTone('expired')).toBe('critical')
    expect(statusTone('active')).toBe('healthy')
    expect(statusTone('suspended')).toBe('warning')
    expect(statusTone('maintenance')).toBe('maintenance')
  })

  it('tells "moving" apart from "wrong"', () => {
    expect(statusTone('provisioning')).toBe('info')
    expect(statusTone('retrying')).toBe('info')
    expect(statusTone('failed')).toBe('critical')
  })

  it('says unknown, visibly, for a word nobody mapped', () => {
    expect(statusTone('flibbertigibbet')).toBe('unknown')
    expect(statusTone(null)).toBe('unknown')
  })

  it('ignores case and separators', () => {
    expect(statusTone('Grace Period')).toBe('warning')
    expect(statusTone('manual-intervention')).toBe('critical')
  })

  it('reads a response code as how one request went', () => {
    expect(httpTone(204)).toBe('healthy')
    expect(httpTone(422)).toBe('warning')
    expect(httpTone(503)).toBe('critical')
  })
})

describe('AppTabs', () => {
  const TABS = [
    { key: 'overview', label: 'Overview' },
    { key: 'contacts', label: 'Contacts', count: 3 },
    { key: 'notes', label: 'Notes', count: 0 },
  ]

  it('puts only the selected tab in the Tab order', () => {
    const tabs = mount(AppTabs, {
      props: { tabs: TABS, label: 'Client', modelValue: 'contacts' },
    }).findAll('[role="tab"]')

    expect(tabs.map((tab) => tab.attributes('tabindex'))).toEqual(['-1', '0', '-1'])
    expect(tabs[1]?.attributes('aria-selected')).toBe('true')
  })

  it('labels the panel by the tab that shows it', () => {
    const wrapper = mount(AppTabs, {
      props: { tabs: TABS, label: 'Client', modelValue: 'contacts' },
    })

    const panel = wrapper.find('[role="tabpanel"]')
    const tab = wrapper.findAll('[role="tab"]')[1]

    expect(panel.attributes('aria-labelledby')).toBe(tab?.attributes('id'))
    expect(tab?.attributes('aria-controls')).toBe(panel.attributes('id'))
  })

  it('moves with the arrow keys and wraps at the ends', async () => {
    const wrapper = mount(AppTabs, {
      props: { tabs: TABS, label: 'Client', modelValue: 'overview' },
    })

    await wrapper.findAll('[role="tab"]')[0]?.trigger('keydown', { key: 'ArrowLeft' })

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['notes'])

    await wrapper.findAll('[role="tab"]')[0]?.trigger('keydown', { key: 'End' })

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['notes'])
  })

  it('shows a zero count rather than hiding it', () => {
    const wrapper = mount(AppTabs, {
      props: { tabs: TABS, label: 'Client', modelValue: 'overview' },
    })

    expect(wrapper.findAll('[role="tab"]')[2]?.text()).toContain('0')
  })
})

describe('AppTable sorting', () => {
  const COLUMNS = [
    { key: 'name', label: 'Server', sortable: true },
    { key: 'status', label: 'Status' },
  ]

  it('tells a screen reader which column is sorted, and which way', () => {
    const wrapper = mount(AppTable, {
      props: { columns: COLUMNS, sort: { key: 'name', direction: 'desc' } as TableSort },
    })

    const headers = wrapper.findAll('thead th')

    expect(headers[0]?.attributes('aria-sort')).toBe('descending')
    expect(headers[1]?.attributes('aria-sort')).toBeUndefined()
  })

  it('makes only a sortable header a control', () => {
    const wrapper = mount(AppTable, { props: { columns: COLUMNS } })

    expect(wrapper.findAll('thead th')[0]?.find('button').exists()).toBe(true)
    expect(wrapper.findAll('thead th')[1]?.find('button').exists()).toBe(false)
  })

  it('cycles ascending, descending, then back to the server order', async () => {
    const wrapper = mount(AppTable, { props: { columns: COLUMNS, sort: null } })
    const button = wrapper.find('thead th button')

    await button.trigger('click')
    expect(wrapper.emitted('update:sort')?.at(-1)).toEqual([{ key: 'name', direction: 'asc' }])

    await wrapper.setProps({ sort: { key: 'name', direction: 'asc' } })
    await button.trigger('click')
    expect(wrapper.emitted('update:sort')?.at(-1)).toEqual([{ key: 'name', direction: 'desc' }])

    await wrapper.setProps({ sort: { key: 'name', direction: 'desc' } })
    await button.trigger('click')
    expect(wrapper.emitted('update:sort')?.at(-1)).toEqual([null])
  })
})

describe('useFocusTrap', () => {
  it('wraps Tab from the last control back to the first', async () => {
    const Harness = defineComponent({
      setup() {
        const box = ref<HTMLElement | null>(null)
        const trap = useFocusTrap(box)

        return () =>
          h('div', { ref: box, tabindex: -1, onKeydown: trap }, [
            h('button', { id: 'first' }, 'Cancel'),
            h('button', { id: 'last' }, 'Delete'),
          ])
      },
    })

    const wrapper = mount(Harness, { attachTo: document.body })
    await nextTick()

    // happy-dom reports no layout, so every element looks invisible to the
    // `getClientRects` filter; give the two buttons a rect.
    for (const id of ['first', 'last']) {
      const element = document.getElementById(id) as HTMLElement
      element.getClientRects = () => [new DOMRect(0, 0, 10, 10)] as unknown as DOMRectList
    }

    const last = document.getElementById('last') as HTMLElement
    last.focus()

    await wrapper.trigger('keydown', { key: 'Tab' })

    expect(document.activeElement?.id).toBe('first')

    await wrapper.trigger('keydown', { key: 'Tab', shiftKey: true })

    expect(document.activeElement?.id).toBe('last')
  })
})

describe('AppTable responsive columns', () => {
  it('drops a priority column below its breakpoint and pins the identity column', () => {
    const wrapper = mount(AppTable, {
      props: {
        columns: [
          { key: 'name', label: 'Server', sticky: true },
          { key: 'region', label: 'Region', hideBelow: 'xl' },
        ],
      },
    })

    const css = wrapper.find('style').text()

    expect(css).toContain('@media (max-width:1279.98px)')
    expect(css).toContain('[data-col="region"]{display:none}')
    expect(css).toContain('[data-col="name"]{position:sticky')
  })
})
