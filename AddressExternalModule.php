<?php namespace Vanderbilt\AddressExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class AddressExternalModule extends AbstractExternalModule
{
    function hook_every_page_top($project_id) {
		$validPages = array("DataEntry/index.php", "surveys/index.php");
		if ($project_id && (in_array(PAGE, $validPages))) {
			\REDCap::allowProjects(array($project_id));

			# get the specifications
			$module_data = ExternalModules::getProjectSettingsAsArray(array("vanderbilt_address_autocomplete"), $project_id);

			$key = $module_data['google-api-key']['value'];
			$autocomplete = $module_data['autocomplete']['value'];
			$streetNumber = $module_data['street-number']['value'];
			$street = $module_data['street']['value'];
			$city = $module_data['city']['value'];
			$county = $module_data['county']['value'];
			$state = $module_data['state']['value'];
			$zip = $module_data['zip']['value'];
			$country = $module_data['country']['value'];
			$import = $module_data['import-google-api']['value'];
			


			if ($key && $autocomplete) {
				# configure the form; disable fields; set IDs
				?>
					<script>
						var autocompletePrefix = 'googleSearch_';
						var autocompleteId = autocompletePrefix+'autocomplete';
						
						$(document).ready(function() {
							<?php $numFields = 0; ?>
							<?php if ($streetNumber): ?>
								$('[name="<?php echo $streetNumber; ?>"]').attr('id', autocompletePrefix+'street_number');
								$('[name="<?php echo $streetNumber; ?>"]').prop('disabled', true);
								<?php $numFields++; ?>
							<?php endif; ?>
							<?php if ($street): ?>
								$('[name="<?php echo $street; ?>"]').attr('id', autocompletePrefix+'route');
								$('[name="<?php echo $street; ?>"]').prop('disabled', true);
								<?php $numFields++; ?>
							<?php endif; ?>
							<?php if ($city): ?>
								$('[name="<?php echo $city; ?>"]').attr('id', autocompletePrefix+'locality');
								$('[name="<?php echo $city; ?>"]').prop('disabled', true);
								<?php $numFields++; ?>
							<?php endif; ?>
							<?php if ($county): ?>
								$('[name="<?php echo $county; ?>"]').attr('id', autocompletePrefix+'administrative_area_level_2');
								$('[name="<?php echo $county; ?>"]').prop('disabled', true);
								<?php $numFields++; ?>
							<?php endif; ?>
							<?php if ($state): ?>
								$('[name="<?php echo $state; ?>"]').attr('id', autocompletePrefix+'administrative_area_level_1');
								$('[name="<?php echo $state; ?>"]').prop('disabled', true);
								<?php $numFields++; ?>
							<?php endif; ?>
							<?php if ($zip): ?>
								$('[name="<?php echo $zip; ?>"]').attr('id', autocompletePrefix+'postal_code');
								$('[name="<?php echo $zip; ?>"]').prop('disabled', true);
								<?php $numFields++; ?>
							<?php endif; ?>
							<?php if ($country): ?>
								$('[name="<?php echo $country; ?>"]').attr('id', autocompletePrefix+'country');
								$('[name="<?php echo $country; ?>"]').prop('disabled', true);
								<?php $numFields++; ?>
							<?php endif; ?>
							<?php if ($numFields > 0): ?>
								$('[name="<?php echo $autocomplete; ?>"]').attr('id', autocompleteId);
								$('[name="<?php echo $autocomplete; ?>"]').wrap('<div id="locationField"></div>');
								$('[name="<?php echo $autocomplete; ?>"]').attr('placeholder', 'Enter your address here');
								$('[name="<?php echo $autocomplete; ?>"]').on('keydown', function() { geolocate(); });
								$('[name="<?php echo $autocomplete; ?>"]').focus(function() { geolocate(); });
							<?php endif; ?>
							initAutocomplete();
						});
					</script>
		
					<!-- Google code configured to this system -->
					<script>
						var placeSearch, autocomplete;
						var componentForm = {
							// Lets check and see which fields we need
							<?php echo ($streetNumber ? "street_number: 'short_name'," : "" ); ?>
							<?php echo ($street ? "route: 'long_name'," : "" ); ?>
							<?php echo ($city ? "locality: 'long_name'," : "" ); ?>
							<?php echo ($county ? "administrative_area_level_2: 'short_name'," : "" ); ?>
							<?php echo ($state ? "administrative_area_level_1: 'short_name'," : "" ); ?>
							<?php echo ($country ? "country: 'long_name'," : "" ); ?>
							<?php echo ($zip ? "postal_code: 'short_name'," : "" ); ?>
						};

						function initAutocomplete() {
							/* Create the autocomplete object, restricting the search to geographical location types. */
							var defaultBounds = new google.maps.LatLngBounds(
								new google.maps.LatLng(-90,-180),
								new google.maps.LatLng(90,180)
							);
							autocomplete = new google.maps.places.Autocomplete(
								(document.getElementById(autocompleteId)),
								{types: ['address']}
							);
							/* When the user selects an address from the dropdown, populate the address fields in the form. */
							autocomplete.addListener('place_changed', fillInAddress);
						}

						function updateValue(id, value){
							var element = document.getElementById(id);
							var eleType = $(element).prop('type');
							element.value = value;
							
							// Is this a unique field type?
							if($(element).hasClass('hiddenradio')) { // Are we working with a radio field?
								var eleName = $(element).attr('name');
								$('input[name="'+eleName+'___radio"][value="'+value+'"]').prop('checked', true);
							} else if(eleType.indexOf("select") >= 0) { // Is it a select field?
								if($('#'+id+' option[value="'+value+'"]').length > 0) { // Is our value an option in the select?
									$('#'+id+' option[value="'+value+'"]').prop('selected', true);
								} else if($('#'+id+' option[value="'+valUnderscore+'"]').length > 0) { // Lets try again with underscores
									var valUnderscore = value.replace(/\s+/g,"_");
									console.log(valUnderscore);
									$('#'+id+' option[value="'+valUnderscore+'"]').prop('selected', true);
								} else if($('#'+id+' option[value="Other"]').length > 0) { // Still haven't found it. Let's check for an "Other" option
									$('#'+id+' option[value="Other"]').prop('selected', true);
								} else {
									var labelId = $(element).attr('aria-labelledby');
									var labelValue = $('#'+labelId).text().trim();
									alert("Could not auto complete the \""+labelValue+"\" field.");
									$('#'+id+' option[value=""]').prop('selected', true);
								}
							}

							$(element).change(); // Trigger the change listener, in case other modules/hooks want to know when this field changes.
						}
			
						function fillInAddress() {
							// Trigger a change event for the field.  This was added so other modules that check the
							// address would trigger after the address is updated (like Census Geocoder).
							$('#'+autocompleteId).change();

							/* Get the place details from the autocomplete object. */
							var place = autocomplete.getPlace();
							for (var component in componentForm) {
								updateValue(autocompletePrefix+component, '');
							}
			
							/* Get each component of the address from the place details and fill the corresponding field on the form. */
							for (var i = 0; i < place.address_components.length; i++) {
								var addressType = place.address_components[i].types[0];
								if (componentForm[addressType] && (document.getElementById(autocompletePrefix+addressType))) {
									var val = place.address_components[i][componentForm[addressType]];
									if(addressType == 'administrative_area_level_2') {
										val =  $.trim(val.replace('County',''));
									}
									updateValue(autocompletePrefix+addressType, val);
									document.getElementById(autocompletePrefix+addressType).disabled = false;
								}
							}
							doBranching();
						}
			
						/* Bias the autocomplete object to the user's geographical location, as supplied by the browser's 'navigator.geolocation' object. */
						function geolocate() {
							if (navigator.geolocation) {
								navigator.geolocation.getCurrentPosition(function(position) {
									lastGeolocateCallTimestamp = Date.now();
									var geolocation = {
										lat: position.coords.latitude,
										lng: position.coords.longitude
									};
									var circle = new google.maps.Circle({
										center: geolocation,
										radius: position.coords.accuracy
									});
									autocomplete.setBounds(circle.getBounds());
								});
							}
						}
					</script>

					<?php if ($import): ?>
						<!-- import Google API for places -->
						<script type="text/javascript" src="https://maps.googleapis.com/maps/api/js?key=<?php echo $key; ?>&libraries=places" async defer></script>
					<?php endif; ?>
				<?php 
			}
		}
	}
}
