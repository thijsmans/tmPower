<?php
    require './autoload.php';
    date_default_timezone_set('Europe/Amsterdam');

    // Initiate tmPower
    $tmPower = new tmPower\tmPower([

        'enever' => [
            'token'     => 'api token here',
        ],

        'homeassistant' => [
            'host'      => 'http://homeassistant.local:8123',
            'token'     => 'longlife token here',
        ],

        'optimizer' => [
            'enabled'   => true,
        ],

    ]);

    // Get Enever data (data for tomorrow available @ 1500 hours)
    $time_now = date('Y-m-d H:00:00');    
    $forceUpdate = intval(date("H")) == 15;

    echo "Using " . ($forceUpdate ? 'new' : 'cached' ) . " data\n";

    $rates = $tmPower->enever->getData( $forceUpdate );

    // Update tariff entity
    $sensor = [
        'value' => isset($rates[$time_now]) ? $rates[$time_now] : null,
        'attributes' => [
            'times' => array_keys($rates),
            'prices' => array_values($rates),
        ],
    ];

    $tmPower->homeassistant->setEntityState(
        'sensor.zonneplan_huidig_tarief', 
        $sensor['value'], 
        $sensor['attributes']
    );

    // If new data was retrieved, calculate optimal battery profile
    if( $forceUpdate )
    {
        $tmPower->optimizer->setRates( $rates );
        $profile = $tmPower->optimizer->getProfile();

        file_put_contents("profile.log", json_encode($profile, JSON_PRETTY_PRINT) );

        // Check if net savings exceed threshold
        if ($profile['net_savings'] > 0.10) 
        {
            // Clear the cache
            $tmPower->writeCache(['profile' => null]);

            // Get the date of the first charging hour
            $firstChargingHour = key($profile['charging_hours']);
            $date = $firstChargingHour ? explode(' ', $firstChargingHour)[0] : null;

            // Initialize arrays for charging and discharging hours
            $charging_hours = [];
            $discharging_hours = [];

            // Helper function to extract hours from time strings
            $extractHours = function ($hoursArray) {
                return array_map(function($time) {
                    return date('H', strtotime($time));
                }, array_keys($hoursArray));
            };

            // Extract charging and discharging hours
            $charging_hours = $extractHours($profile['charging_hours']);
            $discharging_hours = $extractHours($profile['discharging_hours']);

            // Write the updated profile to cache
            $tmPower->writeCache([
                'profile' => [
                    $date => [
                        'charging_hours'    => $charging_hours,
                        'discharging_hours' => $discharging_hours,
                        'savings'           => $profile['net_savings'],
                    ],
                ],
            ]);

            // Set discharging mode to profile
            $tmPower->homeassistant->callDomainService( 'input_select', 'select_option', [
                'entity_id' => 'input_select.tmbatt_aansturing',
                'option' => 'Profiel'
            ]);

        } else
        {
            echo "No new profile saved (too little savings)\n";

            // No suitable profile found: set discharging mode to NOM
            $tmPower->homeassistant->callDomainService( 'input_select', 'select_option', [ 
                'entity_id' => 'input_select.tmbatt_aansturing',
                'option' => 'Nul-op-de-meter'
            ]);
        }
    } else
    {
        echo "No new data, so no profile calculated.\n\n";
    }
/**
Sample profile:
{
    "charging_hours": {
        "2024-10-16 11:00:00": "0.168784",
        "2024-10-16 12:00:00": "0.156599",
        "2024-10-16 13:00:00": "0.154118",
        "2024-10-16 14:00:00": "0.156490",
        "2024-10-16 15:00:00": "0.168808"
    },
    "discharging_hours": {
        "2024-10-16 17:00:00": "0.272529",
        "2024-10-16 18:00:00": "0.279910",
        "2024-10-16 19:00:00": "0.277490",
        "2024-10-16 20:00:00": "0.246925"
    },
    "total_charging_cost": 0.5993385494117647,
    "total_discharging_savings": 0.681648582,
    "net_savings": 0.08231003258823533
}
*/
