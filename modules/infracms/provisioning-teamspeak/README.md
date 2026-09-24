# TeamSpeak

Creates, stops, resizes and deletes virtual TeamSpeak servers on an instance in
the fleet.

## WebQuery, not ServerQuery

The old interface is a raw telnet protocol with its own escaping rules, and
speaking it from PHP means holding a socket open across a queued job. WebQuery is
the same commands over HTTP with an `x-api-key` header — the only version of this
worth shipping.

## The port is the product

Customers connect to `host:port`, so the port TeamSpeak assigns is recorded in
the metadata. Without it the customer has a server they cannot find.

## Stopping is not deleting

`serverstop` leaves the virtual server, its channels and its permissions in
place; `serverdelete` does not, and it cannot be undone. Suspension stops;
termination stops **and then** deletes, in that order, because TeamSpeak refuses
to delete a running server.

## Slots

A TeamSpeak product is usually sold by slot count, so the package is read as a
number. The default is used when it is not one.

## Unproven

**This module has never talked to a TeamSpeak instance.**
