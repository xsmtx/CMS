# 0044 — Mobile is React Native, and a separate artefact

Status: accepted
Date: 2026-09-24

## Context

Handoff #2 §26 requires iOS and Android applications for customers, resellers and
provider staff, and asks explicitly for this decision to be taken in an ADR after
evaluating team skills, accessibility, native integration and release tooling.
Both apps must call the same `/api/v1` and the same application layer; no phone
talks to a provider or a device directly.

The choice is Flutter or React Native. Both ship real apps to both stores, both
have mature push, biometrics, secure-storage and deep-link support, and both are
used by companies larger than any this product will serve. Picking on
architecture alone would be a coin toss, which is why the handoff names four
criteria instead.

**Team skills.** This repository is TypeScript from end to end: Vue 3 SFCs,
Vitest, ESLint, Prettier and `vue-tsc`, with `docs/api/openapi.json` generated
from the routes. Vue is not React, and that difference is real but small — JSX
takes a week. Dart is a second language, a second package ecosystem, a second
formatter, a second test runner and a second set of CI steps, for a codebase
whose entire quality gate is currently one `composer check` and one `npm run`
sequence. More concretely: the API response types can be *generated from the same
OpenAPI document the web client uses* and imported directly. That is not a
convenience, it is the mechanism that stops the app and the API drifting.

**Accessibility.** React Native renders real platform views, so
`accessibilityLabel`, `accessibilityRole` and the platform's own focus order come
from the system. Flutter draws its own widget tree and bridges it to the platform
through a semantics layer; it is genuinely good now and it is still a translation.
Given that this product's accessibility work so far has been tests over
primitives rather than an audit by a person, the surface that inherits the
platform's behaviour by default is the safer one.

**Native integration.** The staff app needs barcode and QR scanning for asset
lookup (§26), Keychain/Keystore, biometric unlock, APNs and FCM, universal links
and certificate handling. Expo covers every one of those with maintained config
plugins. Flutter covers them too, mostly through community packages of varying
health.

**Release tooling.** This is what settles it. The work is being done on Windows,
and an iOS release build needs macOS. Expo's EAS Build compiles both platforms in
the cloud, signs, and submits — first-party, and the reason a one-person or
small team can actually ship an iOS app at all here. Flutter's equivalent is
Codemagic or a rented Mac: workable, and one more vendor.

## Decision

**React Native with Expo and TypeScript**, and the apps live in a **separate
repository**, consuming `/api/v1` and the generated API types.

The second half of that sentence matters as much as the first. Nothing about the
mobile client enters this repository except:

- the API surface it calls, which is already the product's contract;
- the push notification categories, which are a core concern —
  `NotificationEvent` and a `push` channel through `Notifier` (ADR 0029), not a
  mobile feature;
- the `docs/api/openapi.json` that generates its types, which is generated and
  checked in CI already.

## Consequences

**The app cannot be built until a staff API exists, and that is a security
decision rather than a mobile one.** ADR 0033 puts an API token's contact on the
`client` guard precisely so that `CurrentActor`, `CurrentCustomer` and every
policy behave identically for a browser and a token. There is no staff-guard
equivalent, and there should not be one invented in passing while writing a phone
screen. Phase I opens with an ADR for it: what a staff token may hold, how scopes
narrow it, how it expires, and how it is revoked from a lost device. The customer
app can be built against today's API; the staff app cannot.

**Tokens must gain a lifetime.** Today's API tokens do not expire, which is
correct for a server-to-server integration and wrong on a phone that gets left in
a taxi. Short-lived access plus refresh rotation, and a device inventory with
remote revocation, land with the staff API — not as a mobile library's concern.

**Step-up authentication already exists and is reused.** Phase 17's
`RequireRecentAuthentication` is the mechanism §26 asks for when a phone requests
a device reboot or a termination. Biometric unlock stays what the handoff says it
is: a local convenience, never the server's proof of who is asking.

**Some actions stay web-only, by policy and in code.** Firewall configuration,
mass actions and physical power control are the examples §26 names. That is not
enforced by leaving a button out of the app — it is enforced by the API refusing,
because an app is a client and a client can be rewritten.

**Offline is read-only plus a narrow queue.** Cached dashboards and Remote Hands
task data, and queued actions only where replay is provably safe. This is ADR
0034's ground already: a write is replayable because it carries an
`Idempotency-Key`. Destructive infrastructure changes are never queued offline,
because a queued reboot that fires when a technician regains signal is a reboot
nobody chose.

**If the team that ships this is a Flutter team, this ADR is superseded rather
than ignored.** The evaluation above is about *this* codebase's skills — the
criteria are recorded so that a different answer can be argued against the same
four questions instead of by preference.

## Alternatives rejected

- **Flutter.** Better rendering consistency and a genuinely pleasant framework;
  a second language, a second toolchain, no shared types with the API client, and
  an iOS release path that needs a Mac or a third party.
- **A progressive web app.** No APNs critical alerts, no reliable background
  push on iOS, no barcode scanning worth shipping to a datacenter technician, and
  no store presence. §26 asks for two apps.
- **A native app per platform.** Two codebases for the same six screens, for a
  product whose UI budget is already spread across an admin panel, a client area
  and a storefront.
- **A mobile directory inside this repository.** It would put a second toolchain,
  a second lint config and a second test runner inside `composer check`, and tie
  an app-store release cadence to a platform release. The API is the seam; using
  it is the point.
