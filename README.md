# Address Autocomplete External Module

A REDCap External Module that attaches Google Maps address autocomplete to a text field on a
survey or data entry form. When the user picks a prediction, the module writes the individual
address components — street number, street, city/suburb, county, state, postcode, country —
plus latitude/longitude and the place name into other REDCap fields you nominate.

It is a single PHP class (`AddressExternalModule.php`) that injects inline JavaScript and CSS
into the page via two REDCap hooks. There is no build step, no package manager and no test
suite; deployment is a file copy.

---

## Contents

- [Setting up the module](#setting-up-the-module)
- [Settings reference](#settings-reference)
- [How it works](#how-it-works)
  - [PHP side](#php-side-what-gets-emitted)
  - [Loading the Google Maps API](#loading-the-google-maps-api)
  - [Building the widget](#building-the-widget)
  - [Wiring up the form](#wiring-up-the-form)
  - [Filling the fields](#filling-the-fields)
  - [Unit / apartment number recovery](#unit--apartment-number-recovery)
  - [Prediction filtering](#prediction-filtering)
  - [Error handling](#error-handling)
- [Deploying](#deploying)
- [Troubleshooting](#troubleshooting)
- [Known issues and gotchas](#known-issues-and-gotchas)
- [Changes](#changes)

---

## Setting up the module

1. **Get a Google Maps API key.** Follow
   [Google's instructions](https://developers.google.com/maps/documentation/javascript/get-api-key).
   The key **must** have the **Maps JavaScript API** and the **Places API (New)** enabled, with
   billing active on the project. REDCap must be served over **https**.

   > **Places API (New) is a hard requirement.** The module is built on
   > `PlaceAutocompleteElement` and has no fallback. A key without it shows an error banner and
   > a plain text box for manual entry — see [Troubleshooting](#troubleshooting).
2. Enable the module on the project and open its settings.
3. Set **Google API Key** and **Autocomplete Field**. Both are required — the module emits
   nothing at all unless both are present.
4. Map the destination fields you want populated (all optional).
5. Leave **Import Google API** checked unless another module on the same page already loads
   the Google Maps JavaScript API. Without it, nothing loads and the widget never appears.

### Field type requirements

| Setting | Field type it must point at |
|---|---|
| Autocomplete Field | Text field. The module hides it and stores the full formatted address in it. |
| Street Number, Street, City, County, State, Zip, Country, Place Name | Text, radio, dropdown, or `rc-autocomplete` — `updateValue()` handles all four. |
| Latitude / Longitude | Text fields. Number validation is fine. |
| Street Number **when unit recovery is on** | Must be text with **no** integer/number validation, because the value becomes `3/27`. |

---

## Settings reference

All settings are project-level (`config.json` → `project-settings`).

| Key | Name in the UI | Type | Required | Purpose |
|---|---|---|---|---|
| `google-api-key` | Google API Key | text | yes | Injected into the Google Maps bootstrap loader. |
| `autocomplete` | Autocomplete Field (text only) | field-list | yes | The source field the widget attaches to. Also receives the full formatted address. |
| `street-number` | Street Number Field | field-list | no | Google `street_number` (short form). Also the destination for a recovered unit number. |
| `street` | Street Field | field-list | no | Google `route` (long form). |
| `city` | City Field | field-list | no | Google `locality` (long form) — the suburb in AU addresses. |
| `county` | County Field | field-list | no | Google `administrative_area_level_2` (short form). The literal word "County" is stripped. |
| `state` | State Field | field-list | no | Google `administrative_area_level_1` (short form). |
| `zip` | Zip Code Field | field-list | no | Google `postal_code` (short form). |
| `country` | Country Field | field-list | no | Google `country` (long form). |
| `latitude` / `longitude` | Latitude / Longitude Field | field-list | no | Coordinates of the selected place. |
| `place-name` | Place Name Field | field-list | no | The place's `displayName` — e.g. a business name. |
| `recover-unit-from-input` | Recover unit/apartment number from typed text | checkbox | no | Off by default. See [Unit recovery](#unit--apartment-number-recovery). |
| `included-region-codes` | Restrict predictions to region codes | text | no | Comma-separated CLDR region codes, e.g. `au`. Max 15. Blank = unrestricted. |
| `included-primary-types` | Restrict predictions to place types | text | no | Comma-separated Google place types, e.g. `street_address,premise,subpremise`. Max 5. Blank = unrestricted. |
| `import-google-api` | Import Google API | checkbox | no | Emit the Google Maps bootstrap loader. Uncheck only if another module already loads it. |

### Which Google component goes where

The mapping lives in the `componentForm` object built in
[AddressExternalModule.php:89-97](AddressExternalModule.php#L89-L97). Only the components whose
setting is mapped appear in it — an unmapped setting produces no entry, so it is never read and
never cleared.

| Google component type | Setting | Format used |
|---|---|---|
| `street_number` | `street-number` | short |
| `route` | `street` | long |
| `locality` | `city` | long |
| `administrative_area_level_2` | `county` | short |
| `administrative_area_level_1` | `state` | short |
| `country` | `country` | long |
| `postal_code` | `zip` | short |
| `subpremise` | *(no setting)* | handled separately — see below |

`subpremise` is deliberately **absent** from `componentForm`. That object doubles as the registry
of "components that have a destination element", and every entry in it is cleared through
`updateValue(autocompletePrefix + type)` on each selection. Since no `googleSearch_subpremise`
element is ever created, an entry there would only log *"Could not find the element"* on every
selection. The unit is picked out by `extractUnitParts()` instead.

---

## How it works

### PHP side: what gets emitted

Both `hook_survey_page()` and `hook_data_entry_form()` delegate to
`addAddressAutoCompletion()`, which reads every project setting and — only if a key **and** an
autocomplete field are configured — echoes, in order:

1. `<script>var __addressAutoKey="…";</script>` (only when **Import Google API** is on).
2. The Google Maps inline bootstrap loader (only when **Import Google API** is on).
3. A small `<style>` block sizing the widget inside its `#locationField` wrapper.
4. One self-contained IIFE containing all the behaviour.

The PHP settings are baked into that IIFE at emit time, not read at runtime. Optional features
are compiled *out* entirely: e.g. the latitude line is emitted as
`updateValue('latitude', place.location.lat());` only if `$latitude` is set, and the unit-recovery
fallback sits inside `<?php if ($recoverUnit): ?>`. An unconfigured feature therefore costs
nothing and cannot misfire.

Two hardening details in the emitted code:

- **Nowdoc for the bootstrap.** The loader is emitted with `<<<'SCRIPT'` so PHP does not
  interpolate JavaScript template literals such as `${c}` as PHP variables. The API key cannot
  live inside a nowdoc, so it is passed in through the `__addressAutoKey` global set by the
  preceding `<script>` tag, HTML-escaped with `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`.
- **`$toJsArray()`** ([AddressExternalModule.php:37-41](AddressExternalModule.php#L37-L41)) turns
  the comma-separated filter settings into a JS array literal. It never returns an empty string —
  `json_encode()` failing (non-UTF-8 input) falls back to `[]`, because emitting `var x = ;`
  would be a syntax error that kills the entire IIFE and leaves a bare text box on the form.

### Loading the Google Maps API

The module uses Google's official **inline bootstrap loader**. It defines
`google.maps.importLibrary` synchronously and defers the actual network request until the first
`importLibrary()` call. It is safe to run when another module has already loaded the API — the
bootstrap detects this and logs a warning rather than loading twice.

`waitForImportLibrary(timeoutMs)` then polls every 150 ms (15 s default timeout) until
`google.maps.importLibrary` is a function, and `loadPlacesLibrary()` calls
`importLibrary('places')`.

**`places` is the only library the module ever imports.** Nothing in the code may reference
`google.maps.Circle`, `LatLngBounds` or anything else from the `maps` or `core` libraries — they
are undefined here, and a reference inside an async callback throws where nothing catches it.
That is precisely what used to break the location bias; see [v1.2](#changes).

A rejection here almost always means an ad blocker or corporate proxy is blocking
`googleapis.com`; the timeout message says so explicitly.

### Building the widget

`initAutocomplete()` requires `PlaceAutocompleteElement`. **There is no fallback** — if the class
is absent, the module calls `showAutocompleteError()` rather than degrading to something that
looks broken but reports nothing.

`initWithNewApi()` constructs a `<gmp-place-autocomplete>` custom element, applies the prediction
filters, and inserts it before the hidden original input. It listens for:

| Event | Handling |
|---|---|
| `gmp-select` | Convert `event.placePrediction` via `.toPlace()`, call `place.fetchFields({fields: ['addressComponents','location','formattedAddress','displayName']})`, then `fillInAddress()`. |
| `input` (trusted only) | Record the typed text for [unit recovery](#unit--apartment-number-recovery). If the box has been emptied, call `fillInAddress(null, …)` so a cleared search never leaves the previous address behind in the component fields. |
| `gmp-error` | Log it — this is what surfaces a rejected API key or an invalid filter value. |

Predictions are biased toward the user's current position by `applyGeolocationBias()`, which
assigns a `CircleLiteral` built from `navigator.geolocation.getCurrentPosition()`:

```js
placeAutocomplete.locationBias = {
    center: { lat: …, lng: … },
    radius: position.coords.accuracy
};
```

`locationBias` accepts a `CircleLiteral` directly, so no `google.maps.Circle` is constructed and
no second library is needed. A denied or unavailable geolocation is logged and ignored —
predictions are simply unbiased.

### Wiring up the form

Inside `$(document).ready`:

1. **Guard.** If `[name="<autocomplete field>"]` is not on the page, return immediately. This is
   what keeps the module silent on the other instruments of a multi-instrument project.
2. **Tag and lock the destinations.** Each mapped destination field gets
   `id="googleSearch_<google_component_type>"` and `prop('disabled', true)`. The `googleSearch_`
   prefix plus the Google type name *is* the lookup convention the whole script uses.
3. **Hide the source field.** It is wrapped in `<div id="locationField">` and hidden, so the
   Google widget can take its visual place while the original field still submits with the form.

Destination fields start **disabled** on purpose: it prevents manual edits, and it means REDCap
saves a component value only once autocomplete has actually written one. Each field is re-enabled
individually, at the moment it receives a value.

### Filling the fields

`fillInAddress()` reads `place.addressComponents` (values via `shortText` / `longText`),
`place.location.lat()` / `.lng()` and `place.displayName`. The `componentForm` values *are* those
property names, so the lookup is a single step: `comp[componentForm[addressType]]`.

Sequence on every selection:

1. Clear every field in `componentForm` — a new selection never leaves stale parts of the previous
   address behind.
2. Write the full formatted address into the hidden source field and fire `.change()`.
3. Write latitude/longitude if mapped.
4. Loop the components: skip any without both a `componentForm` entry and a matching DOM element;
   strip the word `County` from `administrative_area_level_2`; write via `updateValue()`; re-enable
   the element.
5. Apply the unit/sub-premise **after** that loop, so it overwrites the bare street number the loop
   just wrote.
6. Write the place name if mapped.
7. Call `doBranching()` if it exists, so REDCap re-evaluates branching logic against the new values.

If no place is selected — including when the user empties the search box, which calls
`fillInAddress(null, …)` — the else branch runs instead: blank the source field, lat/lng and place
name, then `doBranching()`.

**`updateValue(id, value)`** is the REDCap-aware setter and the reason this module works with
non-text fields. Given an element id (or the literal `'latitude'`/`'longitude'`, which are looked
up by name instead) it:

- sets `.val(value)` and fires `.change()`;
- for `.hiddenradio` fields, checks the matching `<name>___radio` input;
- for `<select>`, tries an exact option value, then the value with spaces replaced by underscores,
  then an `Other` option, then a single option whose text matches — and if none of those match,
  alerts the user and resets to blank;
- for `.rc-autocomplete`, syncs the visible jQuery UI input with the selected option's text.

If the element is not found it logs *"Could not find the element with the following id"* and
returns — which is why `componentForm` must not contain types with no destination element.

### Unit / apartment number recovery

Typing an Australian unit address such as `3/27 Harris St, Palmyra WA` normally saves
`street_number = "27"`; the unit is lost. There are two independent causes, and both are handled:

**1. Google returned a `subpremise` but the module ignored it.** `extractUnitParts(components)`
walks the raw component list independently of `componentForm` and pulls out the first
`subpremise` and the first `street_number`. Always on; needs no setting.

**2. Google omitted `subpremise` entirely.** A documented Places API limitation — subpremise
addresses "yield only partial predictions in Autocomplete". This is what
**`recover-unit-from-input`** (off by default) addresses. When the checkbox is on and no
`subpremise` came back, `recoverUnitFromText(typed, streetNumber)` parses the unit out of what the
user actually typed:

- The street number Google *did* return is located in the typed text on a word boundary, so a
  street number of `7` is not matched inside `27`.
- Only the text *before* that number is considered, and only if it is ≤ 24 characters — longer
  prefixes are building or place names.
- A trailing hyphen/dash rejects the prefix, because `27-29 Harris St` is a street number range,
  not a unit.
- The prefix must **end** with a unit token, optionally introduced by `unit`, `apt`, `apartment`,
  `flat`, `suite`, `ste`, `shop`, `villa`, `lot`, `level`, `lvl`, `room`, `rm`. That anchoring is
  what rejects `Harris St 27`.
- Anything that does not clearly contain a unit returns `''` rather than guessing.

The raw typed text is tracked in `lastTypedText`. The widget's shadow root is **closed**, but
`input` events are composed (so they cross it and retarget to the host) and
`PlaceAutocompleteElement.value` is public API — no shadow-DOM patching is needed. `e.isTrusted`
filters out the value the widget writes back itself once a prediction is chosen. `lastTypedText`
is cleared after each selection so a later selection cannot reuse it.

Whichever way the unit was obtained, `applyUnitFromComponents()` then:

- does nothing unless a **Street Number Field** is mapped — it is the only destination for the unit;
- writes `3/27` through `updateValue()`, so radios, selects and `rc-autocomplete` keep working;
- re-enables the field, because disabled inputs are not submitted and REDCap would never save it;
- calls `patchFormattedAddress()` to rewrite a leading bare `27` in the source field to `3/27`, so
  the stored full address does not disagree with the components. It no-ops when Google supplied the
  subpremise, since `formattedAddress` already contains the unit in that case.

Behaviour verified against this table (offline harness, not a browser):

| Typed text | Google's street number | Result |
|---|---|---|
| `3/27 Harris St, Palmyra, WA` | `27` | `3` |
| `Unit 3, 27 Harris St` | `27` | `3` |
| `unit 3 27 Harris St` | `27` | `3` |
| `Apt 3B, 27 Harris St` | `27` | `3B` |
| `Shop 12/27 Harris St` | `27` | `12` |
| `12A/27 Harris St` | `27` | `12A` |
| `27 Harris St, Palmyra WA` | `27` | *(none)* |
| `27 Harris St, Palmyra WA` | `7` | *(none)* — substring guard |
| `27-29 Harris St` | `29` | *(none)* — hyphen is a range |
| `Harris St 27` | `27` | *(none)* — prefix is a street name |
| `The Old Rectory, 27 Harris St` | `27` | *(none)* — prefix too long |

Keep the `27-29` case if the heuristic is ever rewritten; it was a genuine false positive caught by
that harness.

### Prediction filtering

`included-region-codes` and `included-primary-types` are assigned as **properties** on the
`PlaceAutocompleteElement` after construction, inside a `try/catch`:

```js
if (regionCodes.length)  { placeAutocomplete.includedRegionCodes  = regionCodes; }
if (primaryTypes.length) { placeAutocomplete.includedPrimaryTypes = primaryTypes; }
```

A bad setting value therefore degrades to unfiltered predictions instead of aborting
initialisation and leaving a plain text box on the form.

Setting `included-primary-types` too narrowly can suppress predictions entirely, and Google may
reject an invalid type outright — which shows up as a `gmp-error` in the console. Always type a
test address after changing it.

### Error handling

`showAutocompleteError()` runs when the library never loads or `PlaceAutocompleteElement` is
absent. It un-hides the original input, sets the placeholder to *"Address autocomplete
unavailable — type manually"*, and prepends a red notice to `#locationField` telling the user to
allow `googleapis.com` and reload. Data entry is never blocked — the user can still type the
address by hand, though the component fields stay disabled and empty.

---

## Deploying

Copy `AddressExternalModule.php` and `config.json` into
`redcap/modules/<module_name>_v<version>/`, replacing what is there. **Do not rename the
directory** — REDCap reads the version from its name.

Then hard-refresh the form (Ctrl+F5): the JavaScript is inlined into the page, so a normal
refresh can serve a cached copy. If a newly added setting does not appear in the module settings
dialog, REDCap has cached the old `config.json` — disable and re-enable the module on the project.

---

## Troubleshooting

Open the browser console and filter on `[Address Autocomplete]`.

| Console shows | Meaning |
|---|---|
| `Using Places API (New) — PlaceAutocompleteElement` | Normal, expected path. |
| `PlaceAutocompleteElement is not available…` + red banner | The API key does not have **Places API (New)** enabled, or billing is off. There is no legacy fallback — enable it on the key. |
| Red *"Address autocomplete could not load"* on the form | Google itself failed — API key, billing, or an ad blocker. Not a module bug. |
| `Google rejected the request…` (`gmp-error`) | Bad API key, or an invalid `included-region-codes` / `included-primary-types` value. |
| `Geolocation unavailable; predictions will not be location-biased.` | The user declined the location prompt, or the browser could not resolve a position. Harmless. |
| `Could not find the element with the following id: googleSearch_x` | A `componentForm` entry has no destination element. Check the field mapping. |
| Nothing at all from `[Address Autocomplete]` | The script never reached the browser: the source field is not on this instrument, both required settings are not set, a PHP fatal occurred, or the file did not deploy. |
| A red `SyntaxError` naming the inline script | The emitted JS is broken; the whole IIFE never ran. |

**PHP is not installed on the development machine, so `php -l` has never been run against this
file.** Emitted-JS changes are validated with a throwaway Node harness that extracts the
`<script>` block, simulates the PHP substitution for both emit branches (all optional settings
mapped, and none), parses the result with `new Function()`, and re-runs the unit-recovery table
above against the real helpers.

---

## Known issues and gotchas

- **Don't map two settings to the same REDCap field.** Each destination element carries a single
  `googleSearch_*` id, so the later assignment wins. Mapping both **City** and **County** to one
  field means `administrative_area_level_2` overwrites `locality`, and the field ends up with the
  council area (`City Of Melville`) instead of the suburb (`Palmyra`). For Australian projects,
  leave **County Field** blank — there is no county in the US sense.
- **Unit recovery requires an unvalidated Street Number Field.** The value becomes `3/27`; integer
  or number validation on that field will reject it.
- **The unit shares the street number field.** It is prepended rather than given its own field, so
  no data dictionary change is needed.
- **The source field is hidden, not removed.** It still submits, and it holds the full formatted
  address.
- **Never add `subpremise` to `componentForm`** — see [the mapping table](#which-google-component-goes-where).
- **Geolocation bias prompts for location permission.** Declining it is harmless; predictions are
  just less locally relevant.
- **Don't reference anything outside the `places` library.** It is the only one imported, so
  `google.maps.Circle` and everything else in `maps`/`core` is `undefined` at runtime.

---

## Changes

- **v1.2** — Removed the legacy `google.maps.places.Autocomplete` path entirely; the module is now
  Places API (New) only, and a key without it shows the error banner instead of silently degrading
  to a text box that looks broken. Fixed location bias, which had never worked on the new path —
  it built a `google.maps.Circle` from the un-imported `maps` library and threw silently inside
  the geolocation callback; it now assigns a `CircleLiteral`. Clearing the search box again wipes
  the component fields, behaviour that previously existed only on the removed legacy path.
- **v1.1** — New Places API (`PlaceAutocompleteElement`) preferred, with legacy
  `google.maps.places.Autocomplete` fallback; inline bootstrap loader for the Maps API;
  multi-instrument guard; Place Name field; unit/sub-premise capture plus opt-in recovery from
  typed text (`recover-unit-from-input`); prediction filtering by region code and primary type;
  user-visible error banner when Google cannot load.
- **v1.0** — Initial release.
