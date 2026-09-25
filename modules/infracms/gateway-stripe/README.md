# Stripe

Card payments through Stripe Checkout, confirmed from a signed webhook.

## Configuration

| Field | What it is |
| --- | --- |
| Secret key | `sk_live_…` or `sk_test_…`. Never the publishable key. |
| Webhook signing secret | `whsec_…`, shown when the endpoint is created in Stripe. |
| API base | Left alone outside a test harness. |

Point a Stripe webhook endpoint at `/webhooks/stripe` and subscribe it to
`checkout.session.completed` and `payment_intent.payment_failed`.

**Both keys or neither.** Without the signing secret this gateway is not
registered at all: a redirect back from Stripe proves nothing (ADR 0024), so a
payment that cannot be confirmed from a signed webhook is a payment this
platform will not record.

## Until it has talked to Stripe

This adapter's request shapes, retries and error handling are tested against
faked HTTP, which proves the code and not the integration. Treat the first
real payment as a test: take one, confirm the invoice settles from the
webhook, and refund it.
