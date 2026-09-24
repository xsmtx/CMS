# Discord notifications

Posts this installation's notifications into a Discord channel.

## A chat room is the operator's endpoint

Not a customer's address. There is nothing here about *who* to tell: everything
sent on the chat channel lands in the same room. That is also why this module and
the other chat modules coexist rather than compete — the registry holds a
provider per channel **and** implementation, so Slack and Discord both deliver
and each writes its own row.

## It never throws

A chat room that is down must not take down the operation that triggered the
message. An invoice is still issued when Discord is having an afternoon; the failure
becomes a delivery row.

## The URL is checked immediately before the request

Not when it was saved. DNS can change in between and that is the whole technique,
and an operator who pasted an internal address gets a refusal rather than a
request from this server to somewhere on its own network.

## Nothing is ever mentioned

`allowed_mentions` is empty, so a notification cannot ping `@everyone` — not
because this module would write one, but because a customer's company name or an
invoice note could contain the text that does. A billing notice waking a room at
three in the morning is a bug nobody forgets.

## Unproven

**This module has never posted to a real Discord workspace.**
