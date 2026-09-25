import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'

import { useFocusTrap } from '../composables/useFocusTrap'
import { httpTone, statusTone } from '../status'
import AppInput from './AppInput.vue'
import AppPagination from './AppPagination.vue'
import AppSelect from './AppSelect.vue'
import AppTable from './AppTable.vue'
import AppTabs from './AppTabs.vue'
import AppTextarea from './AppTextarea.vue'
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

describe('a field in error looks like one', () => {
  /*
   * It did not. The base class list carried `border-line` and the conditional
   * added `border-danger` beside it, so which colour won was decided by the
   * order Tailwind happened to emit two border-colour utilities in — and the
   * hairline won. A refused field was pixel-identical to an accepted one on
   * every form in the product, which the sign-up screen found by being asked
   * for a password twice and getting it wrong on purpose.
   *
   * The fix is that only one of the two classes is ever present. The test
   * asserts the absence, because the presence was never the problem.
   */
  it.each([
    ['AppInput', AppInput],
    ['AppSelect', AppSelect],
    ['AppTextarea', AppTextarea],
  ])('drops the hairline when %s is refused', (_name, component) => {
    const props = { label: 'Password', modelValue: '', options: [] }

    const fine = mount(component, { props })
    const refused = mount(component, { props: { ...props, error: 'Not that one.' } })

    const control = (wrapper: ReturnType<typeof mount>): string =>
      (wrapper.find('input, select, textarea').element as HTMLElement).className

    expect(control(fine)).toContain('border-line')
    expect(control(fine)).not.toContain('border-danger')

    expect(control(refused)).toContain('border-danger')
    expect(control(refused)).not.toContain('border-line')
  })
})

describe('a list that paginates can be paged', () => {
  /*
   * Fourteen admin screens and three portal screens printed "Page 1 of 3" as
   * plain text and rendered no control at all, so the rows past the first page
   * were unreachable. They all use this component now; these are the three
   * things it has to get right for that to be true.
   */
  const links = (): { url: string | null; label: string; active: boolean }[] => [
    { url: null, label: '&laquo; Previous', active: false },
    { url: '/admin/invoices?page=1', label: '1', active: true },
    { url: '/admin/invoices?page=2', label: '2', active: false },
    { url: '/admin/invoices?page=2', label: 'Next &raquo;', active: false },
  ]

  it('links to the other pages and says which one it is on', () => {
    const wrapper = mount(AppPagination, {
      props: { links: links(), total: 47 },
      global: { stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } },
    })

    const hrefs = wrapper.findAll('a').map((link) => link.attributes('href'))

    expect(hrefs).toContain('/admin/invoices?page=2')
    expect(wrapper.find('[aria-current="page"]').text()).toBe('1')
  })

  it('renders the arrow labels as words rather than HTML entities', () => {
    const wrapper = mount(AppPagination, {
      props: { links: links(), total: 47 },
      global: { stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } },
    })

    // Laravel ships `&laquo; Previous`; rendering that with v-html to get an
    // arrow would be an injection sink on every paginated page.
    expect(wrapper.text()).toContain('Previous')
    expect(wrapper.text()).not.toContain('laquo')
  })

  it('stays out of the way when there is one page', () => {
    const wrapper = mount(AppPagination, {
      props: {
        links: [
          { url: null, label: '&laquo; Previous', active: false },
          { url: '/admin/invoices?page=1', label: '1', active: true },
          { url: null, label: 'Next &raquo;', active: false },
        ],
        total: 3,
      },
    })

    expect(wrapper.find('nav').exists()).toBe(false)
  })
})
