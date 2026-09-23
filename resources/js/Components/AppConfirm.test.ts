import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import AppConfirm from './AppConfirm.vue'

/**
 * §8's confirmation ladder. The tests are about the guards, because a
 * confirmation whose guard does not hold is worse than none: it teaches an
 * operator that the dialog is a formality, and then the one that mattered is
 * clicked through too.
 */
enableAutoUnmount(afterEach)

afterEach(() => {
  document.body.innerHTML = ''
})

function dialog(): HTMLElement | null {
  return document.querySelector('[role="alertdialog"]')
}

/**
 * The dialog is teleported, so `wrapper.find` cannot reach into it and
 * `setValue` has to be done by hand: set the value, then say so, which is
 * what `v-model` is listening for.
 */
async function type(selector: string, value: string): Promise<void> {
  const field = dialog()?.querySelector(selector)

  if (!(field instanceof HTMLTextAreaElement || field instanceof HTMLInputElement)) {
    throw new Error(`No ${selector} in the dialog`)
  }

  field.value = value
  field.dispatchEvent(new Event('input'))

  await Promise.resolve()
}

function buttonSaying(text: string): HTMLButtonElement | null {
  return (
    [...(dialog()?.querySelectorAll('button') ?? [])].find(
      (button) => button.textContent?.trim() === text,
    ) ?? null
  )
}

describe('AppConfirm', () => {
  it('asks nothing until it is opened', () => {
    mount(AppConfirm, { props: { open: false, title: 'Suspend the service' } })

    expect(dialog()).toBeNull()
  })

  /**
   * Level 2. One press, and the button is the primary one — a consequential
   * action is not a dangerous one, and painting it red spends the colour
   * that levels 3 and 4 need to still mean something.
   */
  it('confirms a consequential action on one press, with no reason', async () => {
    const wrapper = mount(AppConfirm, {
      props: { open: true, title: 'Issue the invoice', level: 'consequential' },
    })

    await wrapper.vm.$nextTick()

    expect(dialog()?.querySelector('textarea')).toBeNull()

    const confirm = buttonSaying('Confirm')

    expect(confirm?.disabled).toBe(false)

    confirm?.click()

    expect(wrapper.emitted('confirm')?.[0]).toEqual([null])
  })

  /**
   * Level 3. The reason goes to the audit record, so an empty one is not a
   * confirmation — and the button says so by being unpressable rather than
   * by complaining after the fact.
   */
  it('will not confirm a high-risk action without a reason', async () => {
    const wrapper = mount(AppConfirm, {
      props: { open: true, title: 'Refund the payment', level: 'high-risk' },
    })

    await wrapper.vm.$nextTick()

    expect(buttonSaying('Confirm')?.disabled).toBe(true)

    // Whitespace is not a reason.
    await type('textarea', '   ')
    expect(buttonSaying('Confirm')?.disabled).toBe(true)

    await type('textarea', '  Duplicate charge  ')
    expect(buttonSaying('Confirm')?.disabled).toBe(false)

    buttonSaying('Confirm')?.click()

    // Trimmed on the way out: the audit record should not carry the
    // operator's stray spaces.
    expect(wrapper.emitted('confirm')?.[0]).toEqual(['Duplicate charge'])
  })

  /**
   * Level 4. Typing the name is the only guard muscle memory cannot get
   * through, because there is nothing to click.
   */
  it('makes a destructive action be typed out, exactly', async () => {
    const wrapper = mount(AppConfirm, {
      props: {
        open: true,
        title: 'Delete the customer',
        level: 'destructive',
        phrase: 'Acme Ltd',
      },
    })

    await wrapper.vm.$nextTick()

    await type('textarea', 'Duplicate record')
    expect(buttonSaying('Delete')?.disabled).toBe(true)

    // Close is not close enough.
    await type('input', 'acme ltd')
    expect(buttonSaying('Delete')?.disabled).toBe(true)

    await type('input', 'Acme Ltd')
    expect(buttonSaying('Delete')?.disabled).toBe(false)
  })

  it('never makes the dangerous button the primary one', async () => {
    const wrapper = mount(AppConfirm, {
      props: { open: true, title: 'Terminate the service', level: 'destructive', phrase: 'web-01' },
    })

    await wrapper.vm.$nextTick()

    expect(buttonSaying('Delete')?.className).toContain('bg-danger')
    expect(buttonSaying('Delete')?.className).not.toContain('bg-brand')
  })

  /**
   * A dialog that could be confirmed twice is a payment recorded twice. The
   * guard is the same one that stops it being cancelled mid-flight.
   */
  it('refuses a second press while the first is in flight', async () => {
    const wrapper = mount(AppConfirm, {
      props: { open: true, title: 'Record the payment', busy: true },
    })

    await wrapper.vm.$nextTick()

    buttonSaying('Confirm')?.click()
    expect(wrapper.emitted('confirm')).toBeUndefined()

    buttonSaying('Cancel')?.click()
    expect(wrapper.emitted('update:open')).toBeUndefined()
  })

  it('forgets what was typed when it is dismissed', async () => {
    const wrapper = mount(AppConfirm, {
      props: { open: true, title: 'Refund the payment', level: 'high-risk' },
    })

    await wrapper.vm.$nextTick()
    await type('textarea', 'Wrong amount')

    await wrapper.setProps({ open: false })
    await wrapper.setProps({ open: true })
    await wrapper.vm.$nextTick()

    // A reason left over from the last thing somebody nearly did would be
    // written to the audit record of this one.
    expect(dialog()?.querySelector('textarea')?.value).toBe('')
  })
})
