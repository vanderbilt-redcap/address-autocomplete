# Handoff

Rolling handoff for the Address Autocomplete REDCap module. **Update this file; don't add new
dated ones.** The previous `HANDOFF-unit-number-fix.md` went stale and actively misled — it
described a stashed implementation that was never shipped.

**Last change:** two long-standing field-writing defects fixed, and the module deployed to both
REDCap module directories (2026-07-31).
**Status:** ✅ Offline verification passing. ⚠️ **Not browser-tested** — and neither was the legacy
removal below it, so the browser checklist covers both changes.

---

## What changed (latest)

The deployed copies in `d:\redcap_prod\modules\` had drifted a commit behind this repo: both were
still on `f93841b` (683 lines), missing the legacy-path removal described below. Aligning them
surfaced two bugs present in *both* the deployment and this source, so they were fixed here first
and then deployed.

**1. Latitude and longitude were never saved.** Every destination field is disabled on load, and
each write path re-enabled its own element — the component loop, `applyUnitToStreetNumber()`, the
place-name write. Latitude and longitude have no such call site: `updateValue('latitude', …)` is
called directly from `fillInAddress()` and nothing re-enabled the field afterwards. Disabled inputs
are not submitted, so REDCap received no coordinates, ever. Re-enabling is now done once, inside
`updateValue()`, which covers every present and future write path.

That also fires on the clear path (`updateValue(id, '')`), which is a **behaviour change worth
watching**: emptying the search box now genuinely clears the stored component values on save.
Previously the fields were blanked on screen while the still-disabled inputs left the old values
in the database — so the v1.2 clear-on-empty fix below never actually persisted anything.

**2. A missing `shortText` aborted the component loop.** `componentForm` maps
`administrative_area_level_2 → 'shortText'`, but Google omits `shortText` on that component for
many Australian addresses. `val.replace('County', '')` then threw a `TypeError` on `undefined`,
and because the throw escaped the `for` loop, every component after it — state, postcode, country —
went unwritten and `applyUnitFromComponents()` never ran. The lookup now falls back
`comp[componentForm[type]] || comp.shortText || comp.longText || ''`, the same idiom
`extractUnitParts()` already used.

### Deployment note

Both `modules/vanderbilt_address_autocomplete_v1.0.0/` and
`modules/curtin_address_autocomplete_v1.0.0/` were updated. They are the same code under different
namespaces, kept deliberately separate so one project can run both until this module can handle two
address sets itself. **Line 1 of each deployed file and both `config.json` identities are never to
be changed** — update lines 2-to-end only.

---

## What changed (previous: legacy path removal)

The module carried two parallel implementations: `PlaceAutocompleteElement` (Places API New) and
a fallback on the deprecated `google.maps.places.Autocomplete`. The legacy path is gone, along
with everything that existed only to serve both — `fillInAddressLegacy()`, the `formatMap`
property-name bridge, the `shortProp`/`longProp` helper parameters, and a duplicated
geolocation-bias block. **266 lines changed, 683 → 568.**

The fallback was not merely redundant. When the module landed on it, the widget rendered as an
ordinary text box with no error logged — the worst failure mode available, and one that already
cost a debugging session (documented in the old handoff). A key without Places API (New) now
gets the explicit red banner instead.

### Two defects fixed along the way

Both were consequences of the legacy design, found while scoping the removal.

**1. Location bias had never worked on the new path.** Both bias blocks built a
`new google.maps.Circle(...)` — a pattern from when a script-tag load populated the whole
`google.maps` namespace at once. `Circle` belongs to the **`maps`** library; the module only
imports `places`, so `google.maps.Circle` was `undefined` and the constructor threw inside the
`getCurrentPosition` callback, where nothing caught or logged it. Predictions have been
unbiased this whole time, silently.

`locationBias` accepts a `CircleLiteral` per the
[LocationBias typedef](https://developers.google.com/maps/documentation/javascript/reference/places-service),
so `applyGeolocationBias()` now assigns `{center: {lat, lng}, radius: accuracy}` directly. No
constructor, no second library. An error callback was added so a denied permission is logged
rather than swallowed.

**2. Clear-on-empty existed only on the legacy path.** `initWithLegacyApi()` listened for
`change` with an empty value and wiped the component fields; the new path had no equivalent.
Deleting the legacy code would have silently dropped that behaviour, so it was folded into the
existing trusted-`input` listener in `initWithNewApi()`: an emptied box calls
`fillInAddress(null, $field)`, which already runs the clear branch.

### Behaviour change to be aware of

**A key without Places API (New) now shows the error banner and a manual-entry text box instead
of falling back.** For newly issued keys this is strictly better — the legacy fallback would not
have worked anyway, it just failed invisibly. For any project still running on an old key where
legacy genuinely worked, this is a regression that must be fixed by enabling Places API (New) on
the key. Worth checking before deploying widely.

---

## Verification

**Done — offline, all passing.** A harness in the session scratchpad (**not preserved**) renders
the real emitted output under `php`, extracts the main `<script>` block, and parses/exercises it in
headless Edge. Note for whoever reads this next: **PHP is installed** at `D:\PHP\v8.5.7\php.exe`
and `php -l` passes on all three copies. Node is the tool that is missing here — see `CLAUDE.md`.
The harness asserts:

| Check | Result |
|---|---|
| Emitted JS parses via `new Function()` — all optional settings mapped | ✅ |
| Emitted JS parses — no optional settings mapped | ✅ |
| No unsubstituted `<?php` tag, no empty assignment (`var x = ;`) | ✅ |
| No residue: `initWithLegacyApi`, `fillInAddressLegacy`, `formatMap`, `short_name`, `long_name`, `address_components`, `place.geometry`, `new google.maps.Circle`, `placesLib.Autocomplete`, `waitForPlacesReady` | ✅ all absent |
| `importLibrary()` is called for `places` and nothing else | ✅ |
| Unit-recovery table (11 cases from `README.md`) against the real `recoverUnitFromText()` | ✅ |
| `extractUnitParts()` reads `shortText`/`longText`, and no longer reads `short_name`/`long_name` | ✅ |

Both emit branches matter — the PHP conditionals produce materially different JS, and a bug can
hide in the branch you didn't check.

**Not done — needs a browser.** Deploy, hard-refresh (Ctrl+F5), then confirm:

- [ ] Console shows `Using Places API (New) — PlaceAutocompleteElement`, no red errors.
- [ ] Select an address → components, lat/lng and place name populate; their fields enable.
- [ ] **Save and reload → latitude/longitude persisted.** *(the lat/lng fix — cannot be verified
      offline, and the whole point of the change)*
- [ ] **Select an AU address whose `administrative_area_level_2` has no `shortText` → no console
      `TypeError`, and state/postcode/country still fill.** *(the component-loop fix)*
- [ ] **Clear the box → all component fields go blank; save and reload → they stay blank.**
      *(new behaviour, untested — the second half is what the re-enable fix changed)*
- [ ] **Accept the location prompt → no console error, predictions skew local.** *(the fix,
      untested — this is the one most worth watching, since the old code failed silently here)*
- [ ] With `recover-unit-from-input` on, type `3/27 Harris St, Palmyra WA` → Street Number saves
      `3/27` and the search field reads `3/27 …`.
- [ ] Decline the location prompt → `Geolocation unavailable…` logged, widget still works.
- [ ] Bogus API key → red banner, input usable for manual typing.

---

## Deploying

Copy `AddressExternalModule.php` (and `config.json` when settings changed) into
`redcap/modules/<module_name>_v<version>/`. **Don't rename the directory** — the version lives
in its name. Hard-refresh the form, since the JS is inlined into the page. If a new setting
doesn't appear in the settings dialog, REDCap has cached the old `config.json`: disable and
re-enable the module on the project.

On this server there are **two** target directories, `vanderbilt_address_autocomplete_v1.0.0` and
`curtin_address_autocomplete_v1.0.0`. Update both, and preserve each one's line-1 namespace and its
`config.json` identity — copy lines 2-to-end of `AddressExternalModule.php`, never the whole file.
Afterwards, `diff --strip-trailing-cr` the two deployed files: line 1 must be the only difference.

`config.json` has been unchanged since `f93841b` — no setting was legacy-specific, and the two
deployed copies already match this repo (Curtin's differing `name`/`namespace`/`authors` are
intentional).

---

## Still-live config gotchas

Not code issues; they bite real projects.

- **Never map two settings to the same REDCap field.** Each destination element carries a single
  `googleSearch_*` id, so the later assignment wins. In *Curtin COLCOT-T2D*, City and County were
  both mapped to `prac_add_sub`, so `administrative_area_level_2` overwrote `locality` and the
  Suburb field received the council area (`City Of Melville`) instead of the suburb (`Palmyra`).
  **Fix is config-side: clear County Field.** Australian addresses have no county in the US sense.
- **`recover-unit-from-input` requires an unvalidated Street Number Field.** The value becomes
  `3/27`; integer or number validation rejects it.
- **`included-primary-types` set too narrowly can suppress predictions entirely.** Google may
  also reject an invalid type outright, which surfaces as `gmp-error`. Always type a test address
  after changing it.

---

## Housekeeping done

- `HANDOFF-unit-number-fix.md` deleted — stale, superseded by this file.
- `stash@{0}` (*"unit-number fix (3/27) + duplicate-mapping warning"*) dropped. It predated the
  shipped unit fix in `f93841b` and conflicted with it; its `warnOnDuplicateFieldMappings()`
  diagnostic was never shipped and the duplicate-mapping problem is documented above as a config
  gotcha instead.
- `README.md` and `CLAUDE.md` updated: legacy removed throughout, `v1.2` changelog entry, and a
  new invariant recorded — *only the `places` library is imported, so never reference
  `google.maps.Circle` or anything else from `maps`/`core`.*
- The two fixes above folded into the same **v1.2** changelog entry rather than given a v1.2.1 —
  v1.2 is unreleased and untagged, so nothing has shipped under it yet.
- `CLAUDE.md`'s claim that *"PHP is not installed on the dev machine"* corrected — it was false,
  and it had been used to justify skipping `php -l` entirely. Two new invariants recorded there:
  re-enabling belongs in `updateValue()`, and component property reads must tolerate a missing
  property.
