# Mattermost notifications

Posts this installation's notifications into a Mattermost channel.

## A chat room is the operator's endpoint

Not a customer's address. There is nothing here about *who* to tell: everything
sent on the chat channel lands in the same room. That is also why this module and
the other chat modules coexist rather than compete — the registry holds a
provider per channel **and** implementation, so Slack and Discord both deliver
and each writes its own row.

## It never throws

A chat room that is down must not take down the operation that triggered the
message. An invoice is still issued when Mattermost is having an afternoon; the failure
becomes a delivery row.

## The URL is checked immediately before the request

Not when it was saved. DNS can change in between and that is the whole technique,
and an operator who pasted an internal address gets a refusal rather than a
request from this server to somewhere on its own network.

## Self-hosted, and that changes the URL check

Mattermost is usually on somebody's own network, and `SafeUrl` refuses private
addresses — which is correct for a customer-supplied webhook and inconvenient
here. An installation that posts to an internal Mattermost adds that address to
its outbound allowances deliberately, which is the point: it should be a decision
somebody made, not a check that quietly did nothing.

## Unproven

**This module has never posted to a real Mattermost workspace.**
