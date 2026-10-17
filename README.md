# Fude

Change declared website content by chat message — the [Kirby CMS](https://getkirby.com) 5 side of the Fude module.

*Fude* (筆) is Japanese for the writing brush: whoever writes, changes the page. A client sends "New colleague: Anna Neu, medical assistant" plus a photo to a bot; the bot proposes a change, this plugin stores it as an unpublished Kirby content version, hands back a preview link, and publishes only after an explicit confirmation. The chat side (Telegram, e-mail, the model call) is a separate set of scripts; this plugin is the only thing that touches the site, and it can only touch what the site declares.

- **Declarative.** `site/config/fude.php` lists the collections a bot may change (rows of a structure field, or child pages of a parent) and, per collection, the allowed fields with their types. Everything else is unreachable, whatever a message says.
- **Validated twice.** Against the declaration (unknown field, wrong type, select outside its options) and against the page blueprint (required, maxlength, nested rules) — the same checks the Panel applies. Declared fields that the blueprint does not know make the configuration invalid, loudly.
- **Never live directly.** A draft is a Kirby 5 `changes` version (or a draft page). Publishing needs the draft's confirmation token and an unchanged published state: if somebody edited the page in the Panel meanwhile, the publish is refused (409) and the bot starts over.
- **Photos the way the blueprint wants them.** Resized to the declared width, stored with the declared file template and an alt text; no full-size original on the server.
- **Traceable.** One log line per call: who (account and channel actor), when, what, outcome. The content itself is in Git or in the nightly backup.
- **Own role, own routes.** A Kirby account with the bundled role `fude` has no Panel access and no permissions; it can only call `/fude/*` over HTTPS.
- **English and German** replies, following the site's language.

## Requirements

- PHP 8.2–8.4 with `gd` (or ImageMagick, whatever Kirby's thumb driver uses) and `mbstring`
- Kirby 5
- A writable Kirby `storage` root, ideally outside the web root. Drafts and the log live in `<storage>/fude/`.

## Installation

```sh
composer require sayamaapps/kirby-fude
```

Composer installs the plugin into `site/plugins/fude/` (via `getkirby/composer-installer`, which ships with `getkirby/cms`). Add `/site/plugins/fude/` to `.gitignore` and make sure your deploy ships it, just as it ships `kirby/` and `vendor/`.

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/simsimmal/kirby-fude" }]
```

## Configuration

Everything is off by default. Switch it on in `site/config/config.php`:

```php
return [
    'fude.active' => true,
];
```

Then declare what a bot may change in `site/config/fude.php`:

```php
<?php

return [
    'collections' => [
        // Rows of a structure field on one page — for example the team list
        // of a location page.
        'team' => [
            'kind'        => 'structure',
            'page'        => 'standort',          // page id
            'field'       => 'team',        // structure field in that page's blueprint
            'key'         => 'name',        // the text field that identifies a row
            'label'       => 'Team',
            'description' => 'Who works at Praxis Beispiel.',
            'actions'     => ['create', 'update'],
            'fields'      => [
                'name'  => ['type' => 'text', 'required' => true, 'description' => 'Full name as it should appear'],
                'rolle' => ['type' => 'text', 'description' => 'Role, e.g. Medizinische Fachangestellte'],
                'foto'  => ['type' => 'image', 'maxWidth' => 1200, 'formats' => ['jpg', 'png'], 'alt' => 'name'],
            ],
        ],
        // Child pages of one parent — for example news items.
        'news' => [
            'kind'     => 'pages',
            'parent'   => 'aktuelles',
            'template' => 'note',
            'status'   => 'listed',          // status after publishing a created page
            'key'      => 'title',
            'fields'   => [
                'title'     => ['type' => 'text', 'required' => true],
                'text'      => ['type' => 'textarea'],
                'kategorie' => ['type' => 'select'],   // options from the blueprint
                'bild'      => ['type' => 'image'],
            ],
        ],
    ],
];
```

Field types: `text` (one line; `format` `url`, `email` or `tel` when the blueprint field is one of those; see below), `textarea`, `select` (options from the declaration or the blueprint), `date` (`YYYY-MM-DD`, a real calendar day; `min: 'today'` refuses earlier days, and the only accepted value of `min`; today is the day in `fude.timezone`), `number` (decimal comma or point, stored with a point; optional `min` and `max` as numbers, merged with the blueprint's — the tighter bound wins), `time` (`H:MM` or `HH:MM`, 24 h, optional `:SS`; stored as Kirby's time field saves it, `HH:MM:SS`), `toggle` (JSON `true`/`false` or the strings `true`/`false`; stored as `true` or `false`, as Kirby's toggle field saves it), `image` (an upload; `maxWidth` default 1600, `formats` default jpg/png/webp, `template` default from the blueprint's `uploads`, `alt` names the text field whose value becomes the alt text, default the key). `required` and `maxlength` are merged with the blueprint; the stricter one wins. The key field is always required, whether or not `required` is set for it. Each collection may have one image field.

Text values are plain text or Markdown. A value with an HTML tag, an HTML comment, a `javascript:`, `vbscript:` or `data:` scheme or an HTML entity (`&#`) is refused, because sites render textareas with KirbyText, which passes raw HTML through. A `<` in prose ("Kinder < 6 Jahre") and KirbyTags with https links are fine. A `url` field takes `https://` or `http://` addresses, an `email` field an address, a `tel` field digits with `+ - / ( )` and spaces, a `+` only at the start, at least three digits.

The key of a collection (`key`) is a `text`, `select` or `date` field. The default is the first text field.

Then create a Panel account with the role **Fude (bot)** and a long random password. The role only exists while `fude.active` is true.

### Marking fields in blueprints

Instead of listing everything in `fude.php`, a site can mark the fields in its page blueprints. Fude derives the collections from the markers; `fude.php` stays for overrides.

**What to mark.** The ordinary content: texts, contact details, opening hours, lists such as services, team or news. When in doubt, mark less; a marker is quickly added later. Leave out legal texts (imprint, privacy policy), safety-relevant data (emergency numbers), the address and map link, design settings, and any component too complex to describe in a chat message.

```yaml
fields:
  kurzname:                     # a page field: all marked page fields of one page
    type: text                  #   form one "kind: page" collection
    fude: true                  #   description = the field's help text
  team:
    type: structure
    fude: {key: name, actions: [create, update]}   # collection "standort-team" (page standort)
    fields:
      name: {type: text, required: true}
      foto: {type: files, fude: {maxWidth: 800, alt: name}}
      intern: {type: text, fude: false}            # not reachable
```

- **Page fields** (`fude: true` or `fude: {description: …}`). All marked page fields of one page form one collection of `kind: page`, named by the page id with `/` replaced by `-`, labelled with the page title, described as `Page "<title>"`. Only `description` is a marker option here.
- **Structure fields** (`fude: true` or `fude: {…}`). One collection per structure, named `<page-id>-<field>` in lower case, unless the marker says `name`. Options: `name`, `label`, `description`, `key`, `actions` (default `create`, `update`, `delete`), `insert`, `maxEntries`. The label and the description default to the field's label and help text.
- **Sub-fields** of a marked structure: every sub-field of a type Fude knows is included; `fude: false` leaves one out, and an unmarked sub-field of another type is skipped. A sub-field may carry `fude: {description, maxWidth, alt, formats, template}`; the last four are for `files` only.
- **Types**: `text`, `slug`, `tel`, `email`, `url` → `text` (the last three keep their format); `textarea`, `writer` → `textarea`; `select`, `radio`, `toggles` → `select`; `files` → `image` (inside a structure only); `date`, `number`, `time`, `toggle` → the same type. Any other type is skipped in a structure and refused when marked.
- **Descriptions**: the marker's `description`, otherwise the field's `help` text with the HTML tags removed.
- Fields that a blueprint pulls in with `extends:` count like the others.
- Names must be unique. Two structures that would both become `x` are refused: give one of them `fude: {name: …}`.
- Refused with a 500 that names the page and the field: a marker on a **site field** (the site has no page the bot could change through it), a marked unsupported type, `files` on a page field, an unknown option.
- Never reachable through markers: site fields, users, and drafts (pages in `content/_drafts`). Listed and unlisted pages are scanned.

Derived and declared collections are merged by name in `fude.php`: `'name' => false` removes a derived collection, `'name' => [...]` replaces it completely, a new name adds one. With `fude.blueprints` set to `false`, `fude.php` alone decides.

**Large sites.** The configuration is read on every `/fude/` request, and that reads every page's blueprint. For a few hundred pages this is cheap; for a site with thousands of pages, declare the collections in `fude.php` and set `fude.blueprints` to `false`.

### Kinds, actions and collection options

| Kind | Declares | Actions (default `create`, `update`) |
|---|---|---|
| `structure` | rows of a structure field on one page | `create`, `update`, `delete`, `replace` |
| `pages` | child pages of one parent | `create`, `update`, `delete` |
| `page` | the page itself: no key, no entries (`page` is the page id) | `update` only |

`delete` and `replace` are allowed only when listed. `replace` is for `structure` only.

Options per collection (all optional):

- `maxEntries` (`structure`, 1–100): the list holds at most this many rows. Checked for `create` and `replace`. A `replace` is capped at 100 rows even without `maxEntries`.
- `insert` (`structure`): `'top'` puts a created row first, `'bottom'` (the default) appends it.
- `touch` (`structure` and `page`): `['field' => 'stand', 'value' => 'today']` — every draft of the collection also sets that page field to today's date (`Y-m-d`, in `fude.timezone`). Only `today` is accepted; the field must be a date or text field of the page blueprint.
- `publishRoles` (see below).

### Example: a daily menu

The kitchen sends the whole list each morning. `replace` swaps it in one draft, `maxEntries` keeps a typo from adding a sixth dish, and `touch` stamps the page with the day the list was made:

```php
'menue' => [
    'kind'       => 'structure',
    'page'       => 'speisekarte',
    'field'      => 'gerichte',
    'key'        => 'name',
    'label'      => 'Tagesmenü',
    'actions'    => ['create', 'update', 'delete', 'replace'],
    'maxEntries' => 5,
    'insert'     => 'bottom',
    'touch'      => ['field' => 'stand', 'value' => 'today'],  // a date field of the page blueprint
    'fields'     => [
        'name'    => ['type' => 'text', 'required' => true],
        'preis'   => ['type' => 'number', 'min' => 0],          // a number field in the blueprint
        'hinweis' => ['type' => 'textarea'],
    ],
],
```

```sh
curl -u bot:… -H 'Content-Type: application/json' \
     -d '{"collection":"menue","action":"replace","entries":[{"name":"Kürbissuppe","preis":"6,50"},{"name":"Gulasch","preis":"9,90"}]}' \
     https://example.com/fude/draft
```

A multipart form sends the same list as a JSON string in `entries`.

### Options

All keys are read as `fude.<name>`, flat or nested. `null` counts as "not set".

| Option | Default | Meaning |
|---|---|---|
| `active` | `false` | Master switch. Without it the routes answer with the site's real 404 and the role does not exist. |
| `config` | `site/config/fude.php` | The declaration file, relative to the project root or absolute. |
| `roles` | `['fude']` | Roles whose accounts may call the routes. An admin account deliberately does not get through: its password belongs in no bot. |
| `withoutHttps` | `false` | Accept plain HTTP. For local tests only — Basic Auth sends the password with every request. |
| `trustedProxies` | `[]` | IPv4 or IPv6 addresses and CIDR ranges of the reverse proxies that terminate TLS in front of PHP. Only a request from one of them may pass on `X-Forwarded-Proto: https` (see below). |
| `draftLifetime` | `172800` | Seconds a draft can still be published (48 h). Older drafts are refused with 410; they can still be discarded. Fude never discards an expired draft on its own (an editor may have changed it in the Panel since), so it keeps the page locked for new drafts (423) until the client discards it with its token or an editor discards it in the Panel. A client should therefore discard expired drafts regularly. |
| `maxUploadBytes` | `15728640` | Maximum size of an uploaded image (15 MB). |
| `maxPixels` | `40000000` | Maximum width × height of an uploaded image, read from its header before it is decoded. |
| `language` | site language | `en` or `de` for the replies. Otherwise the site's default language, then the first two letters of `locale`, then English. |
| `blueprints` | `true` | Derive collections from the `fude: …` markers of the page blueprints (see below). `false` switches that off: only `fude.php` counts. |
| `maxCollections` | `30` | Most collections the configuration may have, derived and declared together. More is a 500 on every route: the bot reads them all into its prompt. |
| `siteId` | hostname | Identifier in the schema, for readers that tell sites apart. |
| `timezone` | `Europe/Berlin` | The time zone that decides what "today" is for `touch` and `min: 'today'`. Not PHP's default, which on a hoster is often UTC. An unknown zone fails every route with 500. |

Per collection, `publishRoles` (default `['fude']`) lists the roles that may publish that collection — a second gate independent of `roles`.

### HTTPS check

The routes answer 403 unless the request arrived over TLS. The check reads the
connection, not the `url` option: Kirby takes the scheme from a fixed `url`, so
a plain request to an https site would pass.

- **Direct:** the server's `HTTPS` flag is on, or `SERVER_PORT` is 443.
- **Behind a TLS-terminating reverse proxy** (nginx in front of Apache or PHP-FPM, usual on shared hosting): list its address in `fude.trustedProxies`, e.g. `['127.0.0.1', '10.0.0.0/8', '2001:db8::/32']`. The proxy must send `X-Forwarded-Proto: https`. The header counts only from a listed address; from any other one it is ignored, as Kirby ignores it on a fixed `url`.
- **Local tests:** `fude.withoutHttps` (see above).

A refusal says why in its `error`: "reached the site over plain HTTP", or, when the header says https but the sender is not in `fude.trustedProxies`, a message naming that option.

To check a site, call a route without credentials over its public address:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/fude/schema
```

401 means the connection passed the HTTPS check (the login is what fails now). 403 with "HTTPS only" means it did not: the `error` text names the cause. Behind a proxy, check that the proxy sends `X-Forwarded-Proto: https` and that its address is in `fude.trustedProxies`.

## Routes

All routes need Basic Auth with an allowed account, answer JSON with `ok`, `format` (currently 1) and either the data or `error` (a sentence in the site's language), and are never cached or indexed.

| Route | Body | Reply |
|---|---|---|
| `GET /fude/schema` | — | `site`, `language`, `collections`: per collection `kind`, `label`, `description`, `actions`, `key` (null for `kind: page`), `maxEntries`, `touch` (the name of the touched field) and `insert` (each only when declared), `fields` (type, label, required, description, maxlength, options, maxWidth, formats, min, max) and `entries` (for `kind: page`: exactly one) — the published entries reduced to the declared text fields; images appear as `true`/`false`. |
| `POST /fude/draft` | `collection`, `action` (`create`, `update`, `delete` or `replace`), `fields` (object; as a JSON string in a multipart form; none for `delete` and `replace`), `key` (for `update` and `delete`: the key value of the entry; none for `kind: page` and `replace`), `entries` (for `replace`: a JSON list of entries; as a JSON string in a multipart form), `actor` (optional tag for the log, e.g. `telegram:123`), upload `image` (optional) | `draft` (the record: id, collection, action, page, key, index, fields, file, hash, createdAt, user, actor; for `replace` also `replace` = `kept`, `added`, `removed`, `withoutImage`, each a list of key values; for `update` and `delete` of a child page also `uuid`), `token`, `preview` (URL with Kirby's preview token). |
| `POST /fude/publish` | `id`, `token`, `actor` | `id`, `url` (the page), `hash` (the new published state). |
| `POST /fude/discard` | `id`, `token`, `actor` | `id`, `kept` (`true` if the discard left something in place because somebody worked on it in the Panel: changes edited there, a created page that was edited, locked, extended or published there, or an image the published page already uses). |

Status codes: 400 malformed request, 401 login, 403 role, token or HTTPS, 404 unknown collection, entry or draft, 409 conflict (entry exists, published state changed, draft edited in the Panel, ambiguous key), 410 draft expired or gone, 422 validation, 423 the page is locked or has unsaved Panel changes, 500 configuration or internal error (nothing changed).

A draft on a page with unsaved Panel changes is refused: they would be published together with the draft, and nobody confirmed that. Discard them in the Panel or save them first.

```sh
curl -u fude@example.com:… https://example.com/fude/schema
curl -u fude@example.com:… -F collection=team -F action=create \
     -F 'fields={"name":"Anna Neu","rolle":"MFA"}' -F image=@anna.jpg https://example.com/fude/draft
curl -u fude@example.com:… -H 'Content-Type: application/json' \
     -d '{"id":"…","token":"…"}' https://example.com/fude/publish
```

## What happens on the page

- **Structure collection.** The draft is written to the page's `changes` version — complete, with all other fields carried over, exactly like the Panel does it. The published version is untouched until `/fude/publish` calls Kirby's `Version::publish()`, which validates against the blueprint once more. A Panel editor sees the draft as "unsaved changes" on the page and can publish or discard it there; Fude notices that and refuses its own publish (409) or keeps the changes on discard.
- **Structure, delete.** The row leaves the `changes` version; the published row stays until publish. Its image stays on the page, as with an update.
- **Structure, replace.** The whole list goes into the `changes` version in one draft, every row checked like a create. The new list decides every declared text field: one left out is empty afterwards, so the draft is exactly what the bot showed. A row whose key matches a published row (case and spacing ignored) keeps that row's undeclared columns and its image reference. A new key starts empty. A published key missing from the new list leaves it; its files stay on the page for the Panel to clean up. The reply's `draft.replace` names the kept, added and removed keys, and the rows that will have no image (`withoutImage`), so the bot can warn before publish.
- **Pages collection, delete.** Nothing changes before publish. Publish deletes the child page (refused with 409 while it has child pages or a Panel changes version); the reply URL is the parent page. The draft names the page by its UUID as well as its path: a page created again in the old one's place is not deleted (410).
- **Page (`kind: page`).** Like a structure draft: the declared fields and the touch date go into the page's `changes` version, which carries the whole page. The schema lists the published values as the one entry.
- **Pages collection, create.** A draft page under the parent (invisible until published); publishing sets the declared status. Discard deletes the draft page, but only while it is still as the draft left it: the content matches the fingerprint taken at draft time, nobody holds a Panel lock or has unsaved Panel changes on it, and it has no subpages, subpage drafts or files besides Fude's own image. Otherwise the page stays for the Panel and the reply says `kept: true`.
- **Pages collection, update.** The child's `changes` version, as above.
- **Images** become Kirby files on the page that holds the structure (or on the child page), referenced from the row by UUID. Discard removes the file of the discarded draft, but only when the draft owned it alone: its changes version goes with the discard and the published content does not use the file (UUID or file name). A file the published page uses — the Panel published the draft — stays, and so does the file when the changes version is already gone. An update does not delete the previous image — that is the editor's decision in the Panel.
- The preview URL uses Kirby's preview token (`_token`, `_version=changes`). A site behind its own password protection still asks for that password first.

## Privacy

See [DATENSCHUTZ.md](DATENSCHUTZ.md) (German, authoritative) and [PRIVACY.md](PRIVACY.md).

## Tests

```sh
composer install          # Kirby as a dev dependency, used as fixture
composer test             # or: sh tests/run.sh
```

Every test file is a standalone PHP script (exit code 0 = pass), so the suite also runs on a hoster's PHP without a test framework. CI runs it on PHP 8.2–8.4.

## License

MIT, see [LICENSE](LICENSE).
