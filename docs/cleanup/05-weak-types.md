# Cleanup Dimension 05 — Weak Types

Research-only audit (no source modified) of the PHP equivalents of `any`/`unknown`:
`mixed`, bare `array`, missing type declarations, `@var mixed`, broad unions, bare
`callable`, and PHPStan suppressions masking weak typing.

Scope: `src/` (223 PHP files). PHPStan **level 7**, currently **green** (`[OK] No errors`).

---

## 1. Critical assessment of typing health

**This codebase is already very strongly typed. The weak-type surface is small and
mostly legitimate.** Headline evidence:

- **Every** docblock array is shaped. `@return array` (191 hits) and `@param array`
  (259 hits): **0** are bare — all carry `array<...>` generics or `array{...}` shapes.
  This is the single biggest signal: someone has already done the array-shape work.
- **No untyped params/returns.** Heuristic scan for functions without a return type
  found **0**. Spot-checks confirm every method declares a return type.
- **No `@var mixed`** (0), **no `@param object` / `@return object`** (0).
- **No `/** @phpstan-ignore */`** inline annotations (0) and **no baseline file**.
  `phpstan.neon` has 5 `ignoreErrors`, all documented framework false-positives
  (Yii `addColumn` ColumnSchemaBuilder, Commerce soft-dep `class_exists` guards, Yii
  `InlineValidator` `$this` rebind). **None mask weak typing.**
- Only **2 bare `callable`**: one is a `@phpstan-type` alias mirroring the
  graphql-php `FieldDefinition` shape (`src/gql/types/SimpleFormObjectType.php:16`);
  one is a render closure (`SafeRenderService.php:84`). Both legitimate.

The only weak type with meaningful volume is **`mixed`** — 128 occurrences in real
signatures (param/return), of which **~74 are field-value handlers** that are
*inherently* dynamic by Craft's field-type contract (see §3). The genuinely
strengthenable set is tiny.

**Forward-looking note:** PHPStan 2.x (level 9 = strict `mixed` membership, level 10
= `mixed`-everywhere) is available; this project is pinned to 1.12 / level 7. The
`mixed` surface below is exactly what a level-9/10 upgrade would surface. The legit
field-value `mixed` would need `@param mixed`/local `assert`/`is_*` narrowing to pass,
not type removal — flag this before any PHPStan-major upgrade.

---

## 2. Findings

### High confidence (strong type certain, won't break PHPStan/runtime)

Honestly, there are **very few**. The strongest array work is already done, so "High"
here means the few `mixed` slots whose domain is provably a narrow union.

**H1 — `McpServer` JSON-RPC `$id` is `string|int|null`, not `mixed`.**
`src/mcp/McpServer.php` — methods `handleToolCall(mixed $id, …)` (213),
`handleResourceRead(mixed $id, …)` (321), `result(mixed $id, …)` (381),
`error(mixed $id, …)` (390).
Evidence: `$id` originates at line 115 `$id = $request['id'] ?? null;` from a decoded
JSON-RPC request. The JSON-RPC 2.0 spec restricts `id` to String, Number, or Null.
Proposed: `string|int|null` (or document with `@param string|int|null $id`).
Risk: **Low–Medium.** `$request['id']` is untrusted decoded JSON, so PHPStan sees its
type as `mixed`; passing it into a `string|int|null` param would require a narrowing
guard (`is_string($id) || is_int($id) || $id === null`) or a cast at the call site to
stay green. Because of that guard requirement, this is borderline — the current
`mixed` is *defensible* as "untrusted-input passthrough echoed back verbatim."
Recommend documenting intent via `@param string|int|null $id` rather than changing the
native type, to avoid adding runtime guards on a hot path.

### Medium confidence

**M1 — GQL coercion helpers genuinely take `mixed`, but could be `@param`-documented.**
`src/gql/resolvers/FormGqlResolver.php:258/263/268` (`stringOrNull`, `intOrNull`,
`floatOrNull`) and `mapConditional/mapRules/mapOptions` (141/163/189).
These receive raw GraphQL arg/source data of unknown shape and *are* the narrowing
layer — `mixed` in is correct. No native-type change recommended. Only a cosmetic
`@param` could be added; low value. **Leave as-is.**

**M2 — `IntegrationsService::normalizeSettings(mixed $raw): array`** (`:471`).
Receives raw decrypted/project-config settings. `mixed` in is correct (the whole point
is to normalize arbitrary input). Return is already `@return array<string, mixed>`.
**Leave as-is.**

**M3 — `CraftElementIntegration::normalizeMapping(mixed $mapping)` (`:341`),
`GoogleSheetsIntegration::orderedMapping(mixed $raw)` (`:442`).**
Both accept a raw stored mapping (string JSON, array, or null) and return a shaped
`list<array{…}>`. `mixed` in is correct given the polymorphic stored form.
**Leave as-is.**

### Low confidence / cosmetic

**L1 — Scalar coercion helpers (`mixed $value): string`).**
`ExportSubmissionsTool::scalar` (`:139`), `SubmissionCsv::scalar` (`:250`) / `cell`
(`:133`) / `entryLabel` (`:424`), `GoogleSheetsIntegration::stringify` (`:375`),
`FieldType::exportValue` (`:86`). These take a *stored field value* — which is
genuinely `scalar|array|bool|null` across all field types — and flatten to a string.
`mixed` is the correct input type. No change.

**L2 — `SubmissionService::valueForField` / `normalizedValueForField` /
`serializeFieldValue` returning `mixed`** (`:1093/:1108/:992`). They read from a
`array<int|string, mixed>` posted map and pass through field-type normalization that
returns `mixed` by design. Narrowing would be false precision. No change.

---

## 3. Spots that should legitimately stay loose (and why)

These are **correct `mixed`** under Craft's field-type architecture — do not "fix":

- **Field-type value contract (~74 signatures).** `FieldType` base + every concrete
  field type (`TextFieldType`, `SelectFieldType`, `CompositeFieldType`,
  `RepeaterFieldType`, `PhoneFieldType`, `ConsentFieldType`, …):
  `validate(mixed $value)`, `renderInput(string $name, mixed $value = null)`,
  `normalizeValue(mixed $value): mixed`, `normalizeStoredValue(mixed): mixed`,
  `serializeValue(mixed): mixed`, `persistValue(mixed, array): mixed`,
  `resolveForSubmit`, `isChecked`, `hasValue`, `decodeValue`.
  Each field type stores a *different* shape (string, int, `list<int>`,
  `array{raw,e164,country}`, repeater row lists, consent records). A shared interface
  over heterogeneous value shapes is exactly the `mixed`/`normalizeValue` pattern
  Craft itself uses (`craft\base\Field::normalizeValue`, `serializeValue`).
- **`FormField` Craft native field hooks** (`src/fields/FormField.php:42/56/76`):
  `normalizeValue`/`serializeValue`/`inputHtml(mixed $value, …)` — these *override*
  `craft\base\Field` signatures; the base signature is `mixed`. Changing them would
  break the override contract. **Must stay `mixed`.**
- **GQL resolver `mixed $source`** (`FormMutations.php:110/214`,
  `FormQueries.php:65/88`): Craft/graphql-php resolver signature is `mixed $source`.
  Required by the framework. **Must stay.**
- **`SubmissionExporter::export(ElementQueryInterface): mixed`** (`:26`): overrides
  `craft\base\ElementExporterInterface::export()` which returns `mixed` (string |
  array | resource). **Must stay.**
- **`integrations/support/SubmissionValues::value(mixed $entry, mixed $default): mixed`**
  (`:24`): a generic accessor over arbitrary stored submission values. Correctly
  polymorphic. **Leave as-is.**
- **Project-config / settings arrays** flowing through integrations and field configs
  are legitimately `array<string, mixed>` (untyped config maps) — already shaped to
  that and correct.

---

## Summary table

| Category | Count | Action |
|---|---|---|
| Bare `@param array` / `@return array` (no shape) | **0** | Already shaped ✅ |
| Untyped params / missing return types | **0** | None needed ✅ |
| `@var mixed` / `@param object` / `@return object` | **0** | None ✅ |
| Inline `@phpstan-ignore` / baseline suppressions masking types | **0** | None ✅ |
| Bare `callable` | 2 | Both legitimate (lib shape / closure) |
| `mixed` in real signatures | 128 | ~74 field-value (legit), rest mostly legit |
| **Genuinely strengthenable** | **~1** (H1, and only via `@param` doc) | Optional, low-value |

**Verdict:** Typing health is excellent. No source change is warranted on this
dimension. The one nameable improvement (H1, document JSON-RPC `$id` as
`string|int|null`) is cosmetic and would need a narrowing guard to satisfy PHPStan,
so even that is optional. The real future work is a PHPStan 2.x / level-9 upgrade,
where the field-value `mixed` surface would need local narrowing — a deliberate,
scoped effort, not a quick "strengthen the type" pass.
