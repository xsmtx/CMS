# Plesk

Creates, suspends, upgrades and terminates Plesk subscriptions on a server in the
fleet.

## A subscription is not an account

Conflating them is the mistake that makes a Plesk integration behave oddly six
months in. A customer is a **client**; the thing they bought is a **domain** with
a service plan attached. Creating makes the client and then the domain under it;
terminating removes the **domain only** — the client may still have three other
subscriptions on that server.

## Suspension is a property, not a verb

There is no suspend endpoint. A subscription is disabled by setting `enabled` to
false, which is why suspend and unsuspend are one method with a boolean.

## What it needs

| Setting | What it is |
| --- | --- |
| Create subscriptions under | A Plesk reseller login. Empty means whoever the server connection logs in as — right for a single-tenant server, wrong for a multi-tenant one, and getting it wrong is a subscription the reseller cannot see. |

Credentials come from the server row, not from here.

## Unproven

**This module has never talked to a Plesk server.**
