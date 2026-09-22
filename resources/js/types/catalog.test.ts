import { describe, expect, it } from 'vitest'

import { firstPriceError, toDecimal, toMinor, toPricePayload } from './catalog'

describe('toDecimal', () => {
  it('renders two-decimal currencies', () => {
    expect(toDecimal(1999, 2)).toBe('19.99')
    expect(toDecimal(5, 2)).toBe('0.05')
    expect(toDecimal(0, 2)).toBe('0.00')
  })

  it('renders a currency with no minor unit', () => {
    expect(toDecimal(1500, 0)).toBe('1500')
  })

  it('renders a currency with three decimals', () => {
    expect(toDecimal(1234, 3)).toBe('1.234')
  })

  it('keeps the sign on a negative amount', () => {
    // Option prices are signed: "no control panel" is a reduction.
    expect(toDecimal(-300, 2)).toBe('-3.00')
  })
})

describe('toMinor', () => {
  it('parses what an operator types', () => {
    expect(toMinor('19.99', 2)).toBe(1999)
    expect(toMinor('19', 2)).toBe(1900)
    expect(toMinor('.5', 2)).toBe(50)
    expect(toMinor('-3', 2)).toBe(-300)
  })

  it('accepts a comma as the decimal separator', () => {
    expect(toMinor('19,99', 2)).toBe(1999)
  })

  it('drops precision the currency does not have rather than rounding it', () => {
    // The field re-renders afterwards, so the operator sees what was kept.
    expect(toMinor('19.999', 2)).toBe(1999)
    expect(toMinor('1500.75', 0)).toBe(1500)
  })

  it('returns null for anything that is not a number', () => {
    expect(toMinor('', 2)).toBeNull()
    expect(toMinor('abc', 2)).toBeNull()
    expect(toMinor('1.2.3', 2)).toBeNull()
  })

  it('round-trips through toDecimal', () => {
    for (const minor of [0, 5, 99, 1999, -300, 123456]) {
      expect(toMinor(toDecimal(minor, 2), 2)).toBe(minor)
    }
  })
})

describe('toPricePayload', () => {
  it('maps to the keys the server validates', () => {
    // A camelCase payload is rejected field by field, which is silent unless
    // the screen goes looking for nested errors.
    expect(
      toPricePayload([
        { billingCycle: 'monthly', currencyCode: 'EUR', recurringMinor: 999, setupMinor: 0 },
      ]),
    ).toEqual([
      { billing_cycle: 'monthly', currency_code: 'EUR', recurring_minor: 999, setup_minor: 0 },
    ])
  })

  it('maps an empty matrix to an empty list, which means not sold', () => {
    expect(toPricePayload([])).toEqual([])
  })
})

describe('firstPriceError', () => {
  it('finds an error on a matrix row', () => {
    expect(firstPriceError({ 'prices.0.recurring_minor': 'Required.' })).toBe('Required.')
  })

  it('finds an error on the matrix itself', () => {
    expect(firstPriceError({ prices: 'Cannot be negative.' })).toBe('Cannot be negative.')
  })

  it('ignores errors belonging to other fields', () => {
    expect(firstPriceError({ name: 'Required.' })).toBeUndefined()
  })
})
