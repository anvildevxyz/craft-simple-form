# Field Types

Simple Form ships **33 field types**, covering everything from a plain text box
to composite name/address blocks, element pickers, a drawn signature, and a
server-computed calculation. This guide is a reference for every type and the
real config options each one exposes.

Eleven of them are **Pro** — Signature, Payment, Rating, Opinion Scale,
Calculation, Repeater, and the five element relations — and are marked as such
below. The other 22 are available on Solo. See [Editions](editions.md).

## Adding fields

Fields are added in the **form builder** (a form's edit screen in the Control
Panel): pick a type from the palette, drop it into the layout, and configure it
in the right-hand **inspector**. The builder serializes each field to a small
JSON `config`, so everything below maps to an inspector control.

Three settings are shared by (almost) every field:

- **Label** — the visible field label. Translatable per site.
- **Help text** — optional instructions shown under the label. Translatable.
- **Required** — a static required toggle. A field can *also* be made required
  by [conditional logic](conditional-logic.md), independent of this toggle.
- **Error message** — an optional per-field override of the default validation
  message ("Leave blank to use the default").

Most input fields also accept a **Placeholder** where it makes sense.

### How validation works

Every field type validates itself **server-side** on submit — the authoritative
check. Client-side HTML attributes (`required`, `pattern`, `min`/`max`, …) are a
convenience layer only; a forged or scripted POST is always re-checked in PHP.
Where a field has a closed set of valid values (choice options, element ids,
scale points, allowed countries), membership is enforced server-side so an
out-of-set value is rejected regardless of what the browser sent.

A few types collect **no** submitted value at all — the layout blocks (Heading,
Divider, HTML, Text) render on the page but are skipped by validation, storage,
and export.

---

## Text inputs

Single-value text-style controls. All store the posted string verbatim.

### Text (`text`)

A single-line `<input type="text">`.

- `minLength` — minimum character count.
- `maxLength` — maximum character count.
- `placeholder`

### Email (`email`)

A single-line `<input type="email">`, validated as a well-formed address.

- `placeholder`

### URL (`url`)

A single-line `<input type="url">`, validated as a well-formed URL. A
scheme-less entry is normalized before storage, so `example.com` is stored and
validated as `https://example.com`.

- `placeholder`

### Textarea (`textarea`)

A multi-line `<textarea>` (6 rows).

- `minLength` — minimum character count.
- `maxLength` — maximum character count.
- `placeholder`

### Number (`number`)

An `<input type="number">`, validated as numeric and within bounds.

- `min` — minimum value.
- `max` — maximum value.
- `placeholder`

### Phone (`phone`)

An `<input type="tel">`, optionally preceded by a dial-code country selector.
The posted value is normalized server-side to a `{raw, e164, country}` map, so
exports and integrations receive a clean `+<digits>` number; a national number
gets the selected country's dial code prefixed and a single trunk-prefix zero
dropped.

- `showCountrySelector` — render the dial-code prefix `<select>`.
- `defaultCountry` — ISO alpha-2 code, preselected and assumed for national
  numbers (default `CH`).
- `allowedCountries` — restrict the selector to a set of ISO codes (empty = full
  list). A POST selecting a disallowed country is rejected.
- `minDigits` / `maxDigits` — digit-count bounds (defaults 7 / 15).
- `pattern` — a custom regex applied over the normalized digits (a malformed
  pattern is ignored rather than failing the submission).
- `placeholder`

### Hidden (`hidden`)

A `<input type="hidden">` whose value is resolved at render time from a
configurable source and captured for tracking/attribution. It renders bare — no
label or wrapper — though it still carries a translatable label used only as the
export/CP column heading.

- `source` — `static` | `query` | `user` | `cookie` (default `static`).
- `default` — fallback when the source yields nothing.
- `queryParam` — URL query-parameter name (source = `query`).
- `userAttribute` — `email` | `id` | `username` (source = `user`).
- `cookieName` — cookie name (source = `cookie`).
- `maxLength` — sanity bound (default 255).

> **Security:** for the `user` source the value is **re-resolved server-side**
> at submit time from the authenticated identity — a forged hidden value cannot
> impersonate another user. `static` / `query` / `cookie` values are inherently
> client-influenced and pass through sanitized and length-bounded.

### Date (`date`)

An `<input type="date">`, validated as a parseable date. No extra options beyond
the shared ones.

### Time (`time`)

An `<input type="time">` — a time of day (24-hour `HH:MM`) independent of any
date. No extra options beyond the shared ones.

### Date & Time (`datetime`)

An `<input type="datetime-local">` capturing a date and a time in one control,
stored as `YYYY-MM-DDTHH:MM` and validated with the same mechanism as the Date
field. No extra options beyond the shared ones.

---

## Choice fields

Fields backed by a closed set of options or a numeric scale. The option-based
types (Select, Checkbox, Radio) require a non-empty **options** list, each entry
being a `{value, label}` pair edited in the inspector's options editor; a posted
value outside the set is rejected.

### Select (`select`)

A single-choice `<select>` dropdown.

- `options` — the `{value, label}` list.

### Checkbox (`checkbox`)

A multi-select checkbox group (posts an array). Every posted value must be a
known option.

- `options` — the `{value, label}` list.

### Radio (`radio`)

A single-choice radio group.

- `options` — the `{value, label}` list.

### Rating (`rating`)

> **Pro edition.** This field type requires [Pro](editions.md).

A star / heart / number rating over a configurable maximum. Rendered as an
accessible native radio group (keyboard-navigable, works without JS) styled into
glyphs by the asset bundle. The chosen value is stored as an **integer** so
analytics and exports treat it numerically.

- `max` — the maximum, clamped to 1–10 (default 5).
- `iconStyle` — `star` | `heart` | `number` (default `star`).

### Opinion Scale / NPS (`opinion`)

> **Pro edition.** This field type requires [Pro](editions.md).

A horizontal opinion / Net Promoter scale over a configurable integer range,
with optional left/right anchor labels. Like Rating, it renders as an accessible
radio group and stores an **integer**.

- `min` — lower bound (default 0).
- `max` — upper bound (default 10; the span `max − min` is clamped to 10
  discrete points).
- `leftLabel` — translatable left anchor (e.g. "Not likely").
- `rightLabel` — translatable right anchor (e.g. "Very likely").

### Agree / Consent (`consent`)

A single, normally-required GDPR consent checkbox with a translatable rich label
that may carry one safe inline link (`I agree to the [privacy policy](…)`). The
box must be actively ticked to submit — enforced server-side on every channel.

Instead of a bare boolean, a passing submission stores an **auditable consent
record** — `{consented, consentedAt, textVersion, textHash}` — with the
timestamp stamped server-side and the consent text snapshotted and hashed, so a
later policy edit is detectable.

- `consentText` — the translatable rich label (one optional `[label](url)`
  token, rendered safely).
- `required` — defaults on; may still be scoped by conditional logic.
- `requiredMessage` — overrides the default "You must agree before submitting."

---

## Composite fields

A single field that renders several labelled sub-inputs, stores them as a
sub-part map, validates each part, and flattens to one export column per part.
Each sub-field is individually toggleable; the field-level **Required** toggle
makes the *primary* sub-fields mandatory.

### Name (`name`)

Prefix / first / middle / last / suffix sub-inputs. Defaults to **first + last**
enabled (both primary); the rest off.

- `subFields` — per-sub overlay of `{enabled, required, label}`, keyed by
  `prefix` / `first` / `middle` / `last` / `suffix`.

### Address (`address`)

Line 1 / line 2 / city / state-region / postal code / country sub-inputs. All
are text inputs except **country**, a `<select>` whose options come from Craft's
`CountryRepository` at runtime (localized to the install's language, never
hardcoded). line1 / city / postalCode / country are primary.

- `subFields` — per-sub overlay of `{enabled, required, label}`, keyed by
  `line1` / `line2` / `city` / `state` / `postalCode` / `country`.
- `enableAutocomplete` — the **Enable Autocomplete** toggle renders a *"Search
  for an address"* box above the sub-inputs that suggests addresses as the
  visitor types (min. 3 characters) and fills the sub-fields from the picked
  suggestion.

#### Address autocomplete

Autocomplete is **opt-in per field**; the *provider* is chosen globally in
**Settings → General → Address Autocomplete**:

- **Provider** (`addressAutocompleteProvider`) — **Photon** (default) or
  **Nominatim**, both keyless OpenStreetMap services.
- **Endpoint override** (`addressAutocompleteEndpoint`) — point at a
  self-hosted Photon/Nominatim instance; blank uses the provider's public
  endpoint (mind the public services' usage policies on high-traffic forms).
- **API key** (`addressAutocompleteApiKey`) — only for providers that require
  one; the keyless OSM providers ignore it. The key is passed to the browser,
  so use a referrer-restricted key and keep it in an env variable.

Suggestions are fetched **directly from the visitor's browser to the
provider** (no server proxy), so typed address text is sent to that third
party — worth a line in your privacy policy. The feature is fully progressive:
without JavaScript (or if the lookup fails) the plain sub-inputs work
unchanged, and the chosen values are validated server-side like any manual
entry.

---

## Specialized fields

### File Upload (`file`)

Uploaded files are saved as Craft **Assets** in the configured volume; the
stored value is the list of asset ids. Server-side validation enforces count,
size, the extension allowlist, and a content-sniff that rejects executable /
script payloads even when disguised by extension.

- `volume` — the target asset-volume handle.
- `allowedExtensions` — CSV / list of allowed extensions (empty = any).
- `maxSize` — maximum size per file, in MB.
- `multiple` — allow more than one file.

### Signature (`signature`)

> **Pro edition.** This field type requires [Pro](editions.md).

The visitor signs on an HTML `<canvas>`; the drawing is serialized to a PNG data
URL, decoded server-side into a Craft **Asset**, and stored as an asset id list
(same shape as File Upload) — so the signature gets thumbnails, permissions,
deletion, and retention for free.

- `volume` — the target asset-volume handle (optional).
- `penColor` — pen color (default `#1a1a1a`).
- `background` — pad background color (default `#ffffff`).

### Calculation (`calculation`)

> **Pro edition.** This field type requires [Pro](editions.md).

A read-only, computed display field whose value is derived from a formula
referencing other fields of the same form by handle, e.g. `{quantity} *
{unitPrice}`. The formula runs through a safe expression engine (arithmetic
operators, parentheses, `min` / `max` / `round` and similar). The value is
**recomputed authoritatively on the server** at submit time — the client-posted
value is never trusted.

- `formula` — the allow-listed expression (required).
- `decimals` — display precision 0–6 (default 2).
- `thousandsSeparator` — group the integer part (default off).
- `prefix` — display prefix, e.g. `CHF ` (translatable).
- `suffix` — display suffix, e.g. ` kg` (translatable).
- `missingAsZero` — treat a missing/non-numeric reference as 0 (default on).

### Repeater (`repeater`)

> **Pro edition.** This field type requires [Pro](editions.md).

A container holding a small set of inner sub-fields the visitor can repeat ("Add
another"). The submitted value is an ordered array of row objects keyed by inner
handle; wholly-empty rows are dropped and unknown keys stripped. v1 is
deliberately constrained — inner types are limited to **text, email, number,
select**, with no nested repeaters, in-row conditional logic, or file/payment
inner types.

- `fields` — the inner field definitions (`{handle, type, label, required,
  …}`). Each inner field carries its own type's options (e.g. select `options`,
  number `min`/`max`).
- `minRows` — minimum rows (a required repeater implies at least 1).
- `maxRows` — maximum rows (0 = unbounded).
- `addButtonLabel` — custom "Add another" label.

### Payment (`payment`)

> **Pro edition.** This field type requires [Pro](editions.md).

Collects a payment as part of the submission via **Craft Commerce** (a soft
dependency). On the front end it renders the configured gateway's embedded
payment form (card fields, etc.); the charge is processed **before the
submission is saved** (a decline saves nothing), and notifications / integrations
are withheld until it settles. Without Commerce the field degrades to an
informational note. The field collects no posted value of its own.

- `amountType` — `fixed` | `field` (default `fixed`).
- `amount` — the fixed amount (when `amountType` = `fixed`).
- `amountField` — handle of a numeric field holding the amount (when `field`).
- `minAmount` / `maxAmount` — optional bounds on the resolved amount; an
  out-of-range charge is rejected (most useful with `amountType: field`).
- `currency` — ISO currency code (informational; the Commerce store currency is
  authoritative).

> See the **[Payments guide](payments.md)** for setup (gateway + Donation
> purchasable), the pay-to-submit flow, offsite/3-D-Secure handling, payment
> status and abandoned-checkout expiry, and the CP surfaces.

---

## Element relations

> **Pro edition.** All five relation types (`entry`, `category`, `tag`, `user`,
> `asset`) require [Pro](editions.md).

These let a visitor select one or more live Craft elements of a single type,
constrained to configured sources. The selected element **ids** are stored
(single-select still stores a one-element list for a uniform read path).
Validation is entirely server-side: every posted id must belong to an allowed
source — a forged, soft-deleted, or out-of-source id is rejected. Single-select
renders a `<select>`; multi-select renders a checkbox group.

All five share the same options:

- `sources` — list of allowed source handles, or empty / `*` for any source of
  that type.
- `multiple` — single vs. multi select.
- `limit` — maximum selectable when `multiple` (single-select ignores it).

| Type | Handle | Selects | Source = |
| --- | --- | --- | --- |
| Entries | `entry` | Entries | sections |
| Categories | `category` | Categories | category groups |
| Tags | `tag` | Tags | tag groups |
| Users | `user` | Users | user groups |
| Assets | `asset` | Assets | volumes |

> Relation option titles and id resolution are multi-site aware: options render
> in the current site's language, while membership validation searches across
> all sites so a form on a non-primary site still accepts ids that live
> elsewhere.

---

## Layout / content blocks

Presentational blocks that render on the form but collect **no** submitted value
— they are skipped by validation, storage, and export. They can still be shown
or hidden by [conditional logic](conditional-logic.md) and placed on a step/page.

### Heading (`heading`)

A section heading (`<h2>` / `<h3>` / `<h4>`, constrained to that range so it
never breaks the page's document outline). The heading text is the field's
translatable **label**.

- `level` — `h2` | `h3` | `h4` (default `h3`).
- Heading text — authored in the field **label** (per-site translatable).

### Divider (`divider`)

A horizontal rule (`<hr>`) with an optional label shown over the line. The label
is the field's translatable **label**; leave it blank for a plain rule.

- Label — authored in the field **label** (per-site translatable, optional).

### Text (`paragraph`)

A static paragraph of instructions or copy between fields. The body is treated
as **plain text** — it is HTML-escaped with line breaks preserved, never parsed
as HTML or Twig — making it the safe, low-friction sibling to the HTML block
(no sandbox, no permission gate needed). The type handle is `paragraph`
(`text` was already taken by the single-line input); the builder palette
labels it **Text**.

- Body text — per-site translatable multi-line copy. An empty body renders
  nothing.

### Callout (`callout`)

A toned panel of guidance between fields — the same escaped plain-text handling
as the Text block, wrapped in a tone-classed container with an optional icon.
Use it to draw the eye to something the Text block would state too quietly.

- `tone` — `info` | `success` | `warning` | `error` (default `info`). An
  unrecognized tone falls back to `info`.
- `icon` — an optional short glyph or label shown alongside the body.
- Body text — per-site translatable multi-line copy. A callout with neither a
  body nor an icon renders nothing.

### HTML Block (`html`)

A CP-authored HTML/Twig block. The body is authored in the field's **help text**
and rendered through the plugin's forced-Twig-sandbox path, then passed through
an allowlist HTML purifier — `<script>`, inline event handlers, `javascript:` /
`data:` URLs and `craft.app`/queries are all stripped.

- HTML / Twig body — authored in the field **help text** (per-site
  translatable).

> Authoring an HTML block's body requires the **`editHtmlBlocks`** permission.
> A sandbox rejection logs a `simple-form` warning and renders nothing rather
> than leaking raw Twig or breaking the page.
</content>
</invoke>
