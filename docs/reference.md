# Fude reference

For integrators: how blueprint markers and `site/config/fude.php` become collections, fields and conditions. Routes and options are in the [README](../README.md). A configuration error is a 500 on every route and names the collection or field.

## Blueprint markers

Marked fields and sections in a page blueprint derive collections. `fude.php` can override them ([merge rules](#merge-rules)).

| Marker | Result |
|---|---|
| `fude: true` or `fude: {description: …}` on a page field | one `page` collection per page, with all its marked fields |
| `fude: true` or `fude: {…}` on a `structure` field | one `structure` collection |
| `fude: true` or `fude: {…}` on a `pages` section | one `pages` collection: the page's children with the section's template |
| `fude: false` on a field or sub-field | not reachable |

- **Page collection:** name = page id with `/` replaced by `-`; label = page title; actions `update`.
- **Structure collection:** name = `<page-id>-<field>` in lower case, unless `name` is set. Options: `name`, `label`, `description` (default: help text without tags), `key` (default: first text sub-field), `actions` (default `create`, `update`, `delete`), `insert`, `maxEntries`, `touch`, `readImages`. Any other option is a 500.
- **Sub-fields:** `description`; for `files` also `maxWidth`, `alt`, `formats`, `template`. A sub-field of a known type is included without a marker, except `pages`, which needs one.
- **Pages collection** (marked `pages` section): name = `<page-id>-<section>`, unless `name` is set; parent = the page; template = the section's one template, or `template` when it names several. Fields: `title` plus the fields marked in the template's blueprint, with the sub-field options (`files` becomes `image`). Options: `name`, `label` (default: section label), `description` (default: section help), `key` (default `title`), `actions` (default `create`, `update`), `status` (`listed` default, `unlisted`), `template`, `readImages`.
  - Pages of that template get no collection of their own, wherever they are, so many children stay one collection. A site that replaces the parent blueprint keeps the marker, or each child page with marked fields becomes a collection (and `maxCollections` fails).
  - Refused with a 500: a marker on another section type, a section with `parent` or `query`, two marked sections of one page for the same template, a field type Fude cannot map (also `structure`) marked in the template.
  - Example: `events: {type: pages, templates: [event], fude: {key: startdate}}` with `fude: true` at the event fields `startDate` and `venue`.
- **Texts:** `label`, `description` and a field's `help` may be translation arrays (`{en: …, de: …}`); the reply language is used, else English.
- **What to mark:** ordinary content. Leave out legal texts, emergency numbers, address and map, design settings.

| Blueprint type | Fude type |
|---|---|
| `text`, `slug`, `tel`, `email`, `url` | `text` (`tel`, `email`, `url` also set `format`) |
| `textarea`, `writer` | `textarea` |
| `select`, `radio`, `toggles` | `select` |
| `date`, `number`, `time`, `toggle` | same |
| `files` | `image` (inside a structure only) |
| `pages` | `page` (needs `query`; inside a structure only when marked) |

Other types are skipped in a structure and refused when marked. Refused with a 500 that names the page and field: a marker on a site field, an unknown marker option or a wrong value type, `files` on a page field, two collections with the same name (give one `fude: {name: …}`).

## Merge rules

- Markers are read first. `fude.php` then merges by collection name: `false` removes a derived collection, an array replaces it completely, a new name adds one.
- A `pages` collection in `fude.php` with the `parent` and `template` of a marked section replaces the derived one under its own name.
- `blueprints: false` (option): `fude.php` alone decides.
- `maxCollections` counts derived and declared collections together.
- Listed and unlisted pages are scanned; drafts and users never. Fields pulled in with `extends:` count, as Kirby resolves the blueprint.
- Collection names: start with a letter, then `a-z`, `0-9`, `-`, `_`, up to 40 characters. Field names: the same without `-`.

## Declaration (`fude.php`)

- Every declared field must exist in the blueprint with a compatible type: `text` → `text`, `slug`, `tel`, `email`, `url`; `textarea` → `textarea`, `writer`, `text`; `select` → `select`, `radio`, `toggles`; `image` → `files`; `date`, `number`, `time`, `toggle` → the same; `page` → `pages`.
- `key` is a declared `text`, `select` or `date` field, never a `page` field, and always required. Default: the first declared text field.
- A `pages` collection declares a `title` field. A `page` collection has no key and no image field.

| Field option | Meaning |
|---|---|
| `type` | field type (below); default `text` |
| `required` | `true`: required on create, whatever a blueprint `when` says |
| `ask` | `true`: the bot asks for it once before the first draft |
| `description` | shown to the bot; empty by default |
| `maxlength` | positive integer; default 500 (`textarea`: 4000); the smaller of this and the blueprint's wins |
| `options` | `select`: allowed values, a subset of the blueprint's options (none needed if the blueprint has none, e.g. `type: api`) |
| `min`, `max` | `number` bounds; `min: 'today'` only for `date`; `max` only for `number` |
| `formats`, `maxWidth`, `template`, `alt` | `image` (see Image fields) |

Taken from the blueprint: label (translation arrays and keys); `format` (`url`, `email`, `tel` text fields); `required: true`, unless the field is in a `pages` collection with a blueprint `default` (Kirby fills it when it creates the page) or a `when` hides it; `maxlength` and number bounds (the tighter one wins); image `template` from `uploads`.

A value for a field that the blueprint's `when` hides on this entry is a 422, read from the entry's stored values with the draft's on top (a new entry starts from the blueprint defaults). An emptied field passes. When the condition is on a declared field, the draft may send it along; otherwise it changes in the Panel only.

The key stays unique: an update to a key another entry has is a 409, at draft time and again at publish.

## Field types

| Type | Accepts | Notes |
|---|---|---|
| `text` | one line | `url`: `http(s)://` only; `email`; `tel`: optional leading `+`, digits, spaces, `/ - ( )`, at least three digits |
| `textarea` | text or Markdown | |
| `select` | a listed value | values from declaration, blueprint or query |
| `date` | `YYYY-MM-DD`, a real day | `min: 'today'` refuses earlier days; today in `sayamaapps.fude.timezone` |
| `number` | decimal comma or point | up to 15 digits each side; stored with a point |
| `time` | `H:MM` or `HH:MM`, 24 h, optional `:SS` | stored as `HH:MM:SS` |
| `toggle` | `true` or `false` (JSON or text) | stored as `"true"` / `"false"` |
| `image` | upload form part `image` | see Image fields |
| `page` | a title from the query | see Select and page fields |

`text`, `textarea` and `select` refuse HTML tags, comments, `javascript:`, `vbscript:` and `data:` schemes and `&#` entities. Of the KirbyTags, they refuse `(gist: …)` (a script) and a `style:` attribute in any tag (inline CSS); the other tags pass. A plain `<` in prose is fine.

## Required, ask and `when`

- `ask: true` (declaration) or `fudeAsk: true` (blueprint field): the bot asks once before the first draft, together with missing required fields, and takes "don't know". Required fields are never asked. `fudeAsk` is no marker: it never makes a page or structure reachable.
- A blueprint `when` makes a field required or asked only while Kirby shows it:
  - Condition on a field of the same collection: `requiredWhen` / `askWhen` in the schema, values as stored (`{"online": "true"}`). When the draft meets the condition, Kirby's blueprint check refuses an empty value.
  - Condition on a field the collection does not declare: decided by that field's blueprint `default` (a toggle without default is `false`). It holds: plain `required` / `ask`. It does not hold: never required or asked.
  - `required: true` in `fude.php` makes the bot require the field whatever `when` says. Declare it only for fields that are always shown.
- Example: an online event's `link`, `required: true` with `when: {online: true}` in the blueprint, is required for online events only.

## Select and page fields

- **Select with `options: {type: query, …}`:** values come from the query, resolved on every request against the collection's page. For a `pages` collection the page is an unsaved probe, so name the page in the query, e.g. `site.find('filiale').bereiche.toStructure`. Values are the query's `value`s as text. An empty result leaves the field out of the collection; if it is required or the key, that is a 500. A failing query is a 500 naming collection and field.
- **Select with `options: {type: api}`:** not resolved; the values declared in `fude.php` are the allowed set.
- **Page field:** a blueprint `pages` field with `multiple: false` or `max: 1` and a `query` (a site or page stands for its children). Values are the page titles; two equal titles are listed as `Title (page-id)`. A draft may write the title in any case. It is stored as Kirby stores it: `- page://…`, or the id with `store: id`. An empty value clears the field. A query that yields no page leaves the field out, even if required. Several pages, or no query, is a 500.

## Image fields

- Declared as `image` on a `structure` or `pages` collection.
- The upload (form part `image`) goes to the collection's first image field in declaration order. A second image field cannot be filled through the bot, a text value for any image field is refused, and an upload to a collection without one is a 422. **Rule: one image field per collection.**
- `formats`: `jpg`, `png`, `webp`, `gif`; default `jpg`, `png`, `webp`. The format is read from the file content, not its name.
- `maxWidth`: 16–8000, default 1600. The photo is resized and only the copy is stored.
- `alt`: a declared text field (not an image) whose value becomes the alt text; default: the key.
- `template`: Kirby file template; default: the blueprint's `uploads` template.

## Schema fields

`GET /fude/schema` lists per field: `type`, `label`, `required`, `description`, `maxlength`, `options` (select, page), `format`, `maxWidth` and `formats` (image), `min`, `max`. `ask`, `requiredWhen` and `askWhen` appear only when set.
