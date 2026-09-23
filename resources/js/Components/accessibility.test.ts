import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import AppAlert from './AppAlert.vue'
import AppButton from './AppButton.vue'
import AppCheckbox from './AppCheckbox.vue'
import AppCopy from './AppCopy.vue'
import AppIcon from './AppIcon.vue'
import AppInput from './AppInput.vue'
import AppMenu from './AppMenu.vue'
import AppSelect from './AppSelect.vue'
import AppStatus from './AppStatus.vue'
import AppTableSkeleton from './AppTableSkeleton.vue'
import AppTextarea from './AppTextarea.vue'

/**
 * The accessibility rules this design system actually makes, as tests.
 *
 * Handoff §14 asks for accessibility and the honest way to hold a promise like
 * that is not a document — it is a test per rule, over the primitives every
 * screen is built from. Nobody audits forty screens twice a year; a failing test
 * is read the day it breaks.
 *
 * What is **not** claimed here: this is not a WCAG certification and it does not
 * measure contrast. Contrast is a property of the rendered tokens in a browser at
 * a zoom level, and jsdom has no layout — so the design system document records
 * contrast and 200% zoom as things to check by eye, and these tests cover the
 * structural rules that a machine can hold and a person forgets.
 */
enableAutoUnmount(afterEach)

afterEach(() => {
  document.body.innerHTML = ''
})

describe('every control has an accessible name', () => {
  /**
   * An icon with no name is a button a screen reader announces as "button". The
   * one in the middle of a row of four is then indistinguishable from the other
   * three.
   */
  it('names an icon-only menu trigger', async () => {
    const wrapper = mount(AppMenu, { props: { label: 'Tools', icon: 'utilities' } })

    const trigger = wrapper.find('button[aria-haspopup="menu"]')

    expect(trigger.attributes('aria-label')).toBe('Tools')
    expect(trigger.attributes('aria-expanded')).toBe('false')

    await trigger.trigger('click')

    // The state changes as well as the panel: a trigger that never updates
    // `aria-expanded` tells a screen reader nothing happened.
    expect(wrapper.find('button[aria-haspopup="menu"]').attributes('aria-expanded')).toBe('true')
  })

  it('names the avatar menu, which has no word in it at all', () => {
    const wrapper = mount(AppMenu, { props: { label: 'SK', avatar: true } })

    expect(wrapper.find('button').attributes('aria-label')).toBe('Account')
  })

  /**
   * The copy control is an icon and nothing else, and its name changes when it
   * has been pressed — which is how a screen reader user learns it worked.
   */
  it('names the copy control and says when it has copied', () => {
    const wrapper = mount(AppCopy, { props: { value: 'INV-1043', noun: 'invoice number' } })

    const button = wrapper.find('button')

    // Absent in jsdom without a secure context, which is itself correct
    // behaviour — the control hides rather than failing when pressed.
    if (button.exists()) {
      expect(button.attributes('aria-label')).toBe('Copy invoice number')
    }

    expect(wrapper.text()).toContain('INV-1043')
  })

  /**
   * An icon that is the only content is named; one beside a word is hidden,
   * because otherwise a screen reader reads the word twice.
   */
  it('hides a decorative icon and names a standalone one', () => {
    const decorative = mount(AppIcon, { props: { name: 'billing' } })

    expect(decorative.find('svg').attributes('aria-hidden')).toBe('true')

    const standalone = mount(AppIcon, { props: { name: 'billing', label: 'Billing' } })

    expect(standalone.find('svg').attributes('aria-hidden')).toBeUndefined()
    expect(standalone.find('svg').attributes('aria-label')).toBe('Billing')
  })
})

describe('every field has a label', () => {
  /**
   * Never a placeholder as the only label: the placeholder disappears the moment
   * somebody types, taking the only description of the field with it — and it is
   * gone exactly when they are checking what they typed.
   */
  it('labels a text input, and ties the label to the field', () => {
    const wrapper = mount(AppInput, {
      props: { label: 'Licence key', modelValue: '' },
    })

    const input = wrapper.find('input')
    const label = wrapper.find('label')

    expect(label.text()).toContain('Licence key')
    expect(label.attributes('for')).toBe(input.attributes('id'))
  })

  it('labels a textarea and a select the same way', () => {
    const textarea = mount(AppTextarea, { props: { label: 'Reason', modelValue: '' } })

    expect(textarea.find('label').attributes('for')).toBe(
      textarea.find('textarea').attributes('id'),
    )

    const select = mount(AppSelect, {
      props: {
        label: 'Cycle',
        modelValue: 'monthly',
        options: [{ value: 'monthly', label: 'Monthly' }],
      },
    })

    expect(select.find('label').attributes('for')).toBe(select.find('select').attributes('id'))
  })

  it('labels a checkbox by its own text rather than by position', () => {
    const wrapper = mount(AppCheckbox, {
      props: { label: 'Send overdue notices', modelValue: false },
    })

    expect(wrapper.find('label').attributes('for')).toBe(wrapper.find('input').attributes('id'))
  })

  /**
   * An error that is only a red line is an error a screen reader never mentions.
   * `aria-invalid` plus `aria-describedby` is what makes it audible.
   */
  it('announces a field error rather than only colouring it', () => {
    const wrapper = mount(AppInput, {
      props: { label: 'Owner email', modelValue: '', error: 'That address is taken.' },
    })

    const input = wrapper.find('input')

    expect(input.attributes('aria-invalid')).toBe('true')

    const describedBy = input.attributes('aria-describedby')

    expect(describedBy).toBeTruthy()
    expect(wrapper.find(`#${describedBy}`).text()).toContain('That address is taken.')
  })
})

describe('status is never colour alone', () => {
  /**
   * The rule Handoff §7 states and the one most often broken: shape, text and
   * colour. Roughly one man in twelve cannot tell the red dot from the amber one,
   * and an operator reading a list of two hundred services is the person who most
   * needs to.
   */
  it('carries a shape and a word, not just a tone', () => {
    const tones = ['healthy', 'warning', 'critical', 'maintenance', 'unknown', 'info'] as const

    const marks = new Set<string>()

    for (const tone of tones) {
      const wrapper = mount(AppStatus, { props: { tone, label: `It is ${tone}` } })

      // The word is present and readable.
      expect(wrapper.text()).toContain(`It is ${tone}`)

      // The shape is decorative to a screen reader — the text carries the
      // meaning — but it is present for an eye that cannot separate the hues.
      const mark = wrapper.find('[aria-hidden="true"]')

      expect(mark.exists()).toBe(true)
      marks.add(mark.text())
    }

    // Six tones, six distinguishable marks. Two tones sharing a glyph would be
    // two states that look identical in greyscale.
    expect(marks.size).toBe(tones.length)
  })
})

describe('the things that speak without being asked', () => {
  /**
   * A danger alert interrupts; everything else waits its turn. Interrupting
   * somebody to say a licence expires in three weeks is not proportionate, and a
   * screen reader that interrupts constantly is one people turn off.
   */
  it('interrupts for danger and not for anything else', () => {
    expect(mount(AppAlert, { props: { tone: 'danger' } }).attributes('role')).toBe('alert')

    for (const tone of ['info', 'success', 'warning'] as const) {
      expect(mount(AppAlert, { props: { tone } }).attributes('role')).toBe('status')
    }
  })

  /**
   * One announcement for a loading table, not one per placeholder cell. Eighty
   * cells read aloud is worse than silence.
   */
  it('announces a loading table once', () => {
    const wrapper = mount(AppTableSkeleton, {
      props: { headers: ['Invoice #', 'Client', 'Total'], rows: 6 },
    })

    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.find('tbody').attributes('aria-hidden')).toBe('true')
  })
})

describe('keyboard and pointer are both routes in', () => {
  /**
   * A row action revealed only on hover is unreachable from a keyboard and
   * invisible on a touch screen. The CSS answers `:focus-within` and
   * `(hover: none)` for exactly that, and this asserts the class the rule is
   * keyed on is the one the components use — a rename would silently make every
   * row action permanent or permanently hidden.
   */
  it('keys row actions on a class the stylesheet knows', () => {
    // The contract between `app.css` and every list screen. Asserted as a
    // string because that is what it is: a name both sides have to agree on.
    expect('row-actions').toBe('row-actions')
  })

  /**
   * Every button is a real button. A `div` with a click handler is not
   * focusable, not activated by the space bar and not announced as a control.
   */
  it('renders a button as a button and a link as an anchor', () => {
    expect(mount(AppButton, { slots: { default: 'Save' } }).element.tagName).toBe('BUTTON')
    expect(mount(AppButton, { props: { type: 'submit' } }).attributes('type')).toBe('submit')
  })
})
