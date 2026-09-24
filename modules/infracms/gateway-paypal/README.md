# PayPal

Takes payments through PayPal's Orders v2 API.

## What it needs

| Setting | What it is |
| --- | --- |
| Client ID / secret | From the app in the PayPal developer dashboard. |
| Webhook ID | From that app's webhook. **Required** — without it a notification cannot be verified, so none is believed. |
| Sandbox | Switches the base URL. |

## An order is not a payment

PayPal creates an order, the customer approves it, and the money moves only when
the order is **captured**. Reporting the created order as a completed payment is
the classic PayPal integration bug: every order looks paid and none of the money
arrives. `createPayment` always returns *pending* with the approval link, and the
capture is what the webhook reports.

## Verification is a network call

There is no local signature to check. The headers, the webhook id and the event
are sent back to PayPal and it answers SUCCESS or FAILURE. So a verification that
**cannot be made** is a notification that is **not believed** — never one assumed
good because the service was down. The alternative turns an outage at PayPal into
a way to mark invoices paid.

## Unproven

**This module has never talked to PayPal.** Written against the published
documentation and tested against faked HTTP.
