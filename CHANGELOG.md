# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to what a declaration can reach** (new field types, new kinds
  of collections, a route that writes something new) is at least a **minor**
  version and is described under "Reach" so a site operator can re-check
  their `fude.php`.
- The route JSON carries `format`; it increases only when a field is
  removed, renamed or changes meaning. New fields alone don't increase it.

## [1.4.0] — 2026-10-20

### Reach

- **A draft can choose the date of an editable `touch`.** With
  `touch: {field: …, value: today, editable: true}` the draft route takes a
  `touch` parameter: a day `YYYY-MM-DD`, not before today in
  `fude.timezone`, written to the touch field instead of today. Without it
  the draft sets today, as before. Meant for a date such as "show until" on
  a daily menu. Collections without `editable` are unchanged and refuse the
  parameter (422), as do collections without `touch`.

### Added

- **`touchDate` in `/fude/schema`** for an editable touch: `label` and
  `description` (from the marker or the declaration, otherwise the field's
  label and help text), `min` and `default` (both `today`) and `current`,
  the published value of the field.
- **`touch` in the draft record** of structure and page collections:
  `{field, value}`, the date the draft sets, or null without `touch`.

### Removed

- **`DATENSCHUTZ.md` and `PRIVACY.md`.** Fude is a tool for the site's
  editors and processes no visitor data, so it has no place in a site's
  privacy policy. What Fude stores on the server is now described in the
  README ("Stored data").

## [1.3.0] — 2026-10-20

### Reach

- **Select fields with query options are reachable.** A blueprint select with
  `options: {type: query, …}` used to make the configuration invalid (500 on
  every route). Its values now come from the query, resolved against the
  collection's page at every request. A query that yields nothing leaves the
  field out of the collection, so it is not in `/fude/schema` and cannot be
  written; a key or a required field without options stays a 500. Operators
  who put `fude: false` on such a field as a workaround for the 500 should
  re-check their blueprints: the field is now reachable, with the values the
  query yields.

### Added

- **`readImages` collection option.** `true` declares that the chat side may
  read the content of an attached photo as input for the fields, for example
  a photo of a blackboard with today's dishes. The plugin does nothing else
  with it; the schema lists `readImages: true` only when it is set. Allowed in
  `site/config/fude.php` for every kind, and as a marker option of a structure
  field (`fude: {readImages: true}`).

### Fixed

- **Typo in the German privacy text.** `DATENSCHUTZ.md` read "standortßerhalb"
  where it means "außerhalb". The meaning did not change, so this is not
  marked **[privacy text]**.

## [1.2.0] — 2026-10-17

### Added

- **`touch` as a marker option of a structure.** `fude: {touch: {field: stand,
  value: today}}` does what `touch` does in `site/config/fude.php`: every draft
  of the collection sets that page field to today. Until now the option was
  refused as unknown, so a module could not stamp its date field without a
  `fude.php` entry on every site.
- Markers in blueprints that a plugin registers (tabs, fields, sections pulled
  in with `extends`) are covered by a test and documented; they already worked.

## [1.1.1] — 2026-10-17

### Fixed

- **Discarding a created page no longer deletes a Panel editor's work.**
  A page Fude created as a draft was deleted recursively as soon as it was
  still a draft. It is now deleted only while it is as the draft left it:
  same content as at draft time, no Panel lock or unsaved Panel changes, no
  subpages or subpage drafts, no files besides Fude's own image. Otherwise
  the page stays, the draft record goes, and the reply says `kept: true`.
  **[privacy text]** A discarded draft is deleted unless it was edited or
  published in the Panel meanwhile (`PRIVACY.md`, `DATENSCHUTZ.md`).
- **Discarding a draft no longer removes an image the published page uses.**
  The image of a discarded structure or page-update draft goes only when the
  draft's own changes version goes with it and the published content does not
  reference the file (UUID or file name); before, only the changes version
  was looked at. A discard that leaves such an image replies `kept: true`,
  and so does a discard after the Panel published the draft (the reply said
  `false` there, though nothing was discarded).

## [1.1.0] — 2026-10-17

Kirby 5 side of Fude: a site declares which content a chat bot may change,
and the bot's changes arrive as unpublished Kirby content versions with a
preview link. Nothing is published without an explicit second call.

### Scope

- **Collections** declared in `site/config/fude.php` or marked in the page
  blueprints (`fude: true` on a page field, `fude: {…}` on a structure field):
  rows of a structure field on one page (`kind: structure`), child pages of
  one parent (`kind: pages`), and the fields of one page (`kind: page`).
- **Actions** `create`, `update`, `delete` and `replace`, with the allowed
  roles and the publish rules declared per collection.
- **Field types** `text` (with `format` `url`, `email` or `tel`), `textarea`,
  `select`, `date`, `number`, `time`, `toggle` and `image`. Text values may
  not contain HTML.
- **Images** are identified by content, resized to the declared width and
  stored with the declared file template and an alt text. EXIF metadata is
  removed, and no full-size original is kept.
- **Routes** `GET /fude/schema`, `POST /fude/draft`, `POST /fude/publish` and
  `POST /fude/discard`. Basic Auth against a Kirby account with the role
  `fude`, HTTPS only (directly, or behind a TLS-terminating reverse proxy
  listed in `fude.trustedProxies`), off unless `fude.active` is set.
- **Drafts** are Kirby 5 `changes` versions (or draft pages for new child
  pages). Publishing needs the draft id and token, an unchanged published
  state and an unchanged draft, and a draft younger than `fude.draftLifetime`.
- **Log** `storage/fude/log.jsonl`: who, when, route, collection, action, key,
  draft id and outcome. No field values and no images.
- English and German replies, following the site's language.

### Configuration

Options under `fude.*` (`active`, `config`, `roles`, `timezone`,
`draftLifetime`, `maxUploadBytes`, `maxPixels`, `trustedProxies`, `withoutHttps`,
`language`, `blueprints`, `maxCollections`). See the README.
