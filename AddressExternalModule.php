<?php namespace Vanderbilt\AddressExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class AddressExternalModule extends AbstractExternalModule
{
	function hook_survey_page($project_id, $record, $instrument, $event_id, $group_id) {
		$this->addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id);
	}

	function hook_data_entry_form($project_id, $record, $instrument, $event_id, $group_id) {
		$this->addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id);
	}

	function addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id) {
		$key = $this->getProjectSetting('google-api-key',$project_id);
		$autocomplete = $this->getProjectSetting('autocomplete',$project_id);
		$streetNumber = $this->getProjectSetting('street-number',$project_id);
		$street = $this->getProjectSetting('street',$project_id);
		$city = $this->getProjectSetting('city',$project_id);
		$county = $this->getProjectSetting('county',$project_id);
		$state = $this->getProjectSetting('state',$project_id);
		$zip = $this->getProjectSetting('zip',$project_id);
		$country = $this->getProjectSetting('country',$project_id);
		$latitude = $this->getProjectSetting('latitude',$project_id);
		$longitude = $this->getProjectSetting('longitude',$project_id);
		$placeName = $this->getProjectSetting('place-name',$project_id);
		$recoverUnit = $this->getProjectSetting('recover-unit-from-input',$project_id);
		$regionCodes = $this->getProjectSetting('included-region-codes',$project_id);
		$primaryTypes = $this->getProjectSetting('included-primary-types',$project_id);
		$import = $this->getProjectSetting('import-google-api',$project_id);

		// Turn a comma-separated setting into a JS array literal. Never returns an
		// empty string: emitting "var x = ;" would be a syntax error that kills the
		// whole inline script, so json_encode() failure falls back to an empty array.
		$toJsArray = function($csv) {
			$parts = array_values(array_filter(array_map('trim', explode(',', (string)$csv)), 'strlen'));
			$json = json_encode($parts);
			return ($json === false) ? '[]' : $json;
		};

		if ($key && $autocomplete) {

			// Load Google Maps API using the official inline bootstrap.
			// This defines google.maps.importLibrary immediately (synchronously)
			// and defers the actual network load until importLibrary() is called.
			// It is safe even if another module has already loaded the API.
			if ($import) {
				$escapedKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
				// Use nowdoc (<<<'SCRIPT') so PHP does NOT interpolate JS template
				// literals like ${c} as PHP variables. The API key is injected via
				// a preceding <script> that sets a global.
				echo '<script>var __addressAutoKey="' . $escapedKey . '";</script>';
				echo <<<'SCRIPT'
<script>
(g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({key:__addressAutoKey,v:"weekly"});
</script>
SCRIPT;
			}

			?>
			<style>
				#locationField { position: relative; }
				#locationField gmp-place-autocomplete {
					width: 100%;
					font-size: 13px;
				}
			</style>

			<script>
			(function() {
				var autocompletePrefix = 'googleSearch_';
				var autocompleteFieldName = <?php echo json_encode($autocomplete); ?>;

				// Raw text the user typed into the search box, kept so the unit /
				// apartment number can be recovered when Google omits it. See
				// recoverUnitFromText().
				var lastTypedText = '';

				// Component mapping: Google address type -> format preference
				//
				// Do NOT add subpremise here. This object doubles as the registry of
				// "which components have a destination field", and every entry is
				// cleared through updateValue(autocompletePrefix + type) on each
				// selection. No googleSearch_subpremise element is ever created, so an
				// entry here would only log "Could not find the element" every time.
				// The unit is captured by extractUnitParts() instead.
				// The keys are Google address component TYPE names; the values are the
				// Place API property to read off the component.
				var componentForm = {
					<?php echo ($streetNumber ? "street_number: 'shortText'," : ""); ?>
					<?php echo ($street ? "route: 'longText'," : ""); ?>
					<?php echo ($city ? "locality: 'longText'," : ""); ?>
					<?php echo ($county ? "administrative_area_level_2: 'shortText'," : ""); ?>
					<?php echo ($state ? "administrative_area_level_1: 'shortText'," : ""); ?>
					<?php echo ($country ? "country: 'longText'," : ""); ?>
					<?php echo ($zip ? "postal_code: 'shortText'," : ""); ?>
				};

				$(document).ready(function() {
					// FIX #1: Guard — only proceed if the autocomplete target field
					// exists on this particular instrument / form page.
					var $autocompleteField = $('[name="' + autocompleteFieldName + '"]');
					if ($autocompleteField.length === 0) {
						return; // Field not on this form; do nothing.
					}

					// Set up component destination fields: assign IDs and disable them
					<?php if ($streetNumber): ?>
						$('[name="<?php echo $streetNumber; ?>"]').attr('id', autocompletePrefix + 'street_number').prop('disabled', true);
					<?php endif; ?>
					<?php if ($street): ?>
						$('[name="<?php echo $street; ?>"]').attr('id', autocompletePrefix + 'route').prop('disabled', true);
					<?php endif; ?>
					<?php if ($city): ?>
						$('[name="<?php echo $city; ?>"]').attr('id', autocompletePrefix + 'locality').prop('disabled', true);
					<?php endif; ?>
					<?php if ($county): ?>
						$('[name="<?php echo $county; ?>"]').attr('id', autocompletePrefix + 'administrative_area_level_2').prop('disabled', true);
					<?php endif; ?>
					<?php if ($state): ?>
						$('[name="<?php echo $state; ?>"]').attr('id', autocompletePrefix + 'administrative_area_level_1').prop('disabled', true);
					<?php endif; ?>
					<?php if ($zip): ?>
						$('[name="<?php echo $zip; ?>"]').attr('id', autocompletePrefix + 'postal_code').prop('disabled', true);
					<?php endif; ?>
					<?php if ($country): ?>
						$('[name="<?php echo $country; ?>"]').attr('id', autocompletePrefix + 'country').prop('disabled', true);
					<?php endif; ?>
					<?php if ($latitude): ?>
						$('[name="<?php echo $latitude; ?>"]').prop('disabled', true);
					<?php endif; ?>
					<?php if ($longitude): ?>
						$('[name="<?php echo $longitude; ?>"]').prop('disabled', true);
					<?php endif; ?>
					<?php if ($placeName): ?>
						$('[name="<?php echo $placeName; ?>"]').attr('id', autocompletePrefix + 'place_name').prop('disabled', true);
					<?php endif; ?>

					// Wrap original field and hide it; the PlaceAutocompleteElement will replace it visually
					$autocompleteField.wrap('<div id="locationField"></div>');
					$autocompleteField.hide();

					// Initialize the autocomplete once the Google Maps API is available
					initAutocomplete($autocompleteField);
				});

				/**
				 * Polls until google.maps.importLibrary exists, rejecting after the
				 * timeout (default 15 s).
				 *
				 * The bootstrap loader above defines importLibrary synchronously, so this
				 * resolves immediately when "Import Google API" is enabled. The polling is
				 * for the other case: another module supplies the API, possibly after
				 * $(document).ready has already run.
				 */
				function waitForImportLibrary(timeoutMs) {
					timeoutMs = timeoutMs || 15000;
					return new Promise(function(resolve, reject) {
						function ready() {
							return typeof google !== 'undefined' && google.maps &&
							       typeof google.maps.importLibrary === 'function';
						}
						if (ready()) { resolve(); return; }

						var elapsed = 0;
						var interval = 150;
						var poll = setInterval(function() {
							elapsed += interval;
							if (ready()) {
								clearInterval(poll);
								resolve();
							} else if (elapsed >= timeoutMs) {
								clearInterval(poll);
								reject(new Error(
									'Google Maps did not become available within ' +
									(timeoutMs / 1000) + 's. A browser extension (ad blocker) ' +
									'may be blocking requests to googleapis.com.'
								));
							}
						}, interval);
					});
				}

				/**
				 * Load the Places library. This is the only Google library the module
				 * imports — see initWithNewApi() and applyGeolocationBias(), which are
				 * deliberately written to need nothing from `maps` or `core`.
				 */
				function loadPlacesLibrary() {
					return waitForImportLibrary().then(function() {
						return google.maps.importLibrary('places');
					});
				}

				/**
				 * Initialise autocomplete on the given field using PlaceAutocompleteElement
				 * (Places API New). If that class is absent the API key almost certainly
				 * does not have Places API (New) enabled — show the error rather than
				 * degrading to something that looks broken but reports nothing.
				 */
				function initAutocomplete($field) {
					loadPlacesLibrary()
						.then(function(placesLib) {
							if (typeof placesLib.PlaceAutocompleteElement !== 'function') {
								showAutocompleteError($field,
									'PlaceAutocompleteElement is not available. Check that ' +
									'Places API (New) is enabled for this API key.'
								);
								return;
							}
							console.log('[Address Autocomplete] Using Places API (New) — PlaceAutocompleteElement');
							initWithNewApi(placesLib.PlaceAutocompleteElement, $field);
						})
						.catch(function(err) {
							console.error('[Address Autocomplete] Failed to initialise.', err);
							showAutocompleteError($field, err.message || 'Could not load Google Maps.');
						});
				}

				/**
				 * Show a user-visible warning on the form when autocomplete cannot load.
				 */
				function showAutocompleteError($field, detail) {
					$field.show(); // un-hide the original text input so the user can still type
					$field.attr('placeholder', 'Address autocomplete unavailable — type manually');
					$field.closest('#locationField').prepend(
						'<div style="color:#c00;font-size:12px;margin-bottom:4px;">' +
						'&#9888; Address autocomplete could not load. ' +
						'If you have an ad blocker, please allow <b>googleapis.com</b> and reload. ' +
						'You can still type the address manually.' +
						'</div>'
					);
					console.warn('[Address Autocomplete] ' + detail);
				}

				/**
				 * Build the PlaceAutocompleteElement and wire up its events.
				 */
				function initWithNewApi(PlaceAutocompleteElement, $field) {
					// The prediction filters are assigned as properties inside try/catch so
					// that a bad setting value degrades to unfiltered predictions instead of
					// aborting initialisation and leaving a plain text input on the form.
					var placeAutocomplete = new PlaceAutocompleteElement();
					try {
						var regionCodes  = <?php echo $toJsArray($regionCodes); ?>;
						var primaryTypes = <?php echo $toJsArray($primaryTypes); ?>;
						if (regionCodes.length)  { placeAutocomplete.includedRegionCodes  = regionCodes; }
						if (primaryTypes.length) { placeAutocomplete.includedPrimaryTypes = primaryTypes; }
					} catch (e) {
						console.warn('[Address Autocomplete] Could not apply prediction filters; predictions will be unfiltered.', e);
					}

					placeAutocomplete.id = autocompletePrefix + 'autocomplete';
					placeAutocomplete.setAttribute('placeholder', 'Enter your address here');

					// Surface backend rejections (bad API key, invalid filter value)
					placeAutocomplete.addEventListener('gmp-error', function(e) {
						console.error('[Address Autocomplete] Google rejected the request. Check the API key and any prediction filter values.', e);
					});

					// Insert the new element into the wrapper, before the hidden original field
					$field.before(placeAutocomplete);

					// Record what the user actually types, for unit recovery.
					// The widget's shadow root is closed, but `input` events are composed
					// so they cross it and retarget to the host element, and `value` is a
					// documented public property. isTrusted filters out the value the
					// widget writes back itself once a prediction is chosen.
					placeAutocomplete.addEventListener('input', function(e) {
						if (!e.isTrusted) { return; }
						var typed = placeAutocomplete.value || '';
						lastTypedText = typed;
						// Emptying the box clears every destination field, so a cleared
						// search can never leave the previous address behind.
						if (typed === '') { fillInAddress(null, $field); }
					});

					// Apply geolocation bias to improve relevance
					applyGeolocationBias(placeAutocomplete);

					// The modern event is "gmp-select"; the event carries a placePrediction
					// which must be converted to a Place via .toPlace(), then fetched.
					placeAutocomplete.addEventListener('gmp-select', async function(event) {
						var place = null;
						try {
							var prediction = event.placePrediction;
							if (prediction && typeof prediction.toPlace === 'function') {
								place = prediction.toPlace();
							} else if (event.place) {
								place = event.place;
							}

							if (place) {
								await place.fetchFields({
									fields: ['addressComponents', 'location', 'formattedAddress', 'displayName']
								});
							}
						} catch (e) {
							console.warn('[Address Autocomplete] Could not process place.', e);
							place = null;
						}
						fillInAddress(place, $field);
					});
				}

				/**
				 * Bias the autocomplete results toward the user's current location.
				 *
				 * locationBias accepts a CircleLiteral ({center, radius}) directly, so no
				 * google.maps.Circle is constructed. That matters: Circle belongs to the
				 * `maps` library, which this module never imports, so referencing it here
				 * would throw inside the geolocation callback where nothing catches it.
				 */
				function applyGeolocationBias(placeAutocomplete) {
					if (!navigator.geolocation) { return; }
					navigator.geolocation.getCurrentPosition(function(position) {
						placeAutocomplete.locationBias = {
							center: {
								lat: position.coords.latitude,
								lng: position.coords.longitude
							},
							radius: position.coords.accuracy
						};
					}, function(err) {
						console.log('[Address Autocomplete] Geolocation unavailable; predictions will not be location-biased.', err);
					});
				}

				/**
				 * Helper: update a REDCap field value, handling radios, selects,
				 * and rc-autocomplete dropdowns.
				 */
				function updateValue(id, value) {
					if (id == 'latitude') {
						var element = $('[name="<?php echo $latitude; ?>"]');
					}
					else if (id == 'longitude') {
						var element = $('[name="<?php echo $longitude; ?>"]');
					}
					else {
						var element = $('#' + id);
					}

					if (element.length === 0) {
						console.log('Could not find the element with the following id:', id);
						return;
					}

					var eleType = element.prop('type');
					element.val(value);

					// Handle special REDCap field types
					var eleName = element.attr('name');
					if (element.hasClass('hiddenradio')) {
						$('input[name="'+eleName+'___radio"][value="'+value+'"]').prop('checked', true);
					} else if (eleType.indexOf("select") >= 0) {
						if ($('#'+id+' option[value="'+value+'"]').length > 0) {
							$('#'+id+' option[value="'+value+'"]').prop('selected', true);
						} else {
							var valUnderscore = value.replace(/\s+/g,"_");
							if ($('#'+id+' option[value="'+valUnderscore+'"]').length > 0) {
								$('#'+id+' option[value="'+valUnderscore+'"]').prop('selected', true);
							} else if ($('#'+id+' option[value="Other"]').length > 0) {
								$('#'+id+' option[value="Other"]').prop('selected', true);
							} else {
								var optionsWithMatchingContent = $('#'+id+' option').filter(function(){
									return $(this).html() === value;
								});

								if (optionsWithMatchingContent.length === 1) {
									optionsWithMatchingContent.prop('selected', true);
								} else {
									alert("The value '" + value + "' is not a valid value for the '" + eleName + "' field.");
									$('#'+id+' option[value=""]').prop('selected', true);
								}
							}
						}
					}

					// Disabled inputs are not submitted, so anything that writes must also
					// make its element submittable. The component loop, applyUnitToStreetNumber()
					// and the place-name write already do this at their call sites; latitude and
					// longitude have no call site of their own, which is why they were never
					// saved. Re-enabling here covers every write path at once.
					//
					// This deliberately also fires on the clear path (updateValue(id, '')), so
					// emptying the search box really does clear the stored values instead of
					// blanking them in the DOM while the disabled field keeps the old value in
					// the database. Fields still start disabled on page load: nothing calls
					// updateValue() until the user selects or clears an address.
					element.prop('disabled', false);

					element.change();

					if (element.hasClass('rc-autocomplete')) {
						var autocompleteField = element.closest('td').find('.ui-autocomplete-input');
						autocompleteField.val(element.find('option:selected').text());
						autocompleteField.change();
					}
				}

				/**
				 * Pick the unit (subpremise) and street number out of the raw component
				 * list, independently of componentForm — which has no subpremise entry
				 * and would otherwise skip it.
				 */
				function extractUnitParts(components) {
					var parts = { unit: '', streetNumber: '' };
					if (!components || !components.length) { return parts; }
					for (var i = 0; i < components.length; i++) {
						var comp = components[i];
						if (!comp || !comp.types) { continue; }
						var type = comp.types[0];
						var val  = comp.shortText || comp.longText || '';
						if (type === 'subpremise' && !parts.unit) {
							parts.unit = String(val).trim();
						} else if (type === 'street_number' && !parts.streetNumber) {
							parts.streetNumber = String(val).trim();
						}
					}
					return parts;
				}

				function escapeRegExp(str) {
					return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
				}

				/**
				 * Recover the unit / apartment number from the text the user typed.
				 *
				 * Google frequently omits the subpremise component for AU/UK style unit
				 * addresses: "3/27 Harris St" comes back as street number 27 with no
				 * subpremise at all. This parses the unit out of the typed text, anchored
				 * to the street number Google DID return, and returns '' rather than
				 * guessing whenever the text does not clearly contain a unit.
				 */
				function recoverUnitFromText(typed, streetNumber) {
					if (!typed || !streetNumber) { return ''; }
					var text = String(typed).trim();
					var sn   = String(streetNumber).trim();
					if (!text || !sn) { return ''; }

					// Locate the street number on a word boundary, so a street number of
					// "7" is not matched inside "27".
					var snMatch = new RegExp('(^|[^0-9A-Za-z])' + escapeRegExp(sn) + '(?![0-9A-Za-z])').exec(text);
					if (!snMatch) { return ''; }

					var snIndex = snMatch.index + snMatch[1].length;
					if (snIndex <= 0) { return ''; }   // nothing precedes it, so no unit

					var prefix = text.slice(0, snIndex);

					// "27-29 Harris St" is a street number range, not a unit.
					if (/[-–—]\s*$/.test(prefix)) { return ''; }

					// A unit prefix is short; anything longer is a building or place name.
					if (prefix.replace(/\s+/g, ' ').trim().length > 24) { return ''; }

					// The prefix must END with a unit token, optionally introduced by a
					// unit word and optionally followed by "/" or ",". That anchoring is
					// what rejects "Harris St 27" and "The Old Rectory, 27 Harris St".
					var unitMatch = /(?:^|[\s,])(?:(?:unit|apt|apartment|flat|suite|ste|shop|villa|lot|level|lvl|room|rm)\.?\s*)?([0-9]{1,5}[A-Za-z]?)\s*[\/,]?\s*$/i.exec(prefix);
					return unitMatch ? unitMatch[1].toUpperCase() : '';
				}

				/**
				 * Write "3/27" into the Street Number field.
				 *
				 * Goes through updateValue() so radios, selects and rc-autocomplete
				 * dropdowns keep working, then re-enables the field — disabled inputs are
				 * not submitted, so REDCap would otherwise never save the value.
				 */
				function applyUnitToStreetNumber(unit, streetNumber) {
					var id = autocompletePrefix + 'street_number';
					var el = document.getElementById(id);
					if (!el || !unit || !streetNumber) { return; }
					updateValue(id, unit + '/' + streetNumber);
					el.disabled = false;
				}

				/**
				 * Keep the full address stored in the search field consistent with the
				 * components, by rewriting a leading bare street number to "3/27".
				 * No-ops when Google supplied the subpremise, because formattedAddress
				 * already contains the unit in that case.
				 */
				function patchFormattedAddress($field, unit, streetNumber) {
					var current = $field.val();
					if (!current || !unit || !streetNumber) { return; }
					var leading = new RegExp('^\\s*' + escapeRegExp(streetNumber) + '(?![0-9A-Za-z])');
					if (leading.test(current)) {
						$field.val(current.replace(leading, unit + '/' + streetNumber));
						$field.change();
					}
				}

				/**
				 * Apply the unit / sub-premise to the Street Number field. Called after the
				 * components have been written, so that it overwrites the bare street
				 * number they just stored.
				 *
				 * Does nothing unless a Street Number Field is mapped — that field is the
				 * only destination for the unit.
				 */
				function applyUnitFromComponents(components, $field) {
					var parts = extractUnitParts(components);
					var unit  = parts.unit;
					<?php if ($recoverUnit): ?>
					// Google omitted subpremise — fall back to parsing the typed text.
					if (!unit) { unit = recoverUnitFromText(lastTypedText, parts.streetNumber); }
					<?php endif; ?>
					lastTypedText = '';   // consume, so a later selection cannot reuse it

					if (!unit || !parts.streetNumber) { return; }
					if (!document.getElementById(autocompletePrefix + 'street_number')) { return; }
					applyUnitToStreetNumber(unit, parts.streetNumber);
					patchFormattedAddress($field, unit, parts.streetNumber);
				}

				/**
				 * Populate (or clear) all address component fields from the selected Place.
				 * Uses the NEW Places API property names: addressComponents[].longText / shortText.
				 */
				function fillInAddress(place, $field) {
					// Clear all component fields first
					for (var component in componentForm) {
						updateValue(autocompletePrefix + component, '');
					}

					if (place && place.addressComponents && place.addressComponents.length > 0) {
						// Write the full formatted address into the hidden original REDCap field
						$field.val(place.formattedAddress || '');
						$field.change();

						// Latitude & Longitude
						if (place.location) {
							<?php echo ($latitude  ? "updateValue('latitude',  place.location.lat());\n" : ""); ?>
							<?php echo ($longitude ? "updateValue('longitude', place.location.lng());\n" : ""); ?>
						}

						// Map each address component into the configured REDCap fields
						for (var i = 0; i < place.addressComponents.length; i++) {
							var comp = place.addressComponents[i];
							var addressType = comp.types[0];
							if (componentForm[addressType] && document.getElementById(autocompletePrefix + addressType)) {
								// Preferred property first ('shortText' or 'longText'), then the
								// other one. Google omits shortText on some components — notably
								// administrative_area_level_2 in AU — and the replace() below
								// would throw on undefined, aborting the rest of this loop.
								var val = comp[componentForm[addressType]] || comp.shortText || comp.longText || '';
								if (addressType === 'administrative_area_level_2') {
									val = $.trim(val.replace('County', ''));
								}
								updateValue(autocompletePrefix + addressType, val);
								document.getElementById(autocompletePrefix + addressType).disabled = false;
							}
						}

						// Unit / sub-premise. Runs after the loop above so it overwrites
						// the bare street number that loop just wrote.
						applyUnitFromComponents(place.addressComponents, $field);
						<?php echo ($placeName ? "
						if (place.displayName) {
							updateValue(autocompletePrefix + 'place_name', place.displayName);
							document.getElementById(autocompletePrefix + 'place_name').disabled = false;
						}" : ""); ?>
					} else {
						// No place selected — clear the original field and lat/lng
						$field.val('');
						$field.change();
						<?php echo ($latitude  ? "updateValue('latitude',  '');\n" : ""); ?>
						<?php echo ($longitude ? "updateValue('longitude', '');\n" : ""); ?>
						<?php echo ($placeName ? "updateValue(autocompletePrefix + 'place_name', '');\n" : ""); ?>
					}

					if (typeof doBranching === 'function') { doBranching(); }
				}
			})();
			</script>
			<?php
		}
	}
}
