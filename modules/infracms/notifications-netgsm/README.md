# Netgsm SMS

Sends this installation's notifications as text messages through
[Netgsm](https://www.netgsm.com.tr).

## A text message costs money and is measured in characters

Which makes it unlike every other channel here, so two things are built in rather
than left to whoever writes the templates:

- **The body is the subject and the link, nothing else.** A notification body is
  written for somebody reading an email; sending it as SMS would be several
  messages, charged as several, saying the same thing.
- **The subject gives way, never the URL.** Half a link is a message that cost
  money and did nothing.

## Numbers are normalised, and implausible ones are refused

Stored numbers have spaces, plus signs and parentheses; Netgsm wants digits. A
number with no international prefix gets the configured one. A number that ends
up too short or too long is refused **here** rather than paid for.

## Netgsm says no in a 200

The body is a numeric code — `00` and `01` are accepted, everything else is an
error. A channel that read the HTTP status would count every rejection as sent.
The common codes are translated into sentences an operator can act on; `40` in
particular means the sender header is not registered, which is the first thing
that goes wrong.

## What it needs

| Setting | Why |
| --- | --- |
| Netgsm number | The subscriber number, used as the API username. |
| API password | |
| Sender header | **Required, no default.** Netgsm rejects an unregistered header outright, and a guess would fail every message in a way that looks like bad credentials. |
| Default country code | Used when a stored number has no prefix. |

## Unproven

**This module has never sent a real message.**
