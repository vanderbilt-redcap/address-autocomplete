# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A REDCap External Module that adds Google Maps address autocomplete to survey and data entry forms. It is a single-file PHP module (`AddressExternalModule.php`) that injects inline JavaScript and CSS into REDCap pages via hooks.

There is no build system, package manager, or test suite — changes are deployed by updating files in a REDCap instance's modules directory.

## Architecture

The module has one PHP class (`AddressExternalModule`) extending REDCap's `AbstractExternalModule`. The two hooks (`hook_survey_page`, `hook_data_entry_form`) both delegate to `addAddressAutoCompletion()`, which:

1. Reads all project settings (API key, field mappings) via `$this->getProjectSetting()`.
2. Optionally emits the Google Maps inline bootstrap loader script (when `import-google-api` is checked).
3. Emits a self-contained IIFE of JavaScript that wires up the autocomplete widget.

### JavaScript strategy (inside the PHP-emitted IIFE)

- `waitForPlacesReady()` — polls for `google.maps.importLibrary` or `google.maps.places`, resolving to `'importLibrary'` or `'legacy'`.
- `loadPlacesLibrary()` — calls `importLibrary('places')` or returns `google.maps.places` depending on the detected mode.
- `initAutocomplete()` — branches on whether `PlaceAutocompleteElement` (new API) or `Autocomplete` (legacy) is available.
- `initWithNewApi()` — uses `<gmp-place-autocomplete>` custom element; listens for `gmp-select` event; calls `place.fetchFields()` then `fillInAddress()`.
- `initWithLegacyApi()` — attaches to the original `<input>`; listens for `place_changed`; calls `fillInAddressLegacy()`.
- `fillInAddress()` / `fillInAddressLegacy()` — populate destination REDCap fields. New API uses `addressComponents[].longText/shortText`; legacy uses `address_components[].long_name/short_name`.
- `updateValue()` — REDCap-aware field setter that handles hidden radios, `<select>`, and `rc-autocomplete` dropdowns.

### Key invariants

- PHP uses a nowdoc (`<<<'SCRIPT'`) for the Google bootstrap loader to prevent PHP from interpreting JS template literals (e.g., `${c}`) as PHP variables. The API key is injected via a separate `<script>` tag that sets `window.__addressAutoKey`.
- Destination fields (street, city, etc.) are `disabled` until a place is selected, preventing manual edits and ensuring REDCap saves only autocomplete-populated values.
- A guard at the top of `$(document).ready` exits early if the autocomplete source field is not present on the current form, avoiding errors on multi-instrument projects.
- `administrative_area_level_2` (county) strips the word "County" from the value before saving.
- `doBranching()` is called after any fill/clear operation if it exists — this triggers REDCap's branching logic to re-evaluate field visibility.

## config.json settings keys

| Key | Purpose |
|-----|---------|
| `google-api-key` | Required. Google Maps API key. |
| `autocomplete` | Required. The source text field the widget attaches to. |
| `street-number`, `street`, `city`, `county`, `state`, `zip`, `country` | Optional destination fields for address components. |
| `latitude`, `longitude` | Optional destination fields for coordinates. |
| `place-name` | Optional field for the place's display name (`displayName` in new API, `name` in legacy). |
| `import-google-api` | Checkbox — emit the Google Maps bootstrap loader. Disable if another module already loads it. |
