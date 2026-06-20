# PRD — Field type: Signature

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#129](https://github.com/fabianhaef/craft-simple-form/issues/129)

---

## 1. Problem Statement

Forms that act as agreements — consent forms, waivers, delivery confirmations,
contractor sign-offs — need the visitor to **draw a signature**. Simple Form has
no way to capture one. Creators fall back to a "type your name" text field, which
carries none of the visual/legal weight of an actual signature and gives the form
owner nothing to show as proof of sign-off.

A signature is also **personal data**. Whatever we build has to flow through the
plugin's existing retention/anonymization machinery (`RetentionService`) so it is
purged or scrubbed on the same schedule as other PII — a signature that lingers
forever in an asset volume is a GDPR liability.

The natural fit is a **canvas signature pad** that, on submit, serializes the
drawing to a PNG and stores it as a Craft **Asset** — reusing the exact upload →
asset pipeline (`AssetUploadService`) that the File field already uses, so the
signature lands in a managed volume with all the asset tooling (thumbnails,
permissions, deletion) for free.

## 2. Goals

- A **Signature** field type rendering an HTML `<canvas>` signature pad with a
  **Clear** control.
- On submit, serialize the drawing to a **PNG data URL**, decode it server-side,
  and save it as a Craft **Asset** via the existing
  `AssetUploadService`/volume mechanism; store the resulting **asset id** in
  `submission.data` (same shape as the File field).
- **Required validation:** a "required" signature must be **non-empty** (the
  visitor actually drew something), enforced server-side.
- **Mobile / touch support:** the pad works with touch and stylus, not just mouse.
- A dedicated **JS asset bundle** for the pad (CP preview + front end).
- **Retention / GDPR:** the signature asset is treated as personal data — deleting
  or anonymizing a submission (per `RetentionService`) also removes/orphans the
  signature asset; anonymization scrubs the reference.
- **Export:** the signature appears in the submission detail and export as a
  **link to the asset** (and a thumbnail in the CP), not raw base64.
- Multi-site safe; translatable labels; PHPStan L7 + ECS clean; no breaking
  changes.

## 3. Non-Goals (v1)

- Cryptographic / legally-binding e-signature (DocuSign-style audit trails,
  certificates). v1 captures a drawn image, not a signed document.
- Typed-name "signature" fonts or initials capture.
- Multiple signatures per field. One pad → one asset.
- SVG vector storage. v1 stores a rasterized PNG (smaller, simpler, and the File
  pipeline's content sniffing already understands images; note SVG is on the
  File field's *blocked* MIME list for safety).
- Re-editing a previously captured signature on the submission detail screen.

## 4. Users & Use Cases

- **Waiver/consent form:** a gym membership or event waiver where the visitor signs
  to accept terms.
- **Delivery / field-service confirmation:** a courier captures the recipient's
  signature on a phone (touch).
- **Form owner (CP):** opens a submission and sees the rendered signature image,
  or clicks through to the asset; exports submissions and gets a link per row.
- **DPO / compliance:** relies on retention so signatures auto-purge after the
  configured window and anonymization removes them on request.

## 5. Proposed Solution

### 5.1 `SignatureFieldType`

New `src/fields/SignatureFieldType.php` extending `FieldType`, registered in
`FieldTypeRegistry::init()`:

- `getType() => 'signature'`, `getLabel() => 'Signature'`.
- Config: `required`, optional `volume` (handle of the asset volume the signature
  is saved to — same key the File field uses), optional `penColor`/`background`
  presentational settings.
- `renderInput(string $name, mixed $value = null)` emits:
  - a `<canvas>` element with an `id` derived from `$name`,
  - a hidden `<input type="hidden" name="<name>">` that the JS fills with the PNG
    **data URL** on draw/clear,
  - a **Clear** button,
  - the markup the asset bundle hooks onto (data attributes for pen color, etc.).
  The hidden input is what posts, so the field flows through the normal
  `field_<id>` body-param path — **not** the multipart file path. (See 5.3 for why
  this still routes through `AssetUploadService`.)

Because the captured value is a base64 PNG, not an uploaded file, the signature
field is handled in `SubmissionService` as a **special case alongside the File
field**, decoding the data URL into a temporary file before handing it to the
asset pipeline.

### 5.2 The canvas pad (JS asset bundle)

A new front-end + CP asset bundle (registered like `SimpleFormCpAsset`, with a
public-facing companion for the rendered form):

- A small, dependency-free signature-pad implementation (or a vendored,
  well-audited library) drawing on the `<canvas>` with `pointerdown/move/up`
  events so **mouse, touch, and stylus** all work; `touch-action: none` on the
  canvas to prevent scroll-while-signing on mobile.
- High-DPI handling (scale the canvas backing store to `devicePixelRatio`).
- On each stroke end and on **Clear**, the script serializes via
  `canvas.toDataURL('image/png')` into the hidden input (empty string when
  cleared) so the current state always posts.
- Responsive sizing; the pad reflows on resize.
- Progressive enhancement: with no JS the field renders a disabled canvas with a
  note; a required signature then can't be satisfied, which is acceptable (signing
  inherently needs JS) — documented behaviour, not a silent failure.

### 5.3 Submit path: data URL → Asset

In `SubmissionService::createFromRequest()`, the field is handled in the same
"special fields" branch as the File field:

1. Read the posted `field_<id>` value (the PNG data URL string).
2. **Validate** (see 5.4) before touching the filesystem.
3. If non-empty, decode the base64 payload, sanity-check it is genuinely a PNG
   (magic bytes / `image/png` MIME via `FileHelper::getMimeType` on content, the
   same content-sniff guard `FileFieldType` already applies), and write it to a
   temp file with a generated name (e.g. `signature-<submissionref>.png`).
4. Pass the temp file to `AssetUploadService::saveUploads()` — extended to accept
   pre-staged temp files, not only `UploadedFile` instances — which saves it as an
   Asset in the resolved volume and returns the asset id.
5. Store the asset id list as the field's value, exactly like the File field, so
   `data['field_<id>']['value']` is `[<assetId>]`.
6. On overall submission failure (validation/honeypot/captcha/spam drop), the
   created signature asset is cleaned up via the **existing**
   `AssetUploadService::deleteAssets()` orphan-cleanup that already runs for file
   uploads — the signature asset id joins `$createdAssetIds`.

This keeps a single asset-creation + cleanup path shared with the File field;
the only new code is the data-URL-to-temp-file decode.

### 5.4 Required validation (non-empty)

- **Client:** the form's submit handler blocks if a required signature canvas is
  empty (hidden input is `''`), showing the field's error.
- **Server (authoritative):** `SignatureFieldType::validate()` /
  `validateUpload()`-style check treats an empty/missing data URL as "no value";
  with `required` set, that yields the standard
  `Craft::t('simple-form', 'This field is required.')`. A malformed data URL
  (present but not a decodable PNG) is rejected with an "invalid signature" error
  so a junk POST can't create a bogus asset. This runs **before** any asset is
  written.

### 5.5 Retention / GDPR

`RetentionService` already purges or anonymizes submissions on Craft GC:

- **Hard delete:** when a submission is deleted, its signature asset must be
  deleted too. The submission's `data` holds the asset id; the deletion path
  (and `RetentionService::purgeSubmissions()` in delete mode) must collect those
  asset ids and call `AssetUploadService::deleteAssets()` so no orphaned
  signature image survives the submission. This applies equally to File-field
  assets and is the natural place to make asset cleanup a shared concern.
- **Anonymize-in-place:** when `anonymizeInsteadOfDelete` is on,
  `purgeSubmissions()` scrubs the PII-bearing `data`. The signature is PII, so the
  signature asset is **deleted** and the field's value in `data` is scrubbed
  (replaced with a tombstone), keeping the row for aggregate counts but removing
  the image.
- Document that signature volumes should not be public-by-default unless the form
  owner intends signatures to be world-readable (Open Questions).

### 5.6 Submission detail + export

- **CP submission detail** (`templates/submissions`): render the signature as an
  asset **thumbnail/image** (resolve the asset id → `Asset` → transform), with a
  link to the asset. Consistent with how the File field shows uploaded files.
- **Export** (`helpers\SubmissionCsv` / `SubmissionExporter`): the cell holds a
  **URL to the asset** (the asset's URL when the volume is public, otherwise an
  asset reference/id) — never raw base64. This matches the File field's export
  behaviour; the formatting helper should treat signature and file values
  identically (asset-id → URL/reference).

### 5.7 GraphQL / headless

The submit mutation accepts the PNG data URL as the field value (a string), routed
through the same validate + decode + asset path. The submission's signature value
is exposed as the asset id / asset URL in the form payload type, like the File
field.

## 6. Acceptance Criteria

- [ ] `SignatureFieldType` (`signature`) exists, extends `FieldType`, and is
      registered in `FieldTypeRegistry::init()`.
- [ ] The field renders a `<canvas>` pad + hidden input + Clear control via a
      dedicated JS asset bundle (CP preview + front end).
- [ ] Drawing works with mouse **and** touch/stylus; the pad is high-DPI crisp and
      responsive.
- [ ] On submit, a drawn signature is serialized to a PNG data URL, validated as a
      real PNG, and saved as a Craft Asset via `AssetUploadService`; the asset id
      is stored in `submission.data` like a File field.
- [ ] A required signature that is empty is rejected server-side with the standard
      required message **before** any asset is created.
- [ ] A malformed/non-PNG data URL is rejected; no asset is created.
- [ ] On a failed submission, the signature asset is cleaned up (no orphans),
      reusing the existing `deleteAssets()` path.
- [ ] Hard-deleting a submission deletes its signature asset; anonymizing scrubs
      the value and deletes the asset.
- [ ] CP submission detail shows the signature image/thumbnail + asset link.
- [ ] CSV/JSON export emits an asset URL/reference, never raw base64.
- [ ] GraphQL submit accepts the data URL and exposes the asset id/URL.
- [ ] Multi-site safe; translatable label; PHPStan L7 + ECS clean; no breaking
      changes.

## 7. Testing

**Unit (PHPUnit):**
- `SignatureFieldType::validate()`: empty + required → required error; empty +
  optional → no error; a malformed data URL → invalid error; a valid PNG data URL
  → no error.
- Data-URL decode helper: a known base64 PNG decodes to bytes whose sniffed MIME
  is `image/png`; a `data:image/svg+xml` or `data:text/html` payload is rejected.
- `AssetUploadService` (extended): a pre-staged temp PNG saves to the resolved
  volume and returns an id; the orphan-cleanup deletes it.
- `SubmissionService::createFromRequest()` with a signature data URL: asset
  created, id stored in `data`; on a forced validation failure the asset is
  deleted.
- `RetentionService::purgeSubmissions()`: delete mode removes the signature asset;
  anonymize mode scrubs the value and removes the asset.
- Export helper: a signature value renders as an asset URL/reference, not base64.

**craft-smoke-test scenarios (ship in same PR):**
- Build a form with a required Signature field; render the public form; confirm
  the canvas + Clear button appear.
- Submit without drawing: assert the required error and that no asset was created.
- Draw a signature (simulate pointer strokes) and submit: assert a new Asset
  exists in the volume and the submission's `data` references its id.
- Open the submission detail in the CP: assert the signature image renders with a
  link to the asset.
- Export the submission to CSV: assert the cell is an asset URL/reference.
- Run retention GC with a short window in delete mode: assert the submission and
  its signature asset are both gone; repeat in anonymize mode and assert the asset
  is gone and the value scrubbed.

## 8. Open Questions

- **Volume default & visibility.** Should signatures default to a private volume
  (recommended for PII) with the CP rendering them via a temporary URL/transform,
  rather than a public URL in exports?
- **Vendored pad vs. hand-rolled.** Use a small audited signature-pad library or
  write a minimal `<canvas>` handler to avoid a dependency? Lean toward a tiny
  in-house implementation for footprint + supply-chain safety.
- **Image format/size caps.** Cap canvas dimensions and resulting PNG size to
  prevent huge assets; what limits?
- **Anonymize semantics.** On anonymize, delete the asset and tombstone the value,
  or keep a flattened/blurred placeholder? Proposed: delete + tombstone.
- **Shared asset cleanup refactor.** Making submission deletion clean up *all*
  asset-bearing fields (File + Signature) is a small refactor — confirm we want it
  generalized now rather than signature-specific.
- **No-JS / accessibility.** A drawn signature is inherently non-keyboard. Do we
  offer a "type your name" accessible fallback as a separate setting, or document
  the JS requirement?
