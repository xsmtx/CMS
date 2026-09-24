# Hetzner Cloud

Creates, powers and destroys Hetzner Cloud servers from a project API token.

## There is no server in the fleet

`needsServer` is false, and the token lives in this module's configuration. A
public cloud is an account, not a machine an operator added.

## A server is ordered, not created

The call returns an id and a status of `initializing`. `sync` reports when it is
actually running and what address it got.

**Hetzner starts a server the moment it is created**, and there is no way to ask
for one built but switched off. A provisioning run cancelled between creating and
recording leaves a running machine somebody pays for — which is exactly why the
external id is written the moment the provider returns it (ADR 0026).

## Suspension stops the service, not the bill

A powered-off server still costs money. Only terminating stops the charge.

## Unproven

**This module has never talked to Hetzner.**
