# Status Board

The worked example for the InfraCMS module SDK. It watches one URL and
reports whether it answered.

Small on purpose. It touches the four things every module author needs on
their first day and nothing they will not:

| It provides | Through | Which shows |
| --- | --- | --- |
| Settings | `configSchema()` | A module declares fields; core draws, validates and stores the form. The module never renders anything. |
| A health check | `healthChecks()` | How a module says whether it is actually working. Never throws, never returns a configuration value. |
| A dashboard tile | `widgets()` | A widget is data, not a component. Core renders and escapes it. |
| A permission | `permissions()` | It becomes a real permission on the roles screen, and is orphaned rather than deleted when the module goes. |

## The more useful half

What it does **not** contain is the point:

- no service provider, no container, no facade
- no Eloquent model and no migration
- no JavaScript, no build step
- nothing that can register middleware, replace a binding or reorder the
  pipeline — the SDK never offers the chance

Every class it imports lives in `app/Domain`, which is the SDK. If a
future version of core changes how a health report is stored, this module
does not notice.

## Trying it

```bash
php artisan module:list          # appears as on-disk
```

Then, in **Apps & Integrations → Modules**: install it (which writes a row
and runs nothing), fill in a URL, and enable it. Enabling is the moment its
code runs.

To see the refusal path, set `"type": "report"` in `module.json` and enable
it again: a report may not register a health check, and the module is
refused with a sentence rather than half-registered.
