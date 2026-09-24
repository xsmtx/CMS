/**
 * The one status vocabulary.
 *
 * Every screen used to carry its own `tone()` function, and they disagreed:
 * a closed customer was red and a closed ticket grey; an expired domain was
 * red on the list and amber on its own page. An operator learns colours from
 * the whole product, so a colour that means two things on two screens means
 * nothing on either.
 *
 * So a status word is mapped **here**, once, to one of seven tones, and a
 * screen asks `statusTone(row.status)`. A word that is not listed is
 * `unknown` — visibly, rather than silently borrowing a colour.
 *
 * | Tone          | Means                                       | Mark |
 * | ------------- | ------------------------------------------- | ---- |
 * | `healthy`     | Working, or finished the way it should.     | ●    |
 * | `info`        | Moving: something is running right now.     | ◐    |
 * | `warning`     | Working, and somebody should look.          | ▲    |
 * | `critical`    | Not working. Somebody must act.             | ■    |
 * | `maintenance` | Down on purpose.                            | ◆    |
 * | `neutral`     | Out of play: closed, cancelled, archived.   | □    |
 * | `unknown`     | The check did not answer.                   | ○    |
 *
 * Adding a word: put it under the tone whose sentence it matches, not the
 * colour you would like it to be. "Closed" is not a failure; "expired" is.
 */
import type { StatusTone } from './Components/AppStatus.vue'

export type { StatusTone }

const VOCABULARY: Record<Exclude<StatusTone, 'unknown'>, readonly string[]> = {
  healthy: [
    'active',
    'online',
    'healthy',
    'ok',
    'enabled',
    'completed',
    'succeeded',
    'already_done',
    'processed',
    'sent',
    'delivered',
    'paid',
    'answered',
    'done',
    'verified',
    'issued',
    'allow',
  ],
  info: [
    'running',
    'provisioning',
    'registering',
    'retrying',
    'in_progress',
    'queued',
    'transfer_pending',
    'transferring',
  ],
  warning: [
    'warning',
    // A customer the platform cannot bill or provision until somebody
    // supplies details (CustomerStatus::InformationRequired).
    'information_required',
    'degraded',
    'pending',
    'suspended',
    'grace_period',
    'cancel_pending',
    'unpaid',
    'partially_paid',
    'awaiting_payment',
    'open',
    'customer_reply',
    'installed',
    'expiring',
    'payment_review',
    'fraud_review',
    'review',
    'partially_fulfilled',
    'full',
  ],
  critical: [
    'critical',
    'failed',
    'offline',
    'blocked',
    'deny',
    'terminated',
    'expired',
    'redemption',
    'overdue',
    'manual_intervention',
    'deleted',
    'error',
    'failing',
    'unreachable',
  ],
  maintenance: ['maintenance'],
  neutral: [
    'closed',
    'cancelled',
    'archived',
    'disabled',
    'inactive',
    'draft',
    'refunded',
    'partially_refunded',
    'suppressed',
    'ignored',
    'duplicate',
    'on_hold',
    'hidden',
    'retired',
    'withdrawn',
    'unbilled',
  ],
}

const LOOKUP = new Map<string, StatusTone>(
  Object.entries(VOCABULARY).flatMap(([tone, words]) =>
    words.map((word) => [word, tone as StatusTone] as const),
  ),
)

/** The tone for a status word. Case- and separator-insensitive. */
export function statusTone(status: string | null | undefined): StatusTone {
  if (status === null || status === undefined) return 'unknown'

  const key = status
    .trim()
    .toLowerCase()
    .replace(/[\s-]+/g, '_')

  return LOOKUP.get(key) ?? 'unknown'
}

/**
 * An HTTP response code as a tone. Kept apart from the words above because
 * a number is not a state a record is in — it is how one request went.
 */
export function httpTone(code: number): StatusTone {
  if (code >= 500) return 'critical'
  if (code >= 400) return 'warning'
  if (code >= 200 && code < 300) return 'healthy'

  return 'neutral'
}
