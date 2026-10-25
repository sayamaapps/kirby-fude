# Fude

Change declared website content by chat message, for [Kirby CMS](https://getkirby.com) 5.

A chat bot proposes a change, for example a new team member with a photo. Fude stores it as an unpublished Kirby content version with a preview link, and publishes only after an explicit confirmation, with the draft's token. Anything the site does not declare stays unreachable.

## Features

- Declarative and validated: only declared collections and fields are reachable, checked against the blueprint too.
- Drafts are Kirby `changes` versions; publishing needs a token and an unchanged page.
- Photos are resized to the declared width and get alt text.
- One log line per call: account, channel tag, route, outcome.
- Own bot role without Panel access; HTTPS only; English and German replies.

## Requirements

- PHP 8.2–8.4 with `gd` (or ImageMagick) and `mbstring`; Kirby 5; a writable `storage` root (drafts and the log live in `<storage>/fude/`)

## Installation

```sh
composer require sayamaapps/kirby-fude
```

Composer installs to `site/plugins/fude/`. Add that path to `.gitignore` and deploy it like `vendor/`.

## Usage

Declare what the bot may change in `site/config/fude.php`:

```php
<?php

return [
    'collections' => [
        'team' => [
            'kind'   => 'structure',   // rows of a structure field on one page
            'page'   => 'standort',
            'field'  => 'team',
            'fields' => [
                'name' => ['type' => 'text', 'required' => true],
                'foto' => ['type' => 'image', 'maxWidth' => 1200, 'alt' => 'name'],
            ],
        ],
    ],
];
```

Enable Fude with `'fude.active' => true` in `site/config/config.php`, then create a Panel account with the role **Fude (bot)** and a long random password.

### Collections

| Option | Meaning |
|---|---|
| `kind` | `structure` (default; needs `page`, `field`), `pages` (child pages; needs `parent`, `template`; `status` after publish: `listed` (default) or `unlisted`), or `page` (the page itself; `update` only) |
| `key` | Identifies an entry: a `text`, `select` or `date` field, always required. Default: the first text field. |
| `label`, `description`, `fields` | Shown to the bot; `fields` as below (a `pages` collection needs `title`) |
| `actions` | `create`, `update` (default), `delete`, `replace` (structure only) |
| `maxEntries` | `structure`: at most 1–100 rows, checked on `create` and `replace` |
| `insert` | `structure`: `top` or `bottom` (default) for a created row |
| `touch` | `structure`, `page`: `['field' => 'stand', 'value' => 'today']` sets that page date to today on every draft. With `'editable' => true`, a draft may name another day. |
| `readImages` | `true`: the model may read attached photos. Fude itself does not use it. |
| `publishRoles` | Roles that may publish (default `['fude']`) |

### Field types

| Type | Notes |
|---|---|
| `text` | One line; `url`, `email` or `tel` follow the blueprint |
| `textarea` | Plain text or Markdown; HTML tags, `javascript:`-style URLs, the `gist` KirbyTag and `style:` attributes are refused |
| `select` | Options from the declaration, the blueprint or its `query` |
| `date`, `time` | `YYYY-MM-DD` (`min: 'today'` refuses earlier days); `H:MM` or `HH:MM`, 24 h |
| `number` | Decimal comma or point; optional `min` and `max` |
| `toggle` | `true` or `false` |
| `image` | Upload. `maxWidth` (default 1600), `formats` (default `jpg`, `png`, `webp`; also `gif`), `template`, `alt` (text field, default the key) |
| `page` | One page of a blueprint `pages` field's `query`, chosen by title |

Every field takes `description` (shown to the bot), `required` and `maxlength`; the last two merge with the blueprint, and the stricter one wins. `ask: true` (or `fudeAsk: true` in the blueprint) makes the bot ask for a field once before the first draft. Fields can also be marked in page blueprints (`fude:` keys); Fude then derives the collections, and `fude.php` overrides them by name. A marked `pages` section makes the children with its template one `pages` collection, with the fields marked in their blueprint. Marker and rule reference: [docs/reference.md](docs/reference.md#blueprint-markers).

### Chat flow

```sh
curl -u fude@example.com:… -F collection=team -F action=create \
     -F 'fields={"name":"Max Muster"}' -F image=@photo.jpg https://example.com/fude/draft
curl -u fude@example.com:… -H 'Content-Type: application/json' \
     -d '{"id":"…","token":"…"}' https://example.com/fude/publish
```

## Routes

All routes need Basic Auth. Replies are JSON with `ok`, `format` (currently 1) and the data, or `error`.

| Route | Request | Reply |
|---|---|---|
| `GET /fude/schema` | — | `site`, `language`, `collections` (declarations and published entries) |
| `POST /fude/draft` | `collection`, `action`, `fields`, `key` (update, delete), `entries` (replace), `touch`, `actor` (log tag), upload `image` | `draft`, `token`, `preview` |
| `POST /fude/publish` | `id`, `token`, `actor` | `id`, `url`, `hash` |
| `POST /fude/discard` | `id`, `token`, `actor` | `id`, `kept` (`true` if a Panel editor's work stays) |

Errors: 400 malformed request, 401 login, 403 HTTPS, role, token or publish permission, 404 unknown collection, entry or draft, 409 conflict (entry exists, page or its files changed, draft edited in the Panel, file used on another page), 410 draft expired or gone, 422 validation, 423 page locked or with unsaved Panel changes, 500 configuration error.

## Options

All keys are read as `fude.<name>`, flat or nested. `null` counts as not set.

| Option | Default | Meaning |
|---|---|---|
| `active` | `false` | Master switch. Off: routes answer 404 and the role does not exist. |
| `config` | `site/config/fude.php` | Declaration file, relative to the project root or absolute |
| `roles` | `['fude']` | Roles that may call the routes; admins are not included. |
| `withoutHttps` | `false` | Accept plain HTTP. Local tests only: Basic Auth sends the password with every request. |
| `trustedProxies` | `[]` | IP addresses and CIDR ranges of TLS-terminating proxies (see HTTPS) |
| `draftLifetime` | `172800` | Seconds a draft can still be published (48 h). Older drafts answer 410. |
| `maxUploadBytes` | `15728640` | Largest uploaded image (15 MB) |
| `maxPixels` | `40000000` | Largest uploaded image, width × height, checked before decoding |
| `language` | site language | `en` or `de` for replies |
| `blueprints` | `true` | Derive collections from `fude:` markers. `false`: `fude.php` alone decides. |
| `maxCollections` | `30` | Most collections, derived and declared together; more is a 500. |
| `siteId` | hostname | Identifier in the schema, for readers that tell sites apart |
| `timezone` | `Europe/Berlin` | Time zone for "today" (`touch`, `min: 'today'`); an unknown zone is a 500. |

## HTTPS

Routes answer 403 unless the connection itself is TLS. Behind a TLS-terminating proxy, list it in `fude.trustedProxies` and have it send `X-Forwarded-Proto: https`; the header counts only from a listed address. Check a site with `curl -s -o /dev/null -w '%{http_code}\n' https://example.com/fude/schema`: 401 means the check passed; a 403 names the cause in `error`.

## What happens on the page

- **Structure and page drafts** go into the page's `changes` version; a new child page is a draft page until published. Publish validates against the blueprint again; a Panel editor can publish or discard the draft there.
- **Images** become Kirby files on the page. Discard removes a file only if the draft owned it alone: unchanged since the draft and used nowhere else on the site.
- **Deleting a page** takes its files only as the draft saw them: a file added or edited since, or one another page uses, answers 409.

## Stored data

Fude processes no visitor data and stores no IP addresses, user agents or full-size photo originals. The chat side logs in with Kirby's own password check and its lockout, using an account without Panel access. On the server, Fude keeps:

- Proposed content as a `changes` version or draft page, until published or discarded.
- Draft records in `storage/fude/drafts/` (field values, account, channel tag), deleted on publish or discard.
- A log in `storage/fude/log.jsonl`: who, when, route, collection, action, key, outcome. No field values, no images; it restarts at 4 MB.

Tests: `composer test`. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE).
