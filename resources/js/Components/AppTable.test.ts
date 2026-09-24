import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { h } from 'vue'

import AppTable from './AppTable.vue'
import AppTableRow from './AppTableRow.vue'
import AppTableSkeleton from './AppTableSkeleton.vue'
import { type TableColumn } from './tableContext'

/**
 * The table is the screen in this product: nearly every admin page is one.
 * These tests are about the three things §7 added to it, and each of them is
 * a way a table goes wrong rather than a feature to tick off.
 */
const COLUMNS: TableColumn[] = [
  { key: 'number', label: 'Invoice #' },
  { key: 'client', label: 'Client name' },
  { key: 'due', label: 'Due date', optional: true },
  { key: 'method', label: 'Payment method', optional: true, offByDefault: true },
  { key: 'total', label: 'Total', numeric: true },
]

/**
 * The columns control is an `AppMenu`, which teleports its panel to the
 * body — so opening it and then reading `document` is the only way to see
 * what it offers. That is the same reason the menu exists: a panel inside
 * the table is a panel the table's own `overflow-x-auto` cuts off.
 */
async function openColumns(wrapper: ReturnType<typeof mount>): Promise<Element[]> {
  await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

  return [...document.querySelectorAll('[role="menu"] label')]
}

/** The selection as the table last announced it. */
function announced(wrapper: ReturnType<typeof mount>): string[] | undefined {
  const events = wrapper.emitted('update:selected') as string[][][] | undefined

  return events?.at(-1)?.[0]
}

function rows(ids: string[]) {
  return () =>
    ids.map((id) =>
      h(AppTableRow, { id, label: `invoice ${id}`, key: id }, () => [
        h('td', { 'data-col': 'number' }, id),
        h('td', { 'data-col': 'client' }, 'Acme Ltd'),
        h('td', { 'data-col': 'due' }, '2026-01-01'),
        h('td', { 'data-col': 'method' }, 'Card'),
        h('td', { 'data-col': 'total', class: 'numeric' }, '10.00'),
      ]),
    )
}

describe('AppTable columns', () => {
  // A teleported menu is appended to the body and outlives the wrapper that
  // opened it, so one test's panel would still be there for the next.
  enableAutoUnmount(afterEach)

  afterEach(() => {
    document.body.innerHTML = ''
  })

  beforeEach(() => {
    window.localStorage.clear()
  })

  /**
   * Most of the panel was written before §7 and passes `headers`. A change
   * that quietly dropped that shape would empty twenty-five screens.
   */
  it('still draws a table declared with plain headers', () => {
    const wrapper = mount(AppTable, {
      props: { headers: ['Name', 'Amount'], numeric: [1] },
    })

    const headers = wrapper.findAll('th')

    expect(headers.map((header) => header.text())).toEqual(['Name', 'Amount'])
    expect(headers[1]?.classes()).toContain('numeric')

    // No name, nothing optional: no control, rather than a control that
    // forgets on every navigation.
    expect(wrapper.text()).not.toContain('Columns')
  })

  it('offers only the optional columns, and only when the table is named', async () => {
    const control = '[aria-label="Choose columns"]'

    expect(
      mount(AppTable, { props: { columns: COLUMNS } })
        .find(control)
        .exists(),
    ).toBe(false)

    // Without a toolbar the control is an icon in the header row, so it is
    // found by its accessible name rather than by visible text.
    const wrapper = mount(AppTable, { props: { columns: COLUMNS, name: 'invoices' } })

    expect(wrapper.find(control).exists()).toBe(true)

    const offered = (await openColumns(wrapper)).map((label) => label.textContent?.trim())

    // Invoice # and Client name are not on the list: a table whose first
    // column can be hidden is rows of numbers belonging to nothing.
    expect(offered).toEqual(['Due date', 'Payment method'])
  })

  it('starts with an off-by-default column hidden, and remembers being asked for it', async () => {
    const wrapper = mount(AppTable, {
      props: { columns: COLUMNS, name: 'invoices' },
      slots: { default: rows(['INV-1']) },
    })

    expect(wrapper.findAll('thead th').map((header) => header.text())).toEqual([
      'Invoice #',
      'Client name',
      'Due date',
      'Total',
    ])

    // The rule that hides the caller's own cells. A class on the `th` alone
    // would leave the `td` under it in place and the column misaligned.
    expect(wrapper.find('style').text()).toContain('[data-col="method"]')

    const method = (await openColumns(wrapper)).at(1)?.querySelector('input')

    method?.click()
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('thead th').map((header) => header.text())).toContain('Payment method')
    expect(window.localStorage.getItem('table.invoices.hidden')).toBe('[]')

    // A second table of the same name finds the answer.
    const next = mount(AppTable, { props: { columns: COLUMNS, name: 'invoices' } })

    expect(next.findAll('thead th').map((header) => header.text())).toContain('Payment method')
  })

  /**
   * An empty stored list means "they turned everything on", which is not the
   * same as never having been asked. Reading the second as the first is how a
   * preference silently resets.
   */
  it('tells an empty stored preference apart from no preference', () => {
    window.localStorage.setItem('table.invoices.hidden', '[]')

    const wrapper = mount(AppTable, { props: { columns: COLUMNS, name: 'invoices' } })

    expect(wrapper.findAll('thead th').map((header) => header.text())).toContain('Payment method')
  })

  it('survives a stored value nothing wrote', () => {
    window.localStorage.setItem('table.invoices.hidden', 'not json')

    const wrapper = mount(AppTable, { props: { columns: COLUMNS, name: 'invoices' } })

    expect(wrapper.findAll('thead th').length).toBe(4)
  })
})

describe('AppTable selection', () => {
  it('draws no checkbox column until the table is selectable', () => {
    const wrapper = mount(AppTable, {
      props: { columns: COLUMNS },
      slots: { default: rows(['INV-1', 'INV-2']) },
    })

    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
  })

  it('names a checkbox after the row rather than its position', () => {
    const wrapper = mount(AppTable, {
      props: { columns: COLUMNS, selectable: true, rowIds: ['INV-1'], noun: 'invoice' },
      slots: { default: rows(['INV-1']) },
    })

    expect(wrapper.find('tbody input[type="checkbox"]').attributes('aria-label')).toBe(
      'Select invoice INV-1',
    )
    expect(wrapper.find('thead input[type="checkbox"]').attributes('aria-label')).toBe(
      'Select every invoice on this page',
    )
  })

  it('marks a selected row in a way that survives being hovered', async () => {
    const wrapper = mount(AppTable, {
      props: { columns: COLUMNS, selectable: true, rowIds: ['INV-1', 'INV-2'] },
      slots: { default: rows(['INV-1', 'INV-2']) },
    })

    await wrapper.find('tbody input[type="checkbox"]').setValue(true)

    // An attribute, not a class: the tint and the inset edge are one rule
    // next to the hover rule they have to stay distinguishable from.
    expect(wrapper.findAll('tbody tr[data-selected]')).toHaveLength(1)
    expect(announced(wrapper)).toEqual(['INV-1'])
  })

  /**
   * Select-all means this page. A checkbox that quietly picked two hundred
   * rows nobody has looked at is how a bulk action goes wrong.
   */
  it('selects this page and keeps what an earlier page selected', async () => {
    const wrapper = mount(AppTable, {
      props: {
        columns: COLUMNS,
        selectable: true,
        rowIds: ['INV-3', 'INV-4'],
        selected: ['INV-1'],
      },
      slots: { default: rows(['INV-3', 'INV-4']) },
    })

    await wrapper.find('thead input[type="checkbox"]').setValue(true)

    expect(announced(wrapper)).toEqual(['INV-1', 'INV-3', 'INV-4'])
  })

  it('clears this page without clearing another one', async () => {
    const wrapper = mount(AppTable, {
      props: {
        columns: COLUMNS,
        selectable: true,
        rowIds: ['INV-3', 'INV-4'],
        selected: ['INV-1', 'INV-3', 'INV-4'],
      },
      slots: { default: rows(['INV-3', 'INV-4']) },
    })

    await wrapper.find('thead input[type="checkbox"]').setValue(false)

    expect(announced(wrapper)).toEqual(['INV-1'])
  })

  it('reads as partly selected when only some of the page is', () => {
    const wrapper = mount(AppTable, {
      props: {
        columns: COLUMNS,
        selectable: true,
        rowIds: ['INV-1', 'INV-2'],
        selected: ['INV-1'],
      },
      slots: { default: rows(['INV-1', 'INV-2']) },
    })

    const all = wrapper.find('thead input[type="checkbox"]').element as HTMLInputElement

    expect(all.indeterminate).toBe(true)
    expect(all.checked).toBe(false)
  })

  it('only offers the bulk slot once something is selected', () => {
    const shut = mount(AppTable, {
      props: { columns: COLUMNS, selectable: true, rowIds: ['INV-1'] },
      slots: { default: rows(['INV-1']), bulk: '<p>2 actions</p>' },
    })

    expect(shut.text()).not.toContain('2 actions')

    const open = mount(AppTable, {
      props: { columns: COLUMNS, selectable: true, rowIds: ['INV-1'], selected: ['INV-1'] },
      slots: { default: rows(['INV-1']), bulk: '<p>2 actions</p>' },
    })

    expect(open.text()).toContain('2 actions')
  })
})

describe('AppTableSkeleton', () => {
  /**
   * A skeleton exists so the page does not jump when the rows land, which
   * only works if it has the same columns as the table it stands in for.
   */
  it('has the same columns as the table it replaces', () => {
    const wrapper = mount(AppTableSkeleton, { props: { columns: COLUMNS, rows: 3 } })

    expect(wrapper.findAll('thead th').map((header) => header.text())).toEqual([
      'Invoice #',
      'Client name',
      'Due date',
      'Payment method',
      'Total',
    ])
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
  })

  it('says it is loading once, not once per cell', () => {
    const wrapper = mount(AppTableSkeleton, { props: { headers: ['A', 'B'], rows: 4 } })

    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.find('tbody').attributes('aria-hidden')).toBe('true')
    expect(wrapper.attributes('aria-busy')).toBe('true')
  })
})
