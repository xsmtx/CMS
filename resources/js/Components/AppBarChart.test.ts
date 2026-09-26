import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AppBarChart from './AppBarChart.vue'

/**
 * The chart nobody had written a test for, although every dashboard and
 * every report on the installation draws one.
 *
 * Two things about it can regress without anything else noticing: the table
 * underneath, which is the whole of the chart for anybody who cannot see the
 * bars, and what it says when there is nothing to draw.
 */
const MONTHS = [
  { label: 'January', value: 120 },
  { label: 'February', value: 0 },
  { label: 'March', value: 480 },
]

describe('AppBarChart', () => {
  it('puts the same numbers in a table under the bars', () => {
    const wrapper = mount(AppBarChart, { props: { title: 'Money in', rows: MONTHS } })

    // The label is the row's header cell, so each row reads as a pair.
    const rows = wrapper
      .findAll('table tbody tr')
      .map((row) => row.findAll('th, td').map((cell) => cell.text()))

    // Every row, including the empty month: a series that drops its zeroes
    // is a series whose gaps close up and whose shape is a lie.
    expect(rows).toEqual([
      ['January', '120'],
      ['February', '0'],
      ['March', '480'],
    ])
  })

  /**
   * It drew "0 tickets" and then "Nothing in this period." underneath — the
   * same answer twice, which reads as a figure that failed to load rather
   * than as a quiet month.
   */
  it('does not print a total of nothing above the sentence saying so', () => {
    const empty = mount(AppBarChart, {
      props: { title: 'Opened per day', unit: 'tickets', rows: [{ label: 'Monday', value: 0 }] },
    })

    expect(empty.text()).not.toContain('0 tickets')
    expect(empty.text()).toContain('Nothing in this period.')

    const drawn = mount(AppBarChart, {
      props: { title: 'Opened per day', unit: 'tickets', rows: MONTHS },
    })

    expect(drawn.text()).toContain('600 tickets')
    expect(drawn.text()).not.toContain('Nothing in this period.')
  })

  /** `hideTitle` hides it from the eye and not from the outline. */
  it('keeps the title for a screen reader when the section already names it', () => {
    const heading = mount(AppBarChart, {
      props: { title: 'Money in', hideTitle: true, rows: MONTHS },
    }).find('h3')

    expect(heading.text()).toBe('Money in')
    expect(heading.classes()).toContain('sr-only')
  })
})
