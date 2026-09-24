# File Probe — the worked example for infrastructure adapters

This module is not part of the product. It exists so that the adapter seam is
proven from outside core, the way `status-board` proves the rest of the SDK.
Nothing in `app/` mentions it; `tests/Feature/ExampleFileProbeTest.php` drives it
end to end, and if the capability registry stops working for somebody else's
package, that test fails.

## What it does

Reads measurements about your resources from a JSON file that something else
writes — a cron, a poller, a shared mount — and hands them to the platform.

## Why a file and not Prometheus

A real monitoring adapter calls a real monitoring system, which a test cannot do
and a reader cannot run. A file can be both, and reading one exercises every part
of the seam that matters:

- a **declared capability** (`monitoring.metrics.read`, and nothing else, so the
  Adapters screen can say truthfully that this changes nothing);
- a **declared batch size**, which core chunks by;
- a **partial answer** — targets the file says nothing about come back in
  `unknownTargets`, because nine of ten is not success;
- **raw vendor names and units** going through core's normalizer, rather than the
  module pretending to know what this platform calls things.

It needs a path, not a credential. That is why Phase A could ship an adapter
before the credential vault exists.

## The file

```json
{
  "sampled_at": "2026-09-24T10:00:00Z",
  "resources": {
    "01JABCDEF0123456789ABCDEFG": {
      "cpu_percent": 42.5,
      "memory_used": { "value": 8, "unit": "gigabytes" },
      "load": 1.2,
      "accounts": 118
    }
  }
}
```

The keys under `resources` are **node keys**, as the graph holds them — for a
server projected by core that is the server's id, which the Explorer shows under
the name. A bare number is read in the metric's own canonical unit; an object may
name any unit core knows (`percent`, `gigabytes`, `megabits_per_second`, …) and
core does the arithmetic.

A metric name this platform has no `MetricKind` for is **counted and dropped**,
not stored. That is deliberate: a table that accepted any metric name would be a
time-series database nobody sized, and the Telemetry screen is where the refusals
become visible.

## Installing it

```bash
php artisan module:list
# install, then configure the path, then enable
```

Enabling runs the module's code (ADR 0038). This one has no migrations and
registers one adapter.
