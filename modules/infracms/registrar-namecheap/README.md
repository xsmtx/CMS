# Namecheap

Domain registration, transfer and renewal through the Namecheap API.

## Configuration

| Field | What it is |
| --- | --- |
| API user | The API user Namecheap issued, which is not always the account login. |
| API key | From Profile → Tools → API Access. |
| Whitelisted IP | The address on Namecheap's allow-list. Empty sends the server's own. |
| Sandbox | `api.sandbox.namecheap.com`, where nothing is really registered. |

**Both the user and the key, or nothing.** A registrar with no key can take an
order and never register the name, which is worse than one that is absent.

## Until it has talked to Namecheap

Every request shape here is tested against faked HTTP. A transfer code is
fetched, shown once and never written down (ADR 0028); a registry that did not
answer has *not* said a name is free.
