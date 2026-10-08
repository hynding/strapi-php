# JSON `{}` vs `[]` in request bodies

`json_decode($json, true)` turns both `{}` and `[]` into a PHP `[]`. Non-empty containers stay
distinguishable (`array_is_list`); the empty ones do not. So validation that has to tell an
object from an array could not do it: a single component sent as `[]`, or a repeatable component
or dynamic zone sent as `{}`, was accepted (and could store an empty component) where upstream
answers 400. Upstream's API tests check this (`core/*/components/*.test.api.js`).

## Design: an `EmptyObject` marker, opted into by the readers that need it

- `Strapi\Utils\EmptyObject` (packages/core/utils/src/empty-object.php) stands for a JSON `{}`.
  It is immutable and empty. It still reads like an empty array (`$v['k'] ?? null`, `isset`,
  `count`, `foreach`, `(array) $v`), is JS-truthy as `{}` is, and `json_encode` gives `{}`.
  `EmptyObject::decode()` is `json_decode($json, true)` except that `{}` becomes the marker.
  It only walks the result when the document contains `{` followed by `}`.
- `strapi::body` decodes JSON bodies, and the JSON fields of multipart bodies, with it.
- `$ctx->requestBody()` returns the body **without** markers (every `{}` back to `[]`, computed
  once), so the hundred-odd controllers that read bodies as arrays see exactly what they saw
  before. Only the readers that pass document data to the entity validator ask for markers with
  `$ctx->requestBody(true)`. These are the content API `create`/`update` actions (core-api
  controllers), the content manager's create/update/clone (collection types) and
  create-or-update (single types) actions, and i18n's `validate-locale-creation`, which writes
  the body back. A top-level `data: {}` is still normalised to `[]`.
- Even there, the markers are kept only where they matter: `EmptyObject::keepInDocumentData()`
  walks the data with the content-type schema and keeps them in component and dynamic-zone values
  (at any depth) and in `json` attributes. Everywhere else they turn back into `[]`: relation
  payloads (`{ options: {}, connect: [] }`), media, and keys the schema does not know. The
  sanitize and validate visitors read those values as arrays. A first version that kept every
  marker made `core/strapi/api/relations.test.api.ts` fail, because `options: {}` was refused as
  an invalid key.
- Validators: yup `object()` accepts the marker and validates its fields as for `[]`, and the
  cast keeps the marker unless it adds keys. `array()` rejects it, and the message prints `{}`. Zod
  `object`/`record` accept it and keep it when nothing is added, `array` rejects it, and
  `parsedType` says `object`. Because a PHP `[]` that does not come from a marked body is still
  ambiguous (config files, internal callers), yup objects keep accepting `[]` by default. The
  entity validator calls `->rejectEmptyList()` on component and dynamic-zone item schemas, where
  `[]` is a JSON array, as in upstream.
- Responses: the marker serializes as `{}`. A content `json` attribute stored as `{}` (`api::`
  content types and components) is read back as the marker by the database layer
  (`JsonField::fromDBKeepingEmptyObjects`), so it is answered as `{}`. Internal models
  (`admin::`, `plugin::`, `strapi::`) keep reading `{}` as `[]`.

## Alternatives not taken

- Decode every JSON object to `stdClass`, or pass the marker to every controller: every
  `is_array($body['x'])`, `array_merge`, `=== []` on a body value would change behaviour. Some of
  those changes would be silent (`is_array` false), some fatal (`TypeError`).
- A side map of the JSON paths that were `{}`: the document data is sanitized, transformed and
  re-keyed between the controller and the entity validator, so the paths do not survive.
- Query strings: qs has no `{}`/`[]` distinction for empty values, so they are parsed as before.
