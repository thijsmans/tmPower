<?php
    // Get optimized charging/discharging profile for current dynamic rates

    require './autoload.php';

    date_default_timezone_set('Europe/Amsterdam');

    // Instantiate the tmPower object with configuration
    $tmPower = new tmPower\tmPower([
        'enever' => [
            'token' => 'token goes here',
        ],

        'cache' => [
            'enabled'   => true,
        ],

        'optimizer' => [
            'enabled'   => true,
        ],
    ]);

    // Set the rates from enever data
    $tmPower->optimizer->setRates( $tmPower->enever->getData() );

    // Get the battery optimization profile
    $profile = $tmPower->optimizer->getProfile();

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
    }

/*
    Sample of the profile written to cache:
    [profile] => Array
        (
            [2024-09-27] => Array
                (
                    [charging_hours] => Array
                        (
                            [0] => 10
                            [1] => 11
                            [2] => 12
                            [3] => 13
                            [4] => 14
                        )

                    [discharging_hours] => Array
                        (
                            [0] => 17
                            [1] => 18
                            [2] => 19
                            [3] => 20
                        )

                    [savings] => 0.029282877882353
                )
        )
*/
