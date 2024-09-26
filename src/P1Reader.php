<?php
    namespace tmPower;
    
    /**
     * Class P1Reader
     * Leest en parseert DSMR-telegrams via een socketverbinding.
     */
    class P1Reader 
    {
        private $host;          // Host van de DSMR meter
        private $port;          // Poort voor de DSMR mete
        private $socket;        // Socketverbinding
        private $values = [];   // Opslag voor energieverbruik/productiewaarden

        /**
         * P1Reader constructor.
         * @param string $host - De host van de DSMR meter.
         * @param int $port - De poort van de DSMR meter.
         */
        public function __construct($host, $port) 
        {
            $this->host = $host;
            $this->port = $port;
            $this->connect();
        }

        /**
         * Maakt een socketverbinding met de DSMR meter.
         * @throws Exception - Gooit een uitzondering als de verbinding niet kan worden gemaakt.
         */
        private function connect() 
        {
            $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, 30);
            if (!$this->socket) {
                throw new Exception("Unable to connect to {$this->host}:{$this->port} - $errstr ($errno)");
            }
        }

        /**
         * Controleert of de socket nog actief is.
         * @return bool - True als de socket actief is, anders false.
         */
        private function isSocketAlive() 
        {
            if ($this->socket === false) 
            {
                return false;
            }

            $ping = fwrite($this->socket, "\r\n");
            return $ping !== false;
        }

        /**
         * Leest een telegram van de DSMR meter.
         * @return array - Geparseerd telegram als associatieve array.
         * @throws Exception - Gooit een uitzondering als de verbinding niet actief is en niet opnieuw kan worden gemaakt.
         */
        public function readTelegram() 
        {
            if (!$this->isSocketAlive()) 
            {
                $this->connect();
            }

            $telegram = '';
            while (($line = fgets($this->socket, 128)) !== false) 
            {
                $telegram .= $line;
                if (strpos($line, '!') === 0) {
                    break;
                }
            }
            return $this->parseTelegram($telegram);
        }

        /**
         * Parseert het telegram en vertaalt de sleutels naar beschrijvende namen.
         * @param string $telegram - Het ruwe DSMR telegram.
         * @return array - Geparseerd telegram als associatieve array met beschrijvende sleutels.
         */
        private function parseTelegram($telegram) 
        {
            $keyMap = [
                '0-0:1.0.0'   	=> 'timestamp',
                '0-0:96.1.1'    => 'equipment_identifier',
                '0-0:96.7.9'    => 'no_long_powerfailures',
                '0-0:96.7.21'   => 'no_powerfailures',
                '0-0:96.14.0'   => 'tariff_indicator',
                '1-0:1.7.0'     => 'current_consumption',
                '1-0:1.8.1'     => 'consumption_tariff_1',
                '1-0:1.8.2'     => 'consumption_tariff_2',
                '1-0:2.7.0'     => 'current_production',
                '1-0:2.8.1'     => 'production_tariff_1',
                '1-0:2.8.2'     => 'production_tariff_2',
                '1-0:21.7.0'    => 'instant_active_power_L1',
                '1-0:22.7.0'    => 'current_production_L1',
                '1-0:31.7.0'    => 'current_phase_L1',
                '1-0:32.7.0'    => 'voltage_phase_L1',
                '1-0:32.32.0'   => 'no_voltage_sags_L1',
                '1-0:32.36.0'   => 'no_voltage_swells_L1',
                '1-0:41.7.0'    => 'instant_active_power_L2',
                '1-0:42.7.0'    => 'current_production_L2',
                '1-0:51.7.0'    => 'current_phase_L2',
                '1-0:52.7.0'    => 'voltage_phase_L2',
                '1-0:52.32.0'   => 'no_voltags_sags_L2',
                '1-0:52.36.0'   => 'no_voltage_swells_L2',
                '1-0:61.7.0'    => 'instant_active_power_L3',
                '1-0:62.7.0'    => 'current_production_L#',
                '1-0:71.7.0'    => 'current_phase_L3',
                '1-0:72.7.0'    => 'voltage_phase_L3',
                '1-0:72.32.0'   => 'no_voltags_sags_L3',
                '1-0:72.36.0'   => 'no_voltage_swells_L3',
                '1-0:99.97.0'   => 'power_failure_event_log', 
                '1-3:0.2.8'     => 'version_information',
            ];

            $data = [];
            
            $lines = explode("\n", $telegram);

            foreach ($lines as $line) 
            {
                if (preg_match('/^(\d+-\d+:\d+\.\d+\.\d+)\(([^)]+)\)/', trim($line), $matches)) 
                {
                    $key = $matches[1];
                    $value = $matches[2];

                    if( array_key_exists($key, $keyMap) ) 
                        $key = $keyMap[ $key ];

                    $data[ $key ] = $value;
                }
               }
            
            return $data;
        }

        /**
         * Voeg een energieverbruik- of productie waarde toe aan de lijst.
         * Positieve waarden zijn verbruik, negatieve waarden zijn productie.
         * @param float $consumption - De verbruikswaarde in kW.
         * @param float $production - De productiewaarde in kW.
         */
        public function addValue($consumption=0, $production=0) 
        {
            if ($consumption > 0) {
                $this->values[] = $consumption;
            } elseif ($production > 0) {
                $this->values[] = -$production;
            }
        }

        /**
         * Verwijdert de eerste vijf elementen van de array $values,
         * maar behoudt altijd ten minste drie waarden in de array.
         */
        public function trimValues( $trim = 5 ) 
        {
            $valuesCount = count($this->values);
            
            if ($valuesCount > 3) {
                // Bereken het aantal te verwijderen elementen
                $elementsToRemove = min($trim, $valuesCount - 3);
                
                // Verwijder de eerste $elementsToRemove elementen
                $this->values = array_slice($this->values, $elementsToRemove);
            }
        }

        /**
         * Bereken het gemiddelde van de opgeslagen waarden en wis de waarden daarna.
         * @return float - Het gemiddelde van de opgeslagen waarden.
         */
        public function getAverageUsage( $clearValues=true ) 
        {
            if (count($this->values) === 0) {
                return 0;
            }
            $average = array_sum($this->values) / count($this->values);

            if( $clearValues )
            {
                $this->values = []; // Wis de opgeslagen waarden
            }
            
            return $average;
        }

        public function getValues ()
        {
            return $this->values;
        }

        /**
         * Destructor sluit de socketverbinding als deze nog open is.
         */
        public function __destruct() 
        {
            if ($this->socket) {
                fclose($this->socket);
            }
        }
    }
