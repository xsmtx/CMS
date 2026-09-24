# Realtime Register

Registers, transfers, renews and manages domains through Realtime Register.

## Availability is three-valued and stays that way

A registry that did not answer has **not** said a name is free (ADR 0028). A
timeout, a 500 and a malformed answer all produce `unknown`, never `available`.
Collapsing the third state is how a customer pays for a name somebody else owns.

## A contact is created before a domain

Realtime Register addresses registrants by **handle**, not by a block of fields.
The handle is derived from the registrant's email, so the same person registering
a second domain reuses the first handle rather than accumulating one per name.

## The transfer code is never stored

It is fetched, returned once to be shown once, and no copy is kept. The registry
holds the truth, and a copy here is a copy to leak.

## What it needs

| Setting | What it is |
| --- | --- |
| Customer handle | The reseller handle that **owns** the domains. Not the same as the API key — the key says who is calling, the handle says who owns. Getting it wrong registers names under the wrong reseller. |
| API key | Sent as `Authorization: ApiKey …`. |
| Sandbox (OT&E) | Switches to the test environment. |

## Unproven

**This module has never talked to Realtime Register.**
