<?php
    namespace tmPower;

    class BatteryOptimizer 
    {
        // Battery capacity in kWh
        private $batteryCapacity; 

        // Discharge rate per hour in kWh (average usage)
        private $dischargeRate;

        // Baseline for household discharge rate in kWh
        private $baselineDischarge;

        // Array of hourly rates
        private $rates;

        // Charging efficiency (85% efficiency -> 15% loss)
        private $chargingEfficiency;

        /**
         * Constructor to initialize the battery optimizer with default or provided values
         *
         * @param float $capacityKWh - Total battery capacity in kWh
         * @param float $dischargeRateW - Discharge rate in watts (converted to kWh)
         * @param float $baselineW - Baseline household consumption in watts (converted to kWh)
         */
        public function __construct($capacityKWh = 2.5, $dischargeRateW = 633, $baselineW = 200) 
        {
            // Initialize the battery capacity and convert watts to kWh
            $this->batteryCapacity = $capacityKWh;
            $this->dischargeRate = $dischargeRateW / 1000; // Convert watts to kWh
            $this->baselineDischarge = $baselineW / 1000;  // Convert baseline watts to kWh
            $this->chargingEfficiency = 0.85; // 85% efficiency means 15% loss
            $this->rates = []; // Initialize rates as an empty array
        }

        /**
         * Set the array of hourly rates for electricity pricing
         *
         * @param array $rates - Array of hourly rates, format ['Y-m-d H:i:s' => rate]
         */
        public function setRates($rates) 
        {
            $this->rates = $rates;
        }

        /**
         * Get the cheapest hours to charge the battery, considering time restrictions and charging efficiency
         *
         * @return array - Returns an array of charging hours with rates
         */
        public function getChargingHours() 
        {
            $chargeHours = [];
            $remainingCapacity = $this->batteryCapacity / $this->chargingEfficiency; // Adjust for charging loss
            $currentTime = time(); // Get the current timestamp

            // Sort rates from lowest to highest to prioritize cheaper hours
            asort($this->rates);

            foreach ($this->rates as $hour => $rate) 
            {
                $time = strtotime($hour);
                $hourOfDay = (int)date('H', $time); // Get the hour of the day

                // Skip hours that are outside the allowed charging window (08:00 to 18:00) or in the past
                if ($hourOfDay < 8 || $hourOfDay >= 18 || $time <= $currentTime) {
                    continue;
                }

                // Stop if battery is fully charged
                if ($remainingCapacity <= 0) {
                    break;
                }

                // Add the hour to the charge list
                $chargeHours[$hour] = $rate;

                // Decrease remaining capacity by the discharge rate (converted to account for charging efficiency)
                $remainingCapacity -= $this->dischargeRate;
            }

            ksort($chargeHours); // Sort the charge hours by time for easier reading
            return $chargeHours;
        }

        /**
         * Get the optimal hours to discharge the battery, considering charging times and future hours
         *
         * @return array - Returns an array of discharging hours with rates
         */
        public function getDischargingHours() 
        {
            $dischargeHours = [];
            $remainingCapacity = $this->batteryCapacity;
            $currentTime = time(); // Get the current timestamp

            // Get the last charge hour to ensure discharging happens after charging
            $chargingHours = $this->getChargingHours();
            $lastChargeHour = !empty($chargingHours) ? max(array_keys($chargingHours)) : null;

            // Sort rates from highest to lowest to prioritize discharging during expensive hours
            arsort($this->rates);

            foreach ($this->rates as $hour => $rate) 
            {
                $time = strtotime($hour);

                // Skip hours that are in the past or before the last charge hour
                if ($time <= $currentTime || ($lastChargeHour && $time <= strtotime($lastChargeHour))) {
                    continue;
                }

                // Stop if battery is fully discharged
                if ($remainingCapacity <= 0) {
                    break;
                }

                // Add the hour to the discharge list
                $dischargeHours[$hour] = $rate;

                // Decrease remaining capacity by the discharge rate
                $remainingCapacity -= $this->dischargeRate;
            }

            ksort($dischargeHours); // Sort the discharge hours by time for easier reading
            return $dischargeHours;
        }

        /**
         * Get the complete profile with charging hours, discharging hours, and total costs/savings
         *
         * @return array - Returns an array with charging hours, discharging hours, total charging costs, total discharging savings, and net savings
         */
        public function getProfile() 
        {
            // Retrieve the optimized charging and discharging hours
            $chargingHours = $this->getChargingHours();
            $dischargingHours = $this->getDischargingHours();

            // Calculate the total cost of charging and total savings from discharging
            $totalChargingCost = 0;
            $totalDischargingSavings = 0;

            // Sum the charging costs, accounting for efficiency loss
            foreach ($chargingHours as $rate) {
                $totalChargingCost += $rate * ($this->dischargeRate / $this->chargingEfficiency);
            }

            // Sum the discharging savings
            foreach ($dischargingHours as $rate) {
                $totalDischargingSavings += $rate * $this->dischargeRate;
            }

            // Calculate net savings by subtracting total charging cost from discharging savings
            $netSavings = $totalDischargingSavings - $totalChargingCost;

            return [
                'charging_hours' => $chargingHours,
                'discharging_hours' => $dischargingHours,
                'total_charging_cost' => $totalChargingCost,
                'total_discharging_savings' => $totalDischargingSavings,
                'net_savings' => $netSavings
            ];
        }
    }
