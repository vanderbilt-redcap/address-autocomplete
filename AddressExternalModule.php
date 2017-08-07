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
			$state = $module_data['state']['value'];
			$zip = $module_data['zip']['value'];
			$country = $module_data['country']['value'];
			$import = $module_data['import-google-api']['value'];
		

			if ($key && $autocomplete) {
				# configure the form; disable fields; set IDs
				echo "<script>";
				echo "var autocompletePrefix = 'googleSearch_';";
				echo "var autocompleteId = autocompletePrefix+'autocomplete';";
				echo "$(document).ready(function() {";
				$numFields = 0;
				if ($streetNumber) {
					echo "$('[name=\"".$streetNumber."\"]').attr('id', autocompletePrefix+'street_number');";
					echo "$('[name=\"".$streetNumber."\"]').prop('disabled', true);";
					$numFields++;
				}
				if ($street) {
					echo "$('[name=\"".$street."\"]').attr('id', autocompletePrefix+'route');";
					echo "$('[name=\"".$street."\"]').prop('disabled', true);";
					$numFields++;
				}
				if ($city) {
					echo "$('[name=\"".$city."\"]').attr('id', autocompletePrefix+'locality');";
					echo "$('[name=\"".$city."\"]').prop('disabled', true);";
					$numFields++;
				}
				if ($state) {
					echo "$('[name=\"".$state."\"]').attr('id', autocompletePrefix+'administrative_area_level_1');";
					echo "$('[name=\"".$state."\"]').prop('disabled', true);";
					$numFields++;
				}
				if ($zip) {
					echo "$('[name=\"".$zip."\"]').attr('id', autocompletePrefix+'postal_code');";
					echo "$('[name=\"".$zip."\"]').prop('disabled', true);";
					$numFields++;
				}
				if ($country) {
					echo "$('[name=\"".$country."\"]').attr('id', autocompletePrefix+'country');";
					echo "$('[name=\"".$country."\"]').prop('disabled', true);";
					$numFields++;
				}
				if ($numFields > 0) {
					echo "$('[name=\"".$autocomplete."\"]').attr('id', autocompleteId);";
					echo "$('[name=\"".$autocomplete."\"]').wrap('<div id=\"locationField\"></div>');";
					echo "$('[name=\"".$autocomplete."\"]').attr('placeholder', 'Enter your address here');";
					echo "$('[name=\"".$autocomplete."\"]').on('keydown', function() { geolocate(); });";
					echo "$('[name=\"".$autocomplete."\"]').focus(function() { geolocate(); });";
				}
				echo "initAutocomplete();";
				echo "});";
				echo "</script>";
	
				# Google code configured to this system
				echo "<script>";
				echo "var placeSearch, autocomplete;";
				echo "var componentForm = {";
					echo "street_number: 'short_name',";
					echo "route: 'long_name',";
					echo "locality: 'long_name',";
					echo "administrative_area_level_1: 'short_name',";
					echo "country: 'long_name',";
					echo "postal_code: 'short_name'";
				echo "};";

				echo "function initAutocomplete() {";
					echo "/* Create the autocomplete object, restricting the search to geographical";
					echo " * location types. */";
					echo "var defaultBounds = new google.maps.LatLngBounds(";
						echo "new google.maps.LatLng(-90,-180),";
						echo "new google.maps.LatLng(90,180)";
					echo ");";
					echo "autocomplete = new google.maps.places.Autocomplete(";
						echo "(document.getElementById(autocompleteId)),";
						echo "{types: ['address']});";
					echo "/* When the user selects an address from the dropdown, populate the address";
					echo " * fields in the form. */";
					echo "autocomplete.addListener('place_changed', fillInAddress);";
				echo "}";
	
				echo "function fillInAddress() {";
					echo "/* Get the place details from the autocomplete object. */";
					echo "var place = autocomplete.getPlace();";
					echo "for (var component in componentForm) {";
						echo "document.getElementById(autocompletePrefix+component).value = '';";
					echo "}";
	
					echo "/* Get each component of the address from the place details";
					echo " * and fill the corresponding field on the form. */";
					echo "for (var i = 0; i < place.address_components.length; i++) {";
						echo "var addressType = place.address_components[i].types[0];";
						echo "if (componentForm[addressType] && (document.getElementById(autocompletePrefix+addressType))) {";
							echo "var val = place.address_components[i][componentForm[addressType]];";
							echo "document.getElementById(autocompletePrefix+addressType).value = val;";
							echo "document.getElementById(autocompletePrefix+addressType).disabled = false;";
						echo "}";
					echo "}";
                    echo "doBranching();";
				echo "}";
	
				echo "/* Bias the autocomplete object to the user's geographical location,";
				echo " * as supplied by the browser's 'navigator.geolocation' object. */";
				echo "function geolocate() {";
					echo "if (navigator.geolocation) {";
					echo "navigator.geolocation.getCurrentPosition(function(position) {";
						echo "lastGeolocateCallTimestamp = Date.now();";
						echo "var geolocation = {";
							echo "lat: position.coords.latitude,";
							echo "lng: position.coords.longitude";
						echo "};";
						echo "var circle = new google.maps.Circle({";
							echo "center: geolocation,";
							echo "radius: position.coords.accuracy";
						echo "});";
						echo "autocomplete.setBounds(circle.getBounds());";
					echo "});";
					echo "}";
				echo "}";
				echo "</script>";

				# import Google API for places
                if ($import) {
                    echo "<script type=\"text/javascript\" src=\"https://maps.googleapis.com/maps/api/js?key=".$key."&libraries=places\" async defer></script>";
                }
			}
		}
	}
}
