# Handoff — unit/subpremise number fix (stashed, backed out)

**Status:** ⚠️ Written, tested offline, deployed once, **broke the address widget**, backed out. Root cause **not diagnosed**.

**Where the code is:** `stash@{0}` — *not* committed, *not* on any branch.

```
stash@{0}: On master: unit-number fix (3/27) + duplicate-mapping warning
base commit: ec35c4e  ("Update .gitignore")
3 files changed, 193 insertions(+), 7 deletions(-)
```

| Command | Effect |
|---|---|
| `git stash show -p stash@{0}` | Read the diff without applying |
| `git stash apply stash@{0}` | Restore, **keep** the stash as a safety net |
| `git stash pop` | Restore and drop the stash |
| `git stash drop stash@{0}` | Discard the work permanently |

Use `apply`, not `pop`, until it's confirmed working.

---

## The problem being solved

Typing an Australian unit address such as `3/27 Harris St, Palmyra, WA` saves
`street_number = "27"` — the unit number is lost. Two independent causes:

1. **The module discards `subpremise`.** When Google *does* return a `subpremise`
   component, the fill loops key off `componentForm`, which has no `subpremise` entry, so
   it is skipped.
2. **Google often omits `subpremise` entirely.** A documented Places API limitation for
   AU/UK-style unit addresses — the prediction for `3/27 Harris St` comes back as
   `27 Harris St, Palmyra WA` with no subpremise at all. Google's guidance is that
   subpremise addresses "yield only partial predictions in Autocomplete".

**Fixing (1) alone does not fix the reported case.** Both are needed.

## What the stashed change does

| File | Change |
|---|---|
| `config.json` | One new checkbox setting, `recover-unit-from-input`, default off |
| `AddressExternalModule.php` | The logic (below) |
| `CLAUDE.md` | Docs for the new setting, helpers and invariants |

- Captures `subpremise` and `street_number` in **both** fill loops, before the
  `componentForm` check that would skip them.
- `recoverUnitFromText()` — parses the unit from the user's typed text when Google omits
  it. Gated behind the new checkbox. Anchored to the street number Google *did* return, so
  it returns `''` rather than guessing.
- `composeStreetNumber()` / `applyUnitToStreetNumber()` — writes `3/27` into the street
  number field via the existing `updateValue()` (so radios/selects/`rc-autocomplete` keep
  working), re-enables the field so REDCap saves it, and patches the leading number in the
  source field's stored address so it doesn't disagree with the components.
- Tracks raw typed text per API path. On the new API the widget's shadow root is **closed**,
  but `input` events are composed (so they reach the host) and
  `PlaceAutocompleteElement.value` is public API — no `attachShadow` patching needed.
  `event.isTrusted` filters out values the widget writes back itself.
- `warnOnDuplicateFieldMappings()` — console warning when two settings target one field
  (see "Separate config bug" below). **Diagnostic only; this is the prime suspect for the
  breakage.**

Decisions taken along the way: the unit is **prepended to the existing street-number field**
rather than given its own field (no data dictionary change needed; safe only because
`prac_add_stnbr` has no integer/number validation), and text recovery is **opt-in,
default off** so existing projects are unaffected until enabled.

---

## ⚠️ Why it was backed out

After deploying, the address search stopped working and rendered as a **plain text box**.
Backed out before diagnosing. **Get the browser console output before retrying** — it will
almost certainly identify this in seconds.

| Console shows | Meaning |
|---|---|
| Red `SyntaxError` / `Uncaught` naming the inline script | Emitted JS is broken — the whole IIFE never ran |
| `[Address Autocomplete] Using Legacy Places API` | Widget initialised on the legacy path, which renders as an ordinary input. Google stopped enabling legacy Autocomplete for newer API keys, so it would look dead |
| Nothing from `[Address Autocomplete]` | Script never reached the browser — PHP fatal, or the file didn't deploy |
| Red *"Address autocomplete could not load"* on the form | Initialisation ran, Google itself failed — API key or ad blocker, **not this change** |

### Two suspects, both already hardened in the stash

1. **`warnOnDuplicateFieldMappings()` sat in the initialisation path.** It is called inside
   `$(document).ready` *before* `.hide()` and `initAutocomplete()`. A throw there aborts
   ready() and leaves the original input visible — exactly the observed symptom. Now wrapped
   in `try { … } catch (e) {}`.
2. **`json_encode($fieldSettings)` returning `false`** (non-UTF-8 in a field name) would emit
   `var configuredFields = ;` — a syntax error killing the whole IIFE. Now falls back to
   `{}`.

Both fixes are **in the stash but were never deployed or verified.** They are reasoned
guesses, not diagnoses. It is entirely possible the cause was unrelated to this change
(API key, legacy fallback, partial deploy).

**Cheapest way to isolate it:** apply the stash, deploy *only* `config.json` +
`AddressExternalModule.php`, and if it still breaks, comment out the single
`warnOnDuplicateFieldMappings()` call. If the widget returns, the diagnostic is the culprit
and can simply be deleted — it is not part of the actual fix.

---

## Verification already done (offline)

No test suite exists, and **PHP is not installed on this machine — `php -l` was never run.**
What *was* run: the emitted `<script>` block was extracted, PHP substitution simulated, and
the JS parsed with `new Function()` — for both the all-settings-configured and
no-optional-settings branches. Then the real helpers were extracted from source and
exercised. All passed.

| Typed text | Google's street number | Expected unit |
|---|---|---|
| `3/27 Harris St, Palmyra, WA` | `27` | `3` |
| `Unit 3, 27 Harris St` | `27` | `3` |
| `unit 3 27 Harris St` | `27` | `3` |
| `Apt 3B, 27 Harris St` | `27` | `3B` |
| `Shop 12/27 Harris St` | `27` | `12` |
| `12A/27 Harris St` | `27` | `12A` |
| `27 Harris St, Palmyra WA` | `27` | *(none)* |
| `27 Harris St, Palmyra WA` | `7` | *(none)* — substring guard |
| `27-29 Harris St` | `29` | *(none)* — hyphen is a range, not a unit |
| `Harris St 27` | `27` | *(none)* — prefix is a street name |
| `The Old Rectory, 27 Harris St` | `27` | *(none)* — prefix too long |

The `27-29` case was a genuine false positive caught by this harness (it produced `27/29`);
the fix was to stop treating `-` as a unit separator. **Keep that case if the heuristic is
ever rewritten.**

The test harness lived in the session scratchpad and **has not been preserved** — it will
need rewriting. It was ~120 lines of Node: extract the `<script>` block, regex out the PHP
tags, `new Function()` to syntax-check, then brace-match the named helpers out of the
source and run the table above against them.

**Never tested in a real browser.** The shadow-DOM `input` capture and the `isTrusted`
filtering are the parts most in need of live confirmation.

---

## Separate config bug (independent of this work)

In project *Curtin COLCOT-T2D*, **City Field** and **County Field** are both mapped to
`prac_add_sub`. Each destination element carries a single `googleSearch_*` id, so the later
assignment (`administrative_area_level_2`) overwrites the earlier (`locality`). Result: the
Suburb field receives the **council area** (`City Of Melville`) instead of the suburb
(`Palmyra`).

**Fix is config-side only — clear County Field.** Australian addresses have no county in the
US sense. This needs no code change and is worth doing regardless of what happens to the
stash.

## Also flagged, deliberately not changed

`initWithNewApi()` passes `types: ['address']` to the `PlaceAutocompleteElement` constructor.
`types` is **not a valid option** for the new API — it is `includedPrimaryTypes` — so that
filter is currently a silent no-op and predictions are unfiltered. `includedRegionCodes: ['au']`
would further improve AU relevance. Both were left out because they change prediction
behaviour for every project, which deserves its own decision.

## Note on the original suggested snippet

The third-party JS that prompted this work was evaluated and **not used as-is**. Its core
idea (track raw input, prefer `subpremise`, fall back to parsing the typed prefix) was kept,
but it targeted the legacy API path only, its raw-input listener could never fire on the new
API (hidden original input + closed shadow root), it used element IDs that don't match the
module's `googleSearch_*` convention, it bypassed `updateValue()`, it left the field
`disabled` so REDCap wouldn't save it, and its heuristic had no boundary check (`27 Harris St`
with street number `7` yields a bogus unit `2`).

## Deploying

Copy `AddressExternalModule.php` and `config.json` into
`redcap/modules/<module_name>_v<version>/`, replacing what's there. Don't rename the
directory — the version lives in its name. Hard-refresh the form (Ctrl+F5), since the JS is
inlined into the page. If the new checkbox doesn't appear in module settings, REDCap has
cached the old `config.json`; disable and re-enable the module on the project.
