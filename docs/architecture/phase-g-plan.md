# Phase G — The datacenter: hardware, power, and the people who touch it

Status: planned
Date: 2026-09-28
Previous: `phase-f-result.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §11, §18
Umbrella: `advanced-operations-plan.md` §9 (G)

F was about the machine under the customer. G is about **the physical world
under the machine**: which rack it is in, which two feeds power it, how warm
that room is, and who has to walk over and replace the disk.

It is the first phase whose subject this platform cannot discover. A rack is
not an API; somebody types it in.

---

## 1. What ships

1. **The DCIM spine** (§11) — Datacenter → Room → Row → Rack → U → Device,
   with free U and the elevation that follows from it.
2. **Hardware inventory** (§11) — serial, asset tag, purchase and warranty
   dates, location, and the replacement history that outlives any one machine.
3. **Power path in the graph** (§11) — A and B feeds from a PDU outlet to a
   device, so "what does this breaker carry" is a walk.
4. **PDU, UPS and environment adapters** (§11) — outlet load, battery and
   runtime, temperature and humidity, through the metric normalizer that
   already exists.
5. **Remote Hands** (§11) — a task with a rack, a device, a technician, the
   part, the serials before and after, and the evidence.
6. **WordPress fleet** (§18) — discovered installations with their core,
   plugin and theme versions, and what is out of date.

Not the visual rack elevation as a drawing. §11 says "and later visual rack
elevation" in as many words, and a first cut that lists U positions answers
every question the drawing would, without a canvas nobody has designed.

## 2. The decisions

### DCIM is core, and it is rows an operator types

There is no adapter for a rack. Everything in the first three items is
somebody's knowledge of their own building, and the platform's job is to hold
it without an opinion: core ships no rack sizes, no naming convention and no
assumption that a datacenter has rooms. A single-room provider makes one room
and stops thinking about it.

That makes DCIM the opposite of every family since Phase B, and the reason is
the same one that made IPAM core in Phase C: **a module cannot draw a screen**,
and this is almost entirely screens.

### A device is a `servers` row or it is nothing

The temptation is a `devices` table holding everything in the rack. It would
immediately be a second answer to "what servers do we have", and ADR 0043's
rule already says how that ends.

So: a rack position points at a `servers` row where there is one, and carries
a free-text label where there is not — a switch, a PDU, a patch panel, a
blanking plate. What core adds is **where it is**, which is the fact no other
table holds.

### Hardware inventory is about parts, not about machines

A machine is a `servers` row. A **part** — a disk, a DIMM, a PSU, an optic —
has its own serial, its own warranty, and a history of being moved from one
machine to another. That is what §11 asks for and it is why the parts table is
not the machines table: the whole value is that a disk outlives the machine it
was first fitted to, and "where has this serial been" is the question a
warranty claim turns on.

**A part is never deleted.** Replacing one writes a new row and closes the
old one's fitting, exactly as `ip_assignments` does for an address — because
the question is always historical.

### Power is an edge, and A/B is the whole point

A device fed by one PDU is a device that goes down when that PDU does; a
device fed by two is not. `Relation::Powers` already exists in the graph and
has never been used. This is what it was for.

The edge is from the **outlet** to the device, not from the PDU: two devices
on one PDU are usually on different breakers, and a platform that could not
say which would be unable to answer the only question anybody asks of a power
diagram.

### Environment readings are telemetry, and the sensor is a node

Temperature, humidity, airflow, battery charge and runtime are numbers and go
through the normalizer — most of their `MetricKind` members already exist from
Phase A. What is new is a handful for the things a rack has and a server does
not: humidity, airflow, and the states a leak or smoke detector reports, which
are **not** numbers and are therefore an attribute on the node rather than a
metric.

### Remote Hands is a record before it is a request

The same shape as `network_changes` and for the same reason: who asked, why,
which device, which part, what the serials were before and after, and what the
technician saw. §11 mentions physical-access windows linking to change
records, and that link is the reason the record exists at all — an audit that
says a machine was opened is worth more than a ticket that says somebody was
asked to open it.

**The evidence is a reference, never a file store.** Phase E's abuse desk
already settled that: core holds a pointer and a retention clock, not a
photograph.

### The WordPress fleet is a module, and it discovers rather than manages

§18 asks for versions, vulnerable components and update operations. The read
is a module — a panel knows where the installations are — and the *write* half
(update, maintenance mode) is deferred to the phase that has a guarded
workflow for "change somebody's site", which G does not.

What core adds is the seam: a `site` resource kind, the versions on its node,
and an alert subject for "out of date", so an operator gets told through the
mechanism they already have.

## 3. Schema (core)

| Table | Why core |
| --- | --- |
| `datacenters`, `rooms`, `rows`, `racks` | Somebody's building. No adapter can discover it. |
| `rack_positions` | Which U a device occupies, pointing at a `servers` row or carrying a label. |
| `hardware_parts` | Serial, asset tag, warranty; the row that outlives the machine. |
| `part_fittings` | Append-only: which part was in which machine, between when and when. |
| `remote_hands_tasks` | Who asked, which device, which part, the serials, the evidence. |

PDU outlets, UPS units and environment sensors are **graph nodes and
telemetry**, not tables — ADR 0043's rule for the fifth time.

## 4. Authorization

- `dcim.view` — Support holds it. "Which rack is this customer on" is a
  support question during an incident.
- `dcim.manage` — the building's layout. Not Support's.
- `dcim.parts.manage` — fitting and replacing parts.
- `dcim.remote_hands.request` — Support holds it; asking for a disk to be
  swapped is exactly what the person on the telephone needs.
- `dcim.remote_hands.complete` — the technician's half, with the serials.

No password challenge anywhere in this phase. Nothing here changes what a
customer is served: the destructive act is somebody physically pulling a
disk, and this platform records that rather than causing it.

## 5. Sequence

1. The DCIM spine and its screens, with the elevation as a U list.
2. Hardware parts and fittings, with the replacement history.
3. `Relation::Powers` edges, the PDU/UPS/environment contracts, and the one
   worked module.
4. Remote Hands.
5. The WordPress fleet contract and its module.

## 6. Not in this phase

- **A drawn rack elevation.** §11 says "and later".
- **Write operations on a WordPress site.** They need a guarded workflow for
  changing somebody else's site, and the one this product has is for network
  devices.
- **Capacity planning for power.** `CapacityForecast` would answer it from the
  outlet metrics once they exist, and pointing it at a new dimension is a
  smaller thing than building this phase.

## 7. The standing limitation

Unchanged, with one addition worth stating: **most of this phase has no
adapter at all**, which removes the usual caveat and replaces it with a
different one. A rack diagram that does not match the building is worse than
none, because somebody will send a technician to the wrong cabinet. Nothing in
core can detect that, and no test can either — the check is the first time an
operator walks the room with the screen open.
