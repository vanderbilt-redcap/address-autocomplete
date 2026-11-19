<?php namespace Vanderbilt\AddressExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class AddressExternalModule extends \ExternalModules\AbstractExternalModule
{
    public function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id)
    {
        $this->addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id);
    }

    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id)
    {
        $this->addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id);
    }

    public function addAddressAutoCompletion($project_id, $record, $instrument, $event_id, $group_id)
    {
        $key = $this->getProjectSetting('google-api-key', $project_id);
        $import = $this->getProjectSetting('import-google-api', $project_id);
        $useMap = $this->getProjectSetting('use-map', $project_id);
        $defaultLat = $this->getProjectSetting('default-lat', $project_id);
        if (!is_numeric($defaultLat)) {
            $defaultLat = "36.142";
        }
        $defaultLng = $this->getProjectSetting('default-lng', $project_id);
        if (!is_numeric($defaultLng)) {
            $defaultLng = "-86.801";
        }
        if ($key) {
            $autocompletes = $this->getProjectSetting('autocomplete', $project_id);
            $streetNumbers = $this->getProjectSetting('street-number', $project_id);
            $streets = $this->getProjectSetting('street', $project_id);
            $cities = $this->getProjectSetting('city', $project_id);
            $counties = $this->getProjectSetting('county', $project_id);
            $states = $this->getProjectSetting('state', $project_id);
            $zips = $this->getProjectSetting('zip', $project_id);
            $countries = $this->getProjectSetting('country', $project_id);
            $latitudes = $this->getProjectSetting('latitude', $project_id);
            $longitudes = $this->getProjectSetting('longitude', $project_id);

            $latlngFields = array_filter(array_merge($latitudes, $longitudes));
            $recordData = [];
            if (!empty($latlngFields)) {
                $recordData = \REDCap::getData([
                    'project_id' => $project_id,
                    'return_format' => 'json-array',
                    'records' => [$record],
                    'fields' => $latlngFields,
                    'events' => [$event_id]
                ])[0] ?? [];
            }

            $width = 600;
            $this->initializeJavascriptModuleObject();
            $this->framework->tt_addToJavascriptModuleObject("componentForm", [
                    ...($streetNumbers ? ["street_number" => 'short_name'] : []),
                    ...($streets ? ["route" => 'long_name'] : []),
                    ...($cities ? ["locality" => 'long_name'] : []),
                    ...($counties ? ["administrative_area_level_2" => 'short_name'] : []),
                    ...($states ? ["administrative_area_level_1" => 'short_name'] : []),
                    ...($countries ? ["country" => 'long_name'] : []),
                    ...($zips ? ["postal_code" => 'short_name'] : [])
            ]);
            $this->framework->tt_addToJavascriptModuleObject("coordsForm", [
                    ...($latitudes ? ["0" => 'lat'] : []),
                    ...($longitudes ? ["1" => 'lng'] : [])
            ]);
            $this->framework->tt_addToJavascriptModuleObject("autocomplete", []);
            $this->framework->tt_addToJavascriptModuleObject("map", []);

            ?>
            <script>
                const module = <?=$this->framework->getJavascriptModuleObjectName()?>;
                const autocompletePrefix = 'googleSearch_';
                const autocompleteId = autocompletePrefix+'autocomplete';
                const MAP_DIV_ELEMENT_ID = "google_map";
            </script>
            <?php
            foreach ($autocompletes as $index => $autocomplete) {
                if ($autocomplete) {
                    $numFields = 0;
                    $streetNumber = $streetNumbers[$index];
                    $street = $streets[$index];
                    $city = $cities[$index];
                    $county = $counties[$index];
                    $state = $states[$index];
                    $zip = $zips[$index];
                    $country = $countries[$index];
                    $latitude = $latitudes[$index];
                    $longitude = $longitudes[$index];

                    $startLat = is_numeric(($recordData[$latitude] ?? "")) ? $recordData[$latitude] : "";
                    $startLng = is_numeric(($recordData[$longitude] ?? "")) ? $recordData[$longitude] : "";
                    # configure the form; disable fields; set IDs

                    ?>
                    <script>
                        $(document).ready(function() {
                            <?php if ($streetNumber): ?>
                            $('[name="<?php echo $streetNumber; ?>"]').attr('id', autocompletePrefix+'street_number<?php echo $index; ?>');
                            $('[name="<?php echo $streetNumber; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($street): ?>
                            $('[name="<?php echo $street; ?>"]').attr('id', autocompletePrefix+'route<?php echo $index; ?>');
                            $('[name="<?php echo $street; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($city): ?>
                            $('[name="<?php echo $city; ?>"]').attr('id', autocompletePrefix+'locality<?php echo $index; ?>');
                            $('[name="<?php echo $city; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($county): ?>
                            $('[name="<?php echo $county; ?>"]').attr('id', autocompletePrefix+'administrative_area_level_2<?php echo $index; ?>');
                            $('[name="<?php echo $county; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($state): ?>
                            $('[name="<?php echo $state; ?>"]').attr('id', autocompletePrefix+'administrative_area_level_1<?php echo $index; ?>');
                            $('[name="<?php echo $state; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($zip): ?>
                            $('[name="<?php echo $zip; ?>"]').attr('id', autocompletePrefix+'postal_code<?php echo $index; ?>');
                            $('[name="<?php echo $zip; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($country): ?>
                            $('[name="<?php echo $country; ?>"]').attr('id', autocompletePrefix+'country<?php echo $index; ?>');
                            $('[name="<?php echo $country; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($latitude): ?>
                            $('[name="<?php echo $latitude; ?>"]').attr('id', autocompletePrefix+'lat<?php echo $index; ?>');
                            $('[name="<?php echo $latitude; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($longitude): ?>
                            $('[name="<?php echo $longitude; ?>"]').attr('id', autocompletePrefix+'lng<?php echo $index; ?>');
                            $('[name="<?php echo $longitude; ?>"]').prop('disabled', true);
                            <?php $numFields++; ?>
                            <?php endif; ?>
                            <?php if ($numFields > 0): ?>
                            $('[name="<?php echo $autocomplete; ?>"]').attr('id', autocompleteId+<?php echo $index; ?>);
                            $('[name="<?php echo $autocomplete; ?>"]').wrap('<div id="locationField"></div>');
                            $('[name="<?php echo $autocomplete; ?>"]').attr('placeholder', 'Enter your address here');
                            $('[name="<?php echo $autocomplete; ?>"]').on('keydown', function() { geolocate(<?php echo $index; ?>); });
                            $('[name="<?php echo $autocomplete; ?>"]').focus(function() { geolocate(<?php echo $index; ?>); });
                            <?php echo($useMap ? "$('#'+autocompleteId+$index).closest('tr[id$=\'-tr\']').after(\"<tr id='\"+MAP_DIV_ELEMENT_ID+\"$index-tr'><td colspan='2' style='text-align: center;'><div style='width:".$width."px; font-size: 12px; font-style: italic;'>Use the controls to zoom. Double click to set the coordinates.</div><div id='\"+MAP_DIV_ELEMENT_ID+\"$index' style='width:".$width."px; height:450px;'></div></td></tr>\");" : ""); ?>
                            <?php endif; ?>
                            initAutocomplete(<?php echo $index; ?>);
                            <?php echo ($useMap ? "initializeGoogleMap('$index','".($startLat ?: $defaultLat)."','".($startLng ?: $defaultLng)."');" : "") ?>
                        });
                    </script>
                    <?php
                }
            }
            ?>
            <!-- Google code configured to this system -->
            <script>
                function updateValue(id, value){
                    var element = $('#' + id);

                    if(element.length === 0){
                        console.log('Could not find the element with the following id:', id)
                        return
                    }

                    var eleType = element.prop('type');
                    element.val(value);

                    // Is this a unique field type?
                    var eleName = element.attr('name');
                    if(element.hasClass('hiddenradio')) { // Are we working with a radio field?
                        $('input[name="'+eleName+'___radio"][value="'+value+'"]').prop('checked', true);
                    } else if(eleType.indexOf("select") >= 0) { // Is it a select field?
                        if($('#'+id+' option[value="'+value+'"]').length > 0) { // Is our value an option in the select?
                            $('#'+id+' option[value="'+value+'"]').prop('selected', true);
                        } else if($('#'+id+' option[value="'+valUnderscore+'"]').length > 0) { // Lets try again with underscores
                            var valUnderscore = value.replace(/\s+/g,"_");
                            $('#'+id+' option[value="'+valUnderscore+'"]').prop('selected', true);
                        } else if($('#'+id+' option[value="Other"]').length > 0) { // Still haven't found it. Let's check for an "Other" option
                            $('#'+id+' option[value="Other"]').prop('selected', true);
                        } else {
                            var optionsWithMatchingContent = $('#'+id+' option').filter(function(){
                                return $(this).html() === value
                            })

                            if(optionsWithMatchingContent.length === 1){
                                optionsWithMatchingContent.prop('selected', true);
                            } else {
                                alert("The value '" + value + "' is not a valid value for the '" + eleName + "' field.");
                                $('#'+id+' option[value=""]').prop('selected', true);
                            }
                        }
                    }

                    element.change(); // Trigger the change listener, in case other modules/hooks want to know when this field changes.

                    if(element.hasClass('rc-autocomplete')){
                        var autocompleteField = element.closest('td').find('.ui-autocomplete-input')
                        autocompleteField.val(element.find('option:selected').text())
                        autocompleteField.change()
                    }
                }

                function fillInAddress(index) {
                    // Trigger a change event for the field.  This was added so other modules that check the
                    // address would trigger after the address is updated (like Census Geocoder).
                    $('#'+autocompleteId+index).change();

                    /* Get the place details from the autocomplete object. */
                    var place = module.tt('autocomplete')[index].getPlace();

                    let componentForm = module.tt('componentForm');
                    let coordsForm = module.tt('coordsForm');
                    for (var component in componentForm) {
                        updateValue(autocompletePrefix+component+index, '');
                    }
                    for (var coordComponent in coordsForm) {
                        updateValue(autocompletePrefix+component+index, '');
                    }

                    if (place !== undefined && place.geometry && place.geometry.location) {
                        /* Get each component of the address from the place details and fill the corresponding field on the form. */
                        for (var i = 0; i < place.address_components.length; i++) {
                            var addressType = place.address_components[i].types[0];
                            if (componentForm[addressType] && (document.getElementById(autocompletePrefix+addressType+index))) {
                                var val = place.address_components[i][componentForm[addressType]];
                                if(addressType == 'administrative_area_level_2') {
                                    val =  $.trim(val.replace('County',''));
                                }
                                updateValue(autocompletePrefix+addressType+index, val);
                                document.getElementById(autocompletePrefix+addressType+index).disabled = false;
                            }
                        }

                        for (var j = 0; j < Object.keys(coordsForm).length; j++) {
                            let coordType = coordsForm[j];
                            if (place.geometry.location[coordType] && document.getElementById(autocompletePrefix + coordType + index)) {
                                var cVal = place.geometry.location[coordType];
                                updateValue(autocompletePrefix + coordType + index, cVal);
                                document.getElementById(autocompletePrefix + coordType + index).disabled = false;
                            }
                        }
                    } else {
                        // undefined place implies a blank address, lat and long must be blanked
                        for (var k = 0; k < Object.keys(coordsForm).length; k++) {
                            updateValue(autocompletePrefix + coordsForm[k] + index, '');
                        }
                    }
                    doBranching();
                }
            </script>

            <script>
                function initAutocomplete(index) {
                    module.tt('autocomplete')[index] = new google.maps.places.Autocomplete(
                        (document.getElementById(autocompleteId+index)),
                        {types: ['address']}
                    );
                    /* When the user selects an address from the dropdown, populate the address fields in the form. */
                    module.tt('autocomplete')[index].addListener('place_changed', () => {
                        fillInAddress(index);
                    })

                    // if the user deletes the address, erase all address components
                    let addressField = document.getElementById(autocompleteId+index);

                    addressField.addEventListener('change', (event) => {
                        if (addressField.value === "") { fillInAddress(index); }
                    });
                }

                /* Bias the autocomplete object to the user's geographical location, as supplied by the browser's 'navigator.geolocation' object. */
                function geolocate(index) {
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
                            module.tt('autocomplete')[index].setBounds(circle.getBounds());
                        });
                    }
                }

                function initializeGoogleMap(index, defaultLat, defaultLng) {
                    module.tt('map')[index] = new google.maps.Map(document.getElementById(MAP_DIV_ELEMENT_ID+index),
                        {
                            zoom: 15,
                            center: new google.maps.LatLng(defaultLat,defaultLng),
                            overviewMapControl:true,
                            overviewMapControlOptions:(true),
                            mapTypeId: google.maps.MapTypeId.ROADMAP,
                            streetViewControl: false,
                            fullscreenControl: false
                        });
                    console.log(defaultLat);
                    console.log(defaultLng);
                    marker = new google.maps.Marker({map:module.tt('map')[index],position: new google.maps.LatLng(defaultLat,defaultLng)});

                    google.maps.event.addListener(module.tt('map')[index], "click", async (event) => {
                        await googleMapClickHandler(event,index)
                    });
                }

                async function googleMapClickHandler(event,index) {
                    if(module.tt('map')[index].getZoom() < 15) {
                        alert("You need to zoom in more to set the location accurately." );
                        return;
                    }

                    try {
                        let url = "https://maps.googleapis.com/maps/api/geocode/json?latlng="+event.latLng.lat()+","+event.latLng.lng()+"&key=AIzaSyDBjk9eQKKFWa2CLSMQuPlYdDkXIn_e2fA";
                        const response = await fetch(url);
                        const data = await response.json();

                        if (data.results && data.results[0]) {
                            var address_value = data.results[0];
                            const element = document.querySelector("#"+autocompleteId+index);
                            element.value = address_value.formatted_address;
                            module.tt('autocomplete')[index].set("place",address_value);
                            fillInAddress(index);
                        }
                    } catch (error) {
                        console.error("Geocoder failed due to: " + error);
                    }
                }
            </script>
            <?php
            if ($import) {
                echo "<script type=\"text/javascript\" src=\"https://maps.googleapis.com/maps/api/js?key=".$key."&libraries=places\"></script>";
            }
        }
    }
}
