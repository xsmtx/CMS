# İyzico

Takes card payments through [İyzico](https://www.iyzico.com) using its hosted
checkout form.

## What it needs

| Setting | What it is |
| --- | --- |
| API key | From the İyzico merchant panel. |
| Secret key | Signs every request. |
| Sandbox | Switches the base URL. Not a text field on purpose — a live key pointed at the sandbox takes no payment, and a sandbox key pointed at live takes one nobody can explain. |

## Two things worth knowing

**The signature covers the request body**, so the bytes signed must be the bytes
sent. The payload is encoded once and that same string is both hashed and
posted; encoding it twice is how a signature passes in a test and fails against
the real service, because two encoders disagree about a slash.

**Amounts are decimal strings.** `Money::toDecimalString()` produces `"12.34"`
from integer minor units without a float ever existing.

## What moves money

Not the redirect. İyzico returns a token to the callback URL and the truth is
fetched by asking İyzico about that token, server to server — which is what
ADR 0024 requires. The token is the event id, so a customer who reloads the
return page is deduplicated rather than counted twice.

## Unproven

**This module has never talked to İyzico.** Written against the published
documentation and tested against faked HTTP, which proves the code and not the
integration.
