# cPanel / WHM

Accounts on a WHM server, over WHM API 1.

## Configuration

The credentials are not here. A WHM API token belongs to a server, so it lives
on the server in the fleet (Setup → Servers) and never in this module's
settings. What this module configures is patience: how long to wait for a slow
panel, and how many bounded retries to make.

## Two behaviours worth knowing

- **"Account already exists" is a success**, because that is what makes
  retrying a job safe (ADR 0026).
- **The username is derived from the domain**, deterministically, so a retry
  asks for the same account rather than making a second one.

## Until it has talked to a real WHM

Its request shapes, error handling and idempotency are tested against faked
HTTP, which proves the code and not the integration.
