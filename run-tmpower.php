<?php
    define('UPDATE_INTERVAL', 20);

    require './autoload.php';

    date_default_timezone_set('Europe/Amsterdam');

    $tmPower = new tmPower\tmPower([

        'cache' => [
            'enabled'   => true,
            'interval'  => 15,
        ],

        'coulombs' => [
            'enabled'   => true,
        ],

        'enever' => [
            'token'     => 'api key here',
        ],

        'homeassistant' => [
            'host'      => 'http://homeassistant.local:8123',
            'token'     => 'longlife token here',
        ],

        'mqtt' => [
            'host'      => 'homeassistant.local',
            'user'      => 'username',
            'pass'      => 'password',
            'topic'     => 'homeassistant/sensor/tmBatt/inverter',
        ],

        'powerstream' => [
            'serial'     => 'serial here',
            'access_key' => 'access key here',
            'secret_key' => 'secret key here',
        ],

        'p1' => [
            'host'      => 'p1.local',
            'port'      => 2001,
        ],
    ]);

    $powerstream = $tmPower->ecoflow->getDevice( $tmPower->ecoflow_ps_serial );
    $tmPower->ecoflow->currentOutput = $powerstream['data']['20_1.permanentWatts'];

    $tmPower->addTask('mqtt_ping', function ($tmPower) {
        $tmPower->mqtt->ping();
    }, 5);

    $tmPower->addTask('hass', function ($tmPower) {

        $charger = $tmPower->homeassistant->getEntityState('switch.tmbatt_acculader');
        $tmPower->chargerState = $charger['state'];

        $inverter = $tmPower->homeassistant->getEntityState('switch.tmbatt_omvormer');
        $tmPower->inverterState = $inverter['state'];

        $resetCoulombs = $tmPower->homeassistant->getEntityState('input_boolean.tmbatt_reset_coulombs');
        if( $resetCoulombs['state'] == 'on' )
        {
            $tmPower->coulombs->reset();
            $tmPower->updateCache();
            $tmPower->homeassistant->setEntityState('input_boolean.tmbatt_reset_coulombs', 'off');
        }

    }, UPDATE_INTERVAL);

    $tmPower->addTask('p1_average', function ($tmPower) {

        if( $tmPower->chargerState !== 'off' )
            return false;
    
        if( $tmPower->inverterState !== 'on' )
            return false;

        $telegram = $tmPower->p1->readTelegram();

        // Check of het telegram compleet (genoeg) is
        while( !isset($telegram['current_consumption']) || !isset($telegram['current_production']) )
        {
            $telegram = $tmPower->p1->readTelegram();
        }

        // Converteer consumption and production naar Watt in plaats van kW
        $consumption = floatval($telegram['current_consumption']) * 10000 ?? 0;
        $production  = floatval($telegram['current_production']) * 10000 ?? 0;

        $tmPower->p1->addValue( $consumption, $production );

        $values = $tmPower->p1->getValues();
        $last = end( $values );
    }, 1);


    $tmPower->addTask('ecoflow_power', function ($tmPower) {

        $powerstream = $tmPower->ecoflow->getDevice( $tmPower->ecoflow_ps_serial );
        $powerstreamOutput = $powerstream['data']['20_1.permanentWatts'];
        $powerstreamOutputInit = $powerstreamOutput;
        $updatePowerstream = false;

        // Bij eerste keer uitvoeren, verzekeren dat er P1-data bestaat
        if( $tmPower->getTaskCount() == 1 )
        {
            $tmPower->runTask('p1_average');
        }

        if(
            $tmPower->chargerState      === 'off' && 
            $tmPower->inverterState     === 'on' &&
            ( date("H") >= 18 || date("H") < 9 )
        )
        {
            $average = $tmPower->p1->getAverageUsage();

            $consumption = ceil( ($average > 0 ?  $average : 0 ) );
            $production  = ceil( ($average < 0 ? -$average : 0 ) );

            // Als er nog stroom wordt afgenomen, vermogen van de PS verhogen
            if( $consumption > 0 )
            {
                $powerstreamOutput += $consumption;
            }

            // Als er wordt teruggeleverd, vermogen van de PS verlagen
            if( $production > 0 )
            {
                $powerstreamOutput -= $production;
            }

            // Afronden op ronde tientallen Watts
            $powerstreamOutput = round($powerstreamOutput/10) * 10;

            if( $powerstreamOutput < 0 )
                $powerstreamOutput = 0;

            if( $powerstreamOutput > 4000 )
                $powerstreamOutput = 4000;

            // Alleen updaten als het verschil meer dan 10 W is
            $updatePowerstream = (abs($powerstreamOutputInit - $powerstreamOutput) > 100) ;

        } else
        {
            $powerstreamOutput = 0;
            $average = $tmPower->p1->getAverageUsage( false );
            $consumption = ceil( ($average > 0 ?  $average : 0 ) );
            $production  = ceil( ($average < 0 ? -$average : 0 ) );

            $updatePowerstream = ($powerstreamOutput == 0 && $powerstreamOutputInit != 0);
        }

        $battVoltage = (
                    $powerstream['data']['20_1.pv1InputVolt'] 
                    + $powerstream['data']['20_1.pv2InputVolt']
                ) / 20;

        if( $battVoltage < 23.5 )
        {
            $updatePowerstream = true;
            $powerstreamOutput = 0;

            $tmPower->homeassistant->setEntityState('switch.tmbatt_omvormer', 'off');
        }

        if( $updatePowerstream )
        {
            if( $powerstreamOutputInit == 0 )
            {
                $tmPower->coulombs->reset();
                $tmPower->updateCache();
                $tmPower->homeassistant->setEntityState('input_boolean.tmbatt_accu_vol', 'off');
            }

            $tmPower->ecoflow->currentOutput = $powerstreamOutput;
          
            $status = $tmPower->ecoflow->setDeviceFunction( $tmPower->ecoflow_ps_serial,
                'WN511_SET_PERMANENT_WATTS_PACK', 
                [ 'permanent_watts' => $powerstreamOutput ]
            );
        }

        // MQTT-update versturen
        $tmPower->ecoflow->currentOutput = $powerstreamOutput;
        $tmPower->ecoflow->currentVoltage = $battVoltage;

        $tmPower->coulombs->register( $powerstreamOutput*0.1, UPDATE_INTERVAL, $battVoltage );

        $mqttMessage = [
            'charger'  => $tmPower->chargerState,
            'inverter' => $tmPower->inverterState,
            'output'   => $tmPower->ecoflow->currentOutput/10,
            'voltage'  => $battVoltage,
            'coulombs' => $tmPower->coulombs->getTotal(),
            'iteration' => $tmPower->getTaskCount(),
        ];

        $tmPower->mqtt->publish( $tmPower->mqtt_topic, json_encode($mqttMessage) );

    }, UPDATE_INTERVAL);

    // Script na een vijf minuten stoppen (nieuwe cron start)
    $tmPower->addTask('kill', function ($tmPower) {

        if( $tmPower->getTaskCount() > 1 )
        {
            $tmPower->updateCache();
            $tmPower->mqtt->disconnect();

            die("End of this run. Come again!\n");
        }

    }, 300);

    // En starten maar!
    $tmPower->init();
