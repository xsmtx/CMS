# PayTR

Takes card payments through [PayTR](https://www.paytr.com), the Turkish payment
provider.

## What it is

A `PaymentGateway`, registered under the key `paytr`. It hands the customer a
PayTR payment page and waits for the callback; it never sees a card number and
never stores one.

## What it needs

| Setting | What it is |
| --- | --- |
| Merchant ID | From the PayTR merchant panel. |
| Merchant key | Signs every request. |
| Merchant salt | Salts every hash. **Never transmitted** — that is why it is separate from the key. |
| Test mode | Sends `test_mode=1`; nothing is really charged. |
| Allow instalments | Whether the payment page offers them. |

## What it deliberately cannot do

**It cannot charge a stored card.** PayTR hands back a payment page, not a token
this platform may charge later, so `unattendedCharges` is false. A renewal is
therefore something the customer is asked to pay, not something taken from them.
Declaring otherwise would make the renewal sweep raise invoices it then silently
failed to collect.

## What moves money

The **callback**, and only the callback. A customer returning to the success URL
proves nothing (ADR 0024). The callback's hash is verified before the payload is
read, and a callback that does not verify is not parsed at all.

PayTR sends no event id, so the merchant order id plus the status is the
deduplication key — which is what makes a repeated callback idempotent.

## Unproven

**This module has never talked to PayTR.** Its request shapes and its hashing are
written against the published documentation and tested against faked HTTP, which
proves the code and not the integration. Treat the first real payment as a test.
