# Phase G — the datacenter: what shipped

Status: complete
Date: 2026-09-29
Plan: `phase-g-plan.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §11, §18

All five steps of the plan's sequence are in. Phases H to J are not started.

---

## 1. What shipped

| § | Thing | Where |
| --- | --- | --- |
| 11 | The DCIM spine, with the elevation as a U list | `datacenters`, `rooms`, `rows`, `racks`, `rack_positions`, `PlaceDevice`, `Admin/Dcim/Index`, `Admin/Dcim/Rack` |
| 11 | Hardware parts, and where each of them has been | `hardware_parts`, `part_fittings`, `FitPart`, `Admin/Dcim/Parts`, `Admin/Dcim/Part` |
| 11 | The power path, and the relation that waited for it | `PowerProvider`, `UpsProvider`, `EnvironmentProvider`, `DiscoverPower`, `Relation::Powers`, `modules/infracms/pdu-servertech` |
| 11 | Remote hands | `remote_hands_tasks`, `RemoteHands`, `RemoteHandsState`, `Admin/Dcim/RemoteHands` |
| 18 | The WordPress fleet: the seam and the read | `SiteProvider`, `SiteInstallation`, `SiteComponent`, `DiscoverSites`, `AlertSubject::SiteUpdates`, `AlertSubject::SiteVulnerability`, `modules/infracms/sites-wptoolkit` |
| 18 | Update and maintenance operations | **deferred by the plan — see §4** |

Permissions: `dcim.view` (Support holds it — "which rack is this customer on"
is a support question during an incident), `dcim.manage`,
`dcim.parts.manage`, `dcim.remote_hands.request` (Support's, because the
person on the telephone is exactly who needs a disk swapped) and
`dcim.remote_hands.complete`.

No password challenge anywhere in this phase, which the plan predicted and
which held: nothing here changes what a customer is served. The destructive
act is somebody physically pulling a disk, and this platform records that
rather than causing it.

SDK: **1.11**. Additive throughout — `PowerProvider`, `UpsProvider` and
`EnvironmentProvider` at 1.10, `SiteProvider` and its value objects at 1.11,
plus new members on enums a module only reads.

Automation: `power` (every fifteen minutes) and `sites` (daily), taking the
task count from 25 to 27.

## 2. The decisions this phase actually made

**A device is a `servers` row or a label, and never a third table.** A patch
panel is not a server and never will be one; a `rack_positions` row points at
a server or carries a name, and nothing forces an operator to invent a server
record for a cable manager.

**The overlap rule is the one the database cannot express.** A unique key on
`(rack_id, start_unit)` stops two things starting on one unit and says
nothing about a 2U device landing on the 1U above it. `PlaceDevice` takes a
lock on the **rack** row, because the unit being taken has no row of its own
to lock.

**A part outlives the machine.** `part_fittings` is append-only, so "where has
this serial been" is a `where` rather than a feature — and warranty has three
answers, not two: in, out, and nobody recorded one. Drawing the third as
expired would send somebody to argue with a vendor who is still obliged.

**The power edge is from the outlet, not from the PDU.** Two devices on one
PDU are usually on different breakers, and A-versus-B redundancy is the only
question anybody asks of a power diagram. An outlet whose label names nothing
this platform has heard of keeps the name and gets no edge: inventing a node
would put hardware into the graph on the word of a sticker.

**Remote hands is a record before it is a request.** An audit row saying a
machine was opened is worth more than a ticket saying somebody was asked to
open it. The technician is a **name** rather than a staff user — the person
who walks to the rack works for the datacenter and has no account here — and
the evidence is a **reference** rather than a file. There is no `Failed`: a
technician who went and could not do it has still been, and the outcome says
what happened.

**Out of date and vulnerable are different facts, and they never merge.**
Three releases behind with nothing said against it is housekeeping somebody
does on a Thursday; a published advisory is tonight. Two alert subjects, not
one — a single "needs attention" figure would let four hundred of the first
kind hide the one of the second, and an operator who learned to ignore that
list would be right to.

**A site nothing looked at is not a site that is clean.** `vulnerability_data`
on the node says whether anything checked at all, and `SiteVulnerability`
produces no observation where nothing did. Reading an absent advisory list as
zero would hand a whole fleet a clean bill of health it never earned — which
is the most dangerous thing this phase could have shipped, because it is the
one somebody would put on a slide.

**IPAM moved to core during Phase C and the correction stands here too:** a
module cannot draw a screen. §18's read is a module because a panel knows
where the installations are; the fleet's alerting is core because
`AlertSubject` is a closed list the evaluator has to know how to read.

## 3. Two adapters, both unproven

`pdu-servertech` and `sites-wptoolkit` join the twenty-three families that
have never talked to their real systems. Every request shape and every parse
in both is tested against faked HTTP, which proves the code and not the
integration. `docs/operations/release-checklist.md` is unchanged and still
right.

## 4. Deliberately not in this phase

- **A drawn rack elevation.** §11 says "and later"; the U list is what an
  operator reads when deciding where a machine goes.
- **Switching a PDU outlet.** `Capability::PduOutletWrite` exists and no
  interface declares a method for it. Cutting power has none of the warning a
  hypervisor's shutdown gives, and afterwards this platform could not tell a
  machine that did not come back from a machine that was never on.
- **Updating a WordPress site.** §18 asks for update and maintenance
  operations; they need a guarded workflow for changing somebody else's site,
  and the one this product has is for network devices. There is no
  `site.update.write` capability either — unlike the firewall's write, which
  is one somebody could call today over the same connection, an update
  capability with nothing in core that would ever call it is a switch that
  lies.
- **Capacity planning for power.** `CapacityForecast` would answer it from the
  outlet metrics now that they exist; pointing it at a new dimension is its
  own small piece of work.

## 5. What driving it in a browser found

Three things on the remote-hands screen that 2270 tests could not see, and one
older bug the same pass turned up:

- **The row actions were labelled with the state they land in.** `Agreed`,
  `Somebody is there`, `Called off` — three buttons stating facts rather than
  offering actions. `RemoteHandsState` has an `actionKey()` beside its
  `labelKey()` now.
- **`items-end` on a row of fields aligns the wrappers, not the controls.**
  One field with a hint and two without put three inputs at three different
  heights.
- **A form ran the full width of the window**, so the sentence somebody types
  for a technician was one line 1390 pixels wide.
- **The alert rule form held a second copy of which subjects are numeric.** A
  hand-written `subject === 'metric' || subject === 'capacity'` that stopped
  being true three phases later: certificate expiry, blocklist age and backup
  age were all drawn with no threshold field, and `EvaluateAlertRule` says in
  as many words that a numeric rule with no threshold matches nothing. Every
  rule an operator wrote on those three subjects was a rule that could never
  fire. The subjects carry their own shape now, and `AlertingTest` pins it.

`RackRefused` was also putting hard-coded English on an operator's form,
which it had done since the DCIM spine landed. Both DCIM refusal types carry
a translation key now.

## 6. The standing limitation

Unchanged, with the addition Phase C's plan stated: **most of this phase has
no adapter at all.** A rack diagram that does not match the building is worse
than none, because somebody will send a technician to the wrong cabinet — so
every rule in the DCIM spine is about not letting an operator type something
that makes the diagram wrong, and none of it can be verified against
anything but the building itself.
