# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

`README.md` documents the same system for end users and project administrators — setup, settings, troubleshooting. This file covers only what matters when *changing* the code. Keep the two in sync when behaviour changes.

## What this is

A REDCap External Module that adds Google Maps address autocomplete to survey and data entry forms. It is a single-file PHP module (`AddressExternalModule.php`) that injects inline JavaScript and CSS into REDCap pages via hooks.

There is no build system, package manager, or test suite — changes are deployed by copying `AddressExternalModule.php` and `config.json` into `redcap/modules/<module_name>_v<version>/` (never rename that directory; the version lives in its name).

**PHP is not installed on the dev machine, so `php -l` has never been run here.** Validate emitted-JS changes with a throwaway Node harness: extract the `<script>` block containing `autocompletePrefix`, regex out the `<?php … ?>` tags to simulate substitution, and parse with `new Function()`. Run it for **both** emit branches — all optional settings mapped and none mapped — since the PHP conditionals produce materially different JS. The same harness can brace-match named helpers out of the source and exercise them directly; use it to re-run the unit-recovery table in `README.md` after touching `recoverUnitFromText()`. Two things the harness must assert because they are silent killers: no unsubstituted `<?php` tag survives, and no empty assignment (`var x = ;`) is emitted.

## Architecture

The module has one PHP class (`AddressExternalModule`) extending REDCap's `AbstractExternalModule`. The two hooks (`hook_survey_page`, `hook_data_entry_form`) both delegate to `addAddressAutoCompletion()`, which — only when both `google-api-key` and `autocomplete` are set — emits:

1. `<script>var __addressAutoKey="…";</script>` and the Google Maps inline bootstrap loader (when `import-google-api` is checked).
2. A `<style>` block for the `#locationField` wrapper.
3. One self-contained IIFE containing all behaviour.

Settings are **baked into the IIFE at emit time**, not read at runtime. Optional features are compiled out entirely (`<?php echo ($latitude ? "…" : ""); ?>`, `<?php if ($recoverUnit): ?>`), so an unconfigured feature emits no code and cannot misfire. Follow that pattern when adding settings.

### JavaScript strategy (inside the PHP-emitted IIFE)

- `waitForImportLibrary()` — polls every 150 ms (15 s timeout) until `google.maps.importLibrary` is a function. The poll matters only when `import-google-api` is off and another module supplies the API late.
- `loadPlacesLibrary()` — `importLibrary('places')`. The **only** library import in the module.
- `initAutocomplete()` — requires `PlaceAutocompleteElement`; no fallback, `showAutocompleteError()` otherwise.
- `initWithNewApi()` — creates `<gmp-place-autocomplete>`; applies prediction filters as *properties* inside try/catch; listens for `gmp-select` (→ `event.placePrediction.toPlace()` → `fetchFields()` → `fillInAddress()`), `gmp-error`, and trusted `input` (records `lastTypedText`; an emptied box calls `fillInAddress(null, …)` to clear everything).
- `applyGeolocationBias()` — assigns a `CircleLiteral` (`{center, radius}`) to `locationBias`. Do **not** reintroduce `google.maps.Circle` here; see the invariants.
- `fillInAddress()` — populates destination fields from `addressComponents[].shortText/longText`, `place.location`, `place.displayName`. `componentForm`'s values are the property names, so the lookup is `comp[componentForm[addressType]]`.
- `updateValue()` — REDCap-aware field setter handling `.hiddenradio`, `<select>` (exact value → underscored value → `Other` → matching option text → alert + blank), and `.rc-autocomplete` dropdowns.
- `showAutocompleteError()` — un-hides the original input and prepends a red banner; data entry is never blocked.
- Unit/sub-premise helpers: `extractUnitParts()`, `recoverUnitFromText()`, `applyUnitToStreetNumber()`, `patchFormattedAddress()`, `applyUnitFromComponents()` (the entry point, called from `fillInAddress()`).

### Key invariants

- **Places API (New) only.** The legacy `google.maps.places.Autocomplete` path was removed deliberately in v1.2 — do not reintroduce it. It rendered as an ordinary text box with no error, the worst failure mode available, and Google no longer enables it for newly issued keys. Missing `PlaceAutocompleteElement` must surface as an error, not a downgrade.
- **`places` is the only library imported.** Never reference `google.maps.Circle`, `LatLngBounds`, or anything else from `maps`/`core` without importing that library — they are `undefined`, and a reference inside an async callback (a geolocation success handler, say) throws where nothing catches or logs it. This is exactly how the location bias was silently broken before v1.2.
- PHP uses a nowdoc (`<<<'SCRIPT'`) for the Google bootstrap loader to prevent PHP from interpreting JS template literals (e.g. `${c}`) as PHP variables. The API key cannot live in a nowdoc, so it is injected via a separate `<script>` tag setting `window.__addressAutoKey`.
- **Never emit a bare PHP value into a JS assignment.** `$toJsArray()` falls back to `[]` when `json_encode()` fails, because `var x = ;` is a syntax error that kills the whole IIFE and leaves a plain text box on the form. Any new setting emitted into JS needs the same guarantee.
- Destination fields are `disabled` on load and re-enabled individually as each receives a value — this prevents manual edits and ensures REDCap saves only autocomplete-populated values. Anything that writes a value must also re-enable its element, or the value is never submitted.
- Destination elements are found by `id = 'googleSearch_' + <google component type>`. That prefix convention is the lookup mechanism for the entire script.
- A guard at the top of `$(document).ready` exits early if the autocomplete source field is not present on the current form, avoiding errors on multi-instrument projects.
- The source field is hidden, not removed. It still submits, and it holds the full formatted address.
- **Do not add `subpremise` to `componentForm`.** That object doubles as the registry of "components with a destination element", and every entry is cleared through `updateValue(autocompletePrefix + type)` on each selection. No `googleSearch_subpremise` element exists, so an entry would only log "Could not find the element" every time. `extractUnitParts()` handles the unit instead.
- `applyUnitFromComponents()` must run **after** the component loop, so `3/27` overwrites the bare street number that loop just wrote.
- `administrative_area_level_2` (county) strips the word "County" from the value before saving.
- `doBranching()` is called after any fill/clear operation if it exists — this triggers REDCap's branching logic to re-evaluate field visibility.

### Unit / sub-premise recovery

Two independent causes of a lost unit number, both handled:

1. **Google returned `subpremise`, module ignored it.** `extractUnitParts(components)` walks the raw component list independently of `componentForm`, reading `comp.shortText || comp.longText`. Always on.
2. **Google omitted `subpremise`** (documented Places API limitation for AU/UK unit addresses). `recoverUnitFromText()` parses the unit from what the user typed. Gated behind `recover-unit-from-input`, default off.

`recoverUnitFromText()` guards — each exists because of a real false positive; keep them if the heuristic is rewritten:

- Street number matched on a word boundary, so `7` is not found inside `27`.
- Only the prefix before the street number is considered, max 24 chars (rejects `The Old Rectory, 27 Harris St`).
- Trailing hyphen/dash rejected — `27-29 Harris St` is a range, not a unit.
- The prefix must *end* with a unit token, optionally introduced by `unit|apt|apartment|flat|suite|ste|shop|villa|lot|level|lvl|room|rm` (rejects `Harris St 27`).
- Returns `''` rather than guessing.

Raw typed text lives in `lastTypedText`. The widget's shadow root is **closed**, but `input` events are composed (they cross it and retarget to the host) and `PlaceAutocompleteElement.value` is public API — no `attachShadow` patching needed; `e.isTrusted` filters out the value the widget writes back itself. `lastTypedText` is consumed (cleared) after each selection.

The unit is prepended to the existing Street Number Field as `3/27` rather than given its own field — no data dictionary change, but that field must have no integer/number validation.

## config.json settings keys

| Key | Purpose |
|-----|---------|
| `google-api-key` | Required. Google Maps API key. |
| `autocomplete` | Required. The source text field the widget attaches to; also receives the formatted address. |
| `street-number`, `street`, `city`, `county`, `state`, `zip`, `country` | Optional destination fields for address components. |
| `latitude`, `longitude` | Optional destination fields for coordinates. Looked up by *name* in `updateValue()`, not by `googleSearch_*` id. |
| `place-name` | Optional field for the place's `displayName`. |
| `recover-unit-from-input` | Checkbox, default off — parse the unit from typed text when Google omits `subpremise`. |
| `included-region-codes` | Optional comma-separated CLDR region codes (max 15) → `includedRegionCodes`. |
| `included-primary-types` | Optional comma-separated place types (max 5) → `includedPrimaryTypes`. Too narrow a value can suppress all predictions. |
| `import-google-api` | Checkbox — emit the Google Maps bootstrap loader. Disable if another module already loads it. |

Prediction filters are assigned as **properties** on `PlaceAutocompleteElement` after construction, inside try/catch — a bad value degrades to unfiltered predictions instead of aborting init.

## Gotchas when changing config

- Mapping two settings to the same REDCap field silently breaks one of them: each element carries a single `googleSearch_*` id, so the later assignment wins. City + County on one field means the council area overwrites the suburb. For AU projects, leave County blank.
- REDCap caches `config.json`; a new setting that doesn't appear in the settings dialog needs the module disabled and re-enabled on the project.
- The JS is inlined into the page — always hard-refresh (Ctrl+F5) after deploying.

## Handoff

`HANDOFF.md` is the rolling handoff — read it for the current state, what was last changed and why, and what has not been browser-tested. Update it rather than adding new dated handoff files; the previous `HANDOFF-unit-number-fix.md` went stale and actively misled.
