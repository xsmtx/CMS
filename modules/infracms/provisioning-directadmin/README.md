# DirectAdmin

Creates, suspends, upgrades and terminates DirectAdmin accounts on a server in
the fleet.

## What it needs

**No credentials.** A provisioning module is given a server, and the server row
already holds the hostname, port, login and secret. Asking again here would be
two places to change a password and one of them would be missed.

The two settings that are the operator's rather than the server's:

| Setting | Default | Why |
| --- | --- | --- |
| Assign a dedicated IP | off | Off means the account shares the server's IP, which is what most shared hosting does. |
| Let DirectAdmin email the customer | off | This platform sends its own welcome message, and two is one too many. |

## Two things about DirectAdmin

**It answers with a querystring, not JSON**, and it says no with `error=1` in a
**200** response. A module that read the HTTP status would call every refusal a
success.

**A username is at most ten characters, lower case, starting with a letter.**
DirectAdmin enforces it, so the name is derived here rather than discovered when
a customer's order fails.

## Unproven

**This module has never talked to a DirectAdmin server.** Written against the
published documentation and tested against faked HTTP.
