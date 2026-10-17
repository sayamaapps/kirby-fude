# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to what a declaration can reach** (new field types, new kinds
  of collections, a route that writes something new) is at least a **minor**
  version and is described under "Reach" so a site operator can re-check
  their `fude.php`.
- A change to the privacy text (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the plugin must then review its
  privacy policy.
- The route JSON carries `format`; it increases only when a field is
  removed, renamed or changes meaning. New fields alone don't increase it.

## [Unreleased]

### Fixed

- **Discarding a created page no longer deletes a Panel editor's work.**
  A page Fude created as a draft was deleted recursively as soon as it was
  still a draft. It is now deleted only while it is as the draft left it:
  same content as at draft time, no Panel lock or unsaved Panel changes, no
  subpages or subpage drafts, no files besides Fude's own image. Otherwise
  the page stays, the draft record goes, and the reply says `kept: true`.
  **[privacy text]** A discarded draft is deleted unless it was edited or
  published in the Panel meanwhile (`PRIVACY.md`, `DATENSCHUTZ.md`).

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
