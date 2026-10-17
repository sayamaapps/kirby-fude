# Fude: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German
text block for privacy policies, which is the authoritative version. Both
describe the implementation. They are not legal advice. Each operator has to
determine the legal basis, retention period and data subject rights for their
own site.

## What the plugin does

A person authorised by the site operator sends a message (and optionally a
photo) through a chat channel (Telegram, e-mail). A bot outside the website
turns it into a proposed change and calls this plugin. The plugin stores the
proposal as an unpublished Kirby content version, returns a preview link, and
publishes only after an explicit confirmation from the same person.

## What is stored on the web server

- **The proposed content** — the text fields and the resized photo — as a
  Kirby content version or draft page, until it is published or discarded.
  A discarded draft and its photo are deleted; a published one becomes part
  of the website like any Panel edit.
- **A draft record** (`storage/fude/drafts/`): draft id, collection, action,
  the submitted field values, the uploaded file name, the account that made
  it, the channel actor tag the bot passes along (for example
  `telegram:<chat id>` or `mail:<address>`), and timestamps. Deleted on
  publish or discard.
- **A log** (`storage/fude/log.jsonl`): one line per call with timestamp,
  route, account, channel actor tag, collection, action, key (the entry's
  name or title), draft id and outcome. **No field values and no images** are
  logged. The log starts over at 4 MB.
- Kirby's own logs of failed logins (PHP error log).

## What is not stored

No IP addresses, no user agents, no message text beyond the declared fields,
no original photo in full size (only the resized copy), no secrets of the
chat channel.

## Who can reach what

Only accounts with the role `fude` (or the roles in `fude.roles`), only the
collections and fields declared in `site/config/fude.php`, only over HTTPS.
The bot account cannot log in to the Panel.
