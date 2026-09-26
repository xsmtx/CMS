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
    // An IP address doing its job: somebody is using it.
    'assigned',
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
    // A device change on its way to the box. Not a warning - it is doing
    // exactly what somebody asked it to.
    'applying',
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
    // A device change that has been written down and is waiting on a person:
    // somebody has to look, which is what warning means here.
    'awaiting_approval',
    'authorized',
    // The device did not keep the configuration and the backup went back on.
    // Not a failure - the box is where it started - and not success either.
    'rolled_back',
    'customer_reply',
    'installed',
    'expiring',
    'payment_review',
    'fraud_review',
    'review',
    'partially_fulfilled',
    'full',
    // An address that has been released and is cooling off. Not free yet and
    // not a failure - somebody should wait rather than act.
    'quarantined',
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
  // Down on purpose - which is exactly what a reserved address is: an
  // operator set it aside, and it must not look like a free one.
  maintenance: ['maintenance', 'reserved'],
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
    // Somebody said no, which is a decision rather than a fault.
    'rejected',
    'on_hold',
    'hidden',
    'retired',
    'withdrawn',
    'unbilled',
    // A free address is not in play. It is distinct from reserved on the
    // screen because the difference is the whole point of having both.
    'available',
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
