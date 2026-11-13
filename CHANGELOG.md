# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to what a declaration can reach** (new field types, new kinds
  of collections, a route that writes something new) is at least a **minor**
  version and is described under "Reach" so a site operator can re-check
  their `fude.php`.
- The route JSON carries `format`; it increases only when a field is
  removed, renamed or changes meaning. New fields alone don't increase it.

## [1.13.0] — unreleased

### Added

- **A PHP API for other entry points.** `Sayamaapps\Fude\Actions` offers
  `schema()`, `entries()`, `draft()`, `publish()`, `discard()` and
  `drafts()` without HTTP: input as an array, uploads passed in (never read
  from the request), and a context with the acting Kirby user, an `actor`
  tag, two opaque tags stored with a new draft (`owner`, `ref`), the
  write-lock wait (default 30 s), a publish permission callback (default:
  the collection's `publishRoles`) and extra log fields. Impersonation, the
  write lock and the log line moved from the routes into `Actions`; the
  routes call it, so every entry point checks, locks and logs the same way.
  See [PHP API](docs/reference.md#php-api).
- `publish()` and `discard()` accept a draft without its token when the
  caller's `owner` equals the draft's; another owner gets the answer of an
  unknown draft (404). The routes never set `owner` and keep the token.
- `drafts()` lists the drafts of an owner, optionally of one collection or
  one `ref`, with `expiresAt` and `expired`.
- `entries()` returns the entries of one collection, optionally only one by
  `key`, and `openDraft`: the Fude draft (`id`, `owner`, `preview`) or the
  unsaved Panel changes that would refuse a draft for that page.
- `schema()` without entries (`['entries' => false]`).
- A 423 `draft.pending` names the Fude draft behind the unsaved changes in
  `draft` (`id`, `owner`, `preview`; never the token). Unsaved Panel
  changes without a Fude draft answer as before.
- Drafts left alone for twice `draftLifetime` (96 h by default) are
  discarded by the next draft, by the rules of discard: only what is still
  the draft's own goes, Panel work on top stays. Draft records no longer
  pile up, and an abandoned draft no longer blocks its page for good.

- Reach: unchanged, no new field type and no new route. Route JSON `format`
  unchanged (a 423 may carry the new field `draft`; draft records of the
  PHP API carry `owner` and `ref`).

### Fixed

- A route that the site config or another plugin registers under `/fude/`
  was hidden by Fude's catch-all when Kirby loaded Fude first. It now
  answers; any other path under `/fude/` is still Kirby's real 404.

## [1.12.0] — 2026-11-10

### Added

- **A collection's key can be several fields.** `key` was one field, so
  content whose entries are only unique by a combination could not be
  declared: a closing for the restaurant and another for the kitchen on the
  same date got a 409 (`collection.exists`), and a client that then followed
  "same key, so update" overwrote the first, still valid entry. `key` now
  also takes a list (`'key' => ['from', 'area']`, or `fude: {key: [from,
  area]}` in a blueprint). Entries may share a part but not the whole key:
  the duplicate checks (create, create with `entries`, replace, update at
  draft time and again at publish) compare every part, case-insensitive and
  trimmed. An update or delete addresses the entry with `key` as an object
  of every part (`{"from": "2026-12-24", "area": "kitchen"}`, also as a JSON
  string); the schema lists `key` as the list; replies name the key as its
  parts joined with ` / `. An empty list, an unknown field or a part listed
  twice is a 500 like any other configuration error. A key of one field,
  or a list of one, works as before: same schema, same requests, same
  replies.
- Reach: unchanged, no new field type and no new route. Route JSON `format`
  unchanged (the `key` of the schema and of a draft record is an array or an
  object only for a collection that declares a composite key).

## [1.11.3] — 2026-11-03

### Fixed

- **Confirming a new page no longer publishes it over an editor in the Panel.**
  Publishing a draft that creates a page (a `pages` collection, action
  `create`) compared the content and the key but never asked whether someone
  was working on the new page. An editor who had the new draft page open in the
  Panel (Kirby's lock) or had saved changes there without publishing them did
  not stop the confirmation: the page went live and the draft record was
  removed. Publishing a new page now answers 423 in both cases, as drafting
  and updating a page already did, before anything is published. Nothing
  changes on the page, and the draft stays confirmable once the editor has
  discarded in the Panel (after a save there the content differs from the draft
  and publishing answers 409). The 409 for content changed since the draft
  is unchanged.
- Reach: unchanged. Route JSON `format` unchanged.

## [1.11.2] — 2026-11-03

### Fixed

- **A draft whose page or image was already stored when a Kirby `after` hook
  threw is undone too.** Kirby runs `page.create:after` and `file.create:after`
  once the page or the image is on disk and before it hands it back, so a hook
  that threw (a site's own hook, a plugin's) left a draft page in `_drafts/`, or
  an image with its content file on the page, while the answer said "Internal
  error, nothing changed." and no draft record existed. The draft now keeps the
  name of the page or image before it calls Kirby and removes what it finds
  stored under that name, as 1.11.1 does for the rest. A draft page that was
  there before is never touched. If the undo fails, the answer is the 500 that
  says so (check the page in the Panel), and both errors go to the PHP error log.
  A `page.update:after` that threw while the image was attached to a new draft
  page removed the page but answered that the undo had failed; it now answers
  "nothing changed".
- Reach: unchanged. Route JSON `format` unchanged.

## [1.11.1] — 2026-11-03

### Fixed

- **A draft whose record cannot be saved is undone completely.** When the
  record could not be written (`storage/fude/drafts` blocked by a file, not
  writable, disk full), the draft used to leave its `changes` version and the
  uploaded images behind, answered 500, and every retry got 423 ("unsaved
  Panel changes") until someone cleaned up in the Panel. Now the folder is
  checked before anything is stored. A failure after the content was written
  undoes it: the uploaded files and a created draft page are removed, and the
  page's `changes` version is put back to what it was (removed when there was
  none). A `changes` version an editor started in the meantime is never
  touched, not even when the draft is refused. The same request works again
  once the storage is repaired. If the undo itself fails, the 500 says so
  (check the page in the Panel) and both errors go to the PHP error log.
- **Simultaneous requests are handled one after another.** Two drafts for the
  same page at the same moment could end with the first draft's token bound to
  the second draft's content, so that publishing the first one published the
  second. Draft, publish and discard now hold a lock file
  (`storage/fude/write.lock`) while they work; a request that waits more than
  30 seconds answers 503. The lock holds Fude requests against each other, not
  against Panel saves, which keep using Kirby's own lock and Fude's existing
  checks.
- **The 500 text no longer claims "nothing changed" where that is not
  certain.** A failing draft or schema request keeps "Internal error, nothing
  changed." (a draft now undoes itself). Publish and discard can fail
  half-way and answer "Internal error. Check the page in the Panel before
  trying again."
- **Discard removes the images of a draft whose changes were dropped already.**
  When the draft's `changes` version had been discarded in the Panel, a later
  discard through the route left the uploaded images on the page and said
  `kept: false` all the same. Now the images go with the draft. They stay,
  and the reply says `kept: true`, when the published page or another page
  uses them (the Panel published the draft), when they were edited since, or
  when an editor's own `changes` version sits on top of the draft. The log
  line of a discard now carries `kept`.
- Reach: unchanged. Route JSON `format` unchanged.

## [1.11.0] — 2026-11-02

### Added

- **Several new entries in one draft.** `POST /fude/draft` with `action: create`
  and `entries` (a JSON list, as with `replace`) adds every entry of the list
  to a structure collection in one `changes` version; the image of entry n
  comes as form part `image<n>` (`image0`, `image1`, …). Every row is checked
  like a create, the keys must be free and distinct, `maxEntries` counts the
  published rows plus the new ones, `insert: top` puts them first in their
  order. The record lists `keys`, `entries` and `files`, and discard removes
  every file the draft stored. The schema marks such collections with
  `createMany: true`. For a chat where several photos arrive as one message.
  A `pages` collection, `fields` or a single `image` next to `entries`, and an
  `image<n>` that names no entry are refused (422).
- Reach: unchanged. The same collections and fields; a create may add several
  entries at once where it could add one.

### Changed

- The reply to a malformed `entries` names the action (`create` or `replace`).

## [1.10.0] — 2026-10-31

### Changed

- **Options are read as `sayamaapps.fude.<name>`.** The 1.x names `fude.<name>`
  keep working and are read without a warning. If both are set, the new one wins.
- `Options::OPTION_PREFIX` is new. `Options::PREFIX` keeps its 1.x value.

## [1.9.0] — 2026-10-27

### Changed

- **Plugin layout follows the Kirby plugin conventions; behaviour is the same.**
  Classes live in `Sayamaapps\Fude\`, loaded by a class map. The 1.x names
  keep working: the `Fude\` classes (aliases in `legacy.php`, created at
  load time so `catch` and `instanceof` see them), `fude_option()` and
  `fude_not_found()`, the `fude.<name>` options and the `fude.<key>`
  translations. None of them prints a notice.
  Their removal is planned for 2.0 (README, "Planned for 2.0").
- Tests run on PHPUnit; Psalm, PHP Mess Detector and the code style are in CI.

## [1.8.0] — 2026-10-25

### Added

- **`default` for an editable touch.** `'default' => 'saturday'` (any
  weekday, `today` by default) is the day a draft without a date gets: the
  next such day in `fude.timezone`, today included. For a weekly list whose
  "show until" is usually Saturday, so it no longer ends the same day when
  the message names no date. The schema's `touchDate.default` names the day;
  a date in the draft still wins. Only with `editable: true`; anything but
  `today` or an English weekday is a configuration error.

## [1.7.2] — 2026-10-25

### Changed

- **A value for a field the blueprint hides on the entry is a 422.** A
  blueprint `when` decides per entry whether a field counts (a price only
  while the event is not free). Before, a draft could set such a field: the
  publish succeeded and nothing visible changed. Now the draft names the
  condition. The entry's stored values count, with the draft's on top; a new
  entry starts from the blueprint defaults. An emptied field passes. When
  the condition is on a declared field, the bot can send it along.

### Fixed

- **An update can no longer give an entry the key of another one.** Two
  entries with one key could not be named by it afterwards (409 "several
  entries"). Draft and publish both answer 409 now; publish checks again for
  `pages` collections, whose hash covers only the changed page, and for a
  pending create whose key another entry took meanwhile.

## [1.7.1] — 2026-10-25

### Fixed

- **Translation keys in blueprint texts reach the bot as text.** A field's
  `help` that is a translation key (plugin blueprints, such as another plugin's date
  help) went to the bot as the key, in the description of a touch date and of
  fields and collections. It now reads as its text in the reply language, as
  the label already did. A marker's `label` and `description` work the same
  way. Plain text stays as written, and so does a key without a text. A
  translation array as the help of an editable touch date is read too.
- **Discard keeps a file another page uses.** Before, only the draft's own
  page counted: an image that a files field or KirbyText on another page (or
  the site) referred to was deleted with the draft. Now every page, drafts
  and unsaved Panel changes included, in every language, counts, by UUID or
  file id. A file edited since the draft (alt text, replaced image) stays as
  well.
- **A confirmed page delete no longer takes files added or edited after the
  draft.** The delete draft fingerprints the page's files (names, content,
  metadata); a Panel upload, a replaced file or an edited alt text in between
  answers 409, as a content edit already did. A page with a file another page
  uses is not deleted by the bot either (409).
- **Discard of a created page keeps it when its image was edited or is used
  elsewhere.** Before, an unchanged file name was enough to delete the page
  with an image whose metadata had been edited in the Panel.
- Drafts made before this version carry no fingerprint: their delete answers
  409 and their discard keeps the page and image. Create the draft again.

## [1.7.0] — 2026-10-24

### Reach

- **Child pages as a blueprint marker.** `fude: true` (or `fude: {…}`) on
  a `pages` section of a parent blueprint derives one `pages` collection:
  the parent's children with the section's template, as `kind: pages` in
  `fude.php` does. The fields are `title` and the fields marked in the
  child template's blueprint. Options: `name`, `label`, `description`,
  `key`, `actions` (default `create`, `update`), `status`, `template`,
  `readImages`. Pages of that template get no `page` collection of their
  own, so a calendar with 50 events stays one collection under
  `maxCollections`. Existing markers reach nothing new.

### Added

- **A `pages` collection in `fude.php` for the parent and template of a
  marked section replaces the derived one**, whatever its name: a site that
  declared its child pages before keeps exactly one collection.
- **Translated marker texts.** `label` and `description` in a marker and a
  field's `help` may be translation arrays (plugin blueprints), read in the
  reply language. Before, an array help left the description empty.

### Changed

- The 500 for a marked `files` field on a page names both places where
  images are allowed.

## [1.6.1] — 2026-10-24

### Fixed

- **The validator rejects the `gist` tag and `style:` attributes in chat
  content** (`text`, `textarea` and `select`, also inside a nested tag).
  Sites render these fields with KirbyText, and both could put foreign
  script or styles on the public site. All other KirbyTags pass as before.

## [1.6.0] — 2026-10-23

### Added

- **Asked fields.** `ask: true` in `fude.php` or `fudeAsk: true` at a
  blueprint field: `/fude/schema` says `ask: true`, and the bot asks for
  the field before the first draft and takes "don't know". For what a
  visitor would miss but an entry is not wrong without (the end of an
  event, its price). `fudeAsk` is no marker and reaches nothing. A
  required field is never asked.
- **`requiredWhen` and `askWhen` in `/fude/schema`** for a field with a
  blueprint `when` on a field of the collection, with the values as
  stored (`{"online": "true"}`). Each key only when set: a schema without
  them reads as before.

### Changed

- **A required blueprint field with `when` is required only while it is
  shown,** as in the Panel, where Kirby skips the check of a hidden
  field. A condition on a field the collection does not declare is
  decided by that field's blueprint default: a field that would never be
  shown is no longer required from the bot (before, Fude asked for it
  anyway), one that is always shown stays required. A condition on a
  declared field moves the check to Kirby, which refuses a draft that
  meets it and leaves the field empty.

## [1.5.0] — 2026-10-22

### Reach

- **New field type `page`: a reference to one page.** It sets a blueprint
  `pages` field that holds one page (`multiple: false` or `max: 1`) to one
  of the pages the field's `query` names, for example the venue of an
  event. The options are the page titles (`Title (page/id)` where two
  titles are the same); a draft sends a title and the page stores the
  reference as the Panel does, `- page://<uuid>` (or the id with
  `store: id`). Works in all three kinds of collection, in `fude.php` and
  as a blueprint marker. A `pages` sub-field of a marked structure joins
  only when marked itself, so existing marked structures reach nothing new.

### Added

- **Page fields in `/fude/schema`:** `type: page` with `options` (the
  titles); entries show the title of the referenced page.

### Changed

- **Required fields with a default, in a `pages` collection,** are no
  longer required from the bot: Kirby fills the default when it creates
  the child page (an event's status "scheduled", say). The schema says
  `required: false` for them. Emptying one on an existing page is still
  refused by Kirby's blueprint check. A field declared `required: true`
  in `fude.php`, and every field of a structure row (rows get no
  defaults), stays required.

### Notes

- A page field whose query yields no page is left out of its collection,
  also when it is required: content never makes the configuration
  invalid. Kirby's own check still refuses a draft that needs the field.

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
