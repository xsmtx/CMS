# SolusVM

Creates, suspends, upgrades and terminates virtual servers through a SolusVM
master.

## One endpoint, one `action`

There are no paths and no verbs: everything is `/api/admin/command.php` with a
form field. That is why the adapter is mostly one private method.

It answers with a **querystring** and says no in a **200** (`status=error`), so
the HTTP status decides nothing.

## The credentials arrive once

`vserver-create` returns the vserver id, the root password and the assigned IP in
the same response, and there is no way to ask for the password again. If the call
succeeds and the caller then fails to record it, the customer has a machine
nobody can log into — which is why the external id is written the moment the
provider returns it (ADR 0026).

## What it needs

| Setting | Why there is no default |
| --- | --- |
| Virtualisation | Guessing KVM on an OpenVZ master fails every order. |
| Template | Guessing hands the customer whichever operating system was first. |
| Node group | Optional: empty lets SolusVM choose the node. |

The master's API id and key come from the server row.

## Unproven

**This module has never talked to a SolusVM master.**
