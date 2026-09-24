# Proxmox VE

Clones a template into a VM, and starts, stops and destroys it.

## A VM is cloned, never built

Installing an operating system is minutes of work with a dozen ways to fail, and
a provisioning call is not the place for it. The template is prepared once by an
operator who can watch it; every customer's VM is a copy. That is why
**Template VMID is required** — a module that fell back to creating an empty VM
would hand a customer a machine that boots to nothing.

## Almost nothing is synchronous

A clone, a stop and a destroy each return a **task id** (`UPID:…`) and the work
happens afterwards. A successful call means *accepted*, not *done*, and `sync` is
what eventually reports the truth. Treating the 200 as completion is how a
customer is told their VM is ready while it is still copying a disk.

## Authentication is a token

`PVEAPIToken=user@realm!tokenid=secret`, held whole in the server row's secret. A
ticket expires in two hours, which means a provisioning worker that sat idle
overnight fails its first job.

## Suspension stops the VM

Proxmox's own `suspend` writes memory to disk and **holds the resources**. For a
customer who has not paid, the resources are the point.

## Resizing is not offered

A VM's size is its hardware; changing it needs a reboot and a disk change that
cannot be undone. `changePackage` is declared unsupported and answers honestly if
asked anyway.

## Unproven

**This module has never talked to a Proxmox server.**
