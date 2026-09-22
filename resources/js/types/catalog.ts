/**
 * Catalog shapes shared between the admin screens.
 *
 * Amounts are always minor units, matching the server. The browser never
 * holds a price as a float: `1999` is unambiguous where `19.99` is not.
 */

export interface CycleOption {
  value: string
  label: string
  recurring: boolean
}

export interface CurrencyOption {
  code: string
  symbol: string | null
  exponent: number
  isBase: boolean
}

export interface PriceCell {
  billingCycle: string
  currencyCode: string
  recurringMinor: number
  setupMinor: number
}

/**
 * Minor units to the decimal string an operator reads.
 *
 * String arithmetic, not division: 1999 / 100 is 19.99 today and something
 * with a trailing 0000000001 the moment the exponent is 3.
 */
export function toDecimal(minor: number, exponent: number): string {
  if (exponent === 0) return String(minor)

  const negative = minor < 0
  const digits = String(Math.abs(minor)).padStart(exponent + 1, '0')

  return `${negative ? '-' : ''}${digits.slice(0, -exponent)}.${digits.slice(-exponent)}`
}

/**
 * What an operator typed, back to minor units.
 *
 * Returns null for anything that is not a number, so the caller can leave
 * the field alone rather than turning a typo into a zero. Extra decimals are
 * dropped rather than rounded, and the field re-renders so the operator sees
 * exactly what will be stored.
 */
export function toMinor(text: string, exponent: number): number | null {
  const normalised = text.trim().replace(',', '.')

  if (normalised === '' || !/^-?\d*(\.\d*)?$/.test(normalised)) return null

  const negative = normalised.startsWith('-')
  const [whole = '', fraction = ''] = normalised.replace('-', '').split('.')
  const padded = (fraction + '0'.repeat(exponent)).slice(0, exponent)
  const minor = Number(`${whole === '' ? '0' : whole}${padded}`)

  if (!Number.isSafeInteger(minor)) return null

  return negative ? -minor : minor
}

export function cellKey(cycle: string, currency: string): string {
  return `${cycle}:${currency}`
}

/**
 * The wire shape the server validates: snake_case keys, minor units.
 *
 * Three screens submit a price matrix. Each one spelling the keys out again
 * is how one of them ends up silently rejected, so the mapping lives here
 * and is tested.
 */
export interface PricePayload {
  billing_cycle: string
  currency_code: string
  recurring_minor: number
  setup_minor: number
}

export function toPricePayload(cells: PriceCell[]): PricePayload[] {
  return cells.map((cell) => ({
    billing_cycle: cell.billingCycle,
    currency_code: cell.currencyCode,
    recurring_minor: cell.recurringMinor,
    setup_minor: cell.setupMinor,
  }))
}

/**
 * The first validation error touching the price matrix, whatever row it
 * landed on. Laravel reports `prices.0.recurring_minor`; an operator needs
 * to be told something went wrong, not which array index.
 */
export function firstPriceError(errors: Record<string, string>): string | undefined {
  const key = Object.keys(errors).find((name) => name === 'prices' || name.startsWith('prices.'))

  return key === undefined ? undefined : errors[key]
}
