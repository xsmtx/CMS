import { describe, expect, it } from 'vitest'

import { httpTone, statusTone } from './status'

describe('statusTone', () => {
  it('maps every status word the pages send', () => {
    const words: Record<string, string> = {
      // Health
      ok: 'healthy',
      degraded: 'warning',
      failing: 'critical',
      // Domains
      registering: 'info',
      transfer_pending: 'info',
      transferring: 'info',
      redemption: 'critical',
      // Orders
      awaiting_payment: 'warning',
      payment_review: 'warning',
      fraud_review: 'warning',
      partially_fulfilled: 'warning',
      // Tickets
      open: 'warning',
      customer_reply: 'warning',
      answered: 'healthy',
      on_hold: 'neutral',
      closed: 'neutral',
      // Servers
      full: 'warning',
      unreachable: 'critical',
      // Catalog
      hidden: 'neutral',
      retired: 'neutral',
      // Cancellations, order payment
      withdrawn: 'neutral',
      unbilled: 'neutral',
    }

    for (const [word, tone] of Object.entries(words)) {
      expect(statusTone(word), word).toBe(tone)
    }
  })

  it('is case- and separator-insensitive', () => {
    expect(statusTone('On Hold')).toBe('neutral')
    expect(statusTone('manual-intervention')).toBe('critical')
  })

  it('says unknown for a word it has never seen, and for nothing', () => {
    expect(statusTone('sideways')).toBe('unknown')
    expect(statusTone(null)).toBe('unknown')
    expect(statusTone(undefined)).toBe('unknown')
  })
})

describe('httpTone', () => {
  it('reads a response code', () => {
    expect(httpTone(200)).toBe('healthy')
    expect(httpTone(204)).toBe('healthy')
    expect(httpTone(302)).toBe('neutral')
    expect(httpTone(404)).toBe('warning')
    expect(httpTone(503)).toBe('critical')
  })
})
