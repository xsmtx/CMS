# DigitalOcean Droplets

Creates, powers and destroys Droplets from an API token.

## There is no server in the fleet

`needsServer` is false. A control panel is a machine an operator added and holds
credentials for; a public cloud is an **account**. The token lives in this
module's own configuration — masked by the presenter, never leaving
`ModuleContext` — and `testConnection` is not offered, because there is nothing
to connect to except the API.

## A Droplet is ordered, not created

The call returns immediately with an id and a status of `new`; the machine exists
minutes later. `sync` is what reports when it is actually running and what
address it got. Telling a customer their server is ready on the strength of the
201 is how they are handed an IP that does not answer.

## Suspension stops the service, not the bill

A powered-off Droplet still costs money. That is the honest thing to tell an
operator: only terminating stops the charge, and only terminating destroys the
disk.

## The size is the package, untranslated

What you sold is the slug DigitalOcean knows — `s-1vcpu-1gb`. A mapping table
inside a module is a mapping table somebody has to keep in step with a price
list.

## Unproven

**This module has never talked to DigitalOcean.**
