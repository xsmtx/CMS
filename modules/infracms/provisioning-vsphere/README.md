# VMware vSphere / ESXi

Clones a template into a VM through vCenter, and powers and deletes it.

## It talks to vCenter, not to a bare ESXi host

A scoping decision rather than an oversight. A standalone ESXi host exposes a
small subset of the Automation API and **no clone operation at all**; cloning,
folders, resource pools and datastore placement are vCenter's. A module that
pretended otherwise would work on a lab box and fail on every real deployment.

The server row is the vCenter appliance. What is configured here is where clones
come from and where they go.

## Every call needs a session

`POST /api/session` with basic auth returns a token for `vmware-api-session-id`.
There is no long-lived key, so a session is created **per operation and not
cached**: a token cached across a queued job has expired by the time the job
runs, and the failure looks exactly like bad credentials.

## Nothing is addressed by name

vSphere identifies a VM, a folder, a datastore and a resource pool by opaque ids
— `vm-1042`, `datastore-17`. Every name an operator typed is looked up first,
which is why creating makes several calls before the one that matters.

## Powering off is not optional before deleting

vCenter answers 400 rather than doing it for you. Terminate powers off first, and
treats "already powered off" as success rather than a failure to retry forever.

## Unproven

**This module has never talked to a vCenter.**
