import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AppCard from './AppCard.vue'

describe('AppCard', () => {
  it('renders its slot content', () => {
    const wrapper = mount(AppCard, { slots: { default: 'Body copy' } })

    expect(wrapper.text()).toContain('Body copy')
  })

  it('renders a heading only when a title is supplied', () => {
    expect(mount(AppCard).find('h2').exists()).toBe(false)
    expect(
      mount(AppCard, { props: { title: 'Services' } })
        .find('h2')
        .text(),
    ).toBe('Services')
  })

  it('associates the description with the card', () => {
    const wrapper = mount(AppCard, {
      props: { title: 'Invoices', description: 'Nothing due right now.' },
    })

    expect(wrapper.text()).toContain('Nothing due right now.')
  })
})
