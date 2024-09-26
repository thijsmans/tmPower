<?php
    namespace tmPower;

    class CoulombCounter 
    {
        public $coulombs; // Total registered coulombs

        public function __construct() 
        {
            $this->coulombs = 0;
        }

        /**
         * Registers power consumption over a given duration in seconds.
         *
         * @param float $watts The power in watts.
         * @param int $seconds The duration in seconds.
         * @param float $voltage The voltage used (default is 24V).
         */
        public function register (float $watts, int $seconds, float $voltage = 24) 
        {
            // Calculate energy in joules (E = P * t)
            $joules = $watts * $seconds;
            
            // Coulombs = Joules / Voltage
            $coulombs = $joules / $voltage;

            // Add the calculated coulombs to the registered coulombs
            $this->coulombs += $coulombs;
        }

        /**
         * Get the total registered coulombs.
         *
         * @return float The total registered coulombs.
         */
        public function getTotal() 
        {
            return $this->coulombs;
        }

        /**
         * Reset the coulomb counter.
         * 
         * @return bool Returns true on reset.
         */
        public function reset ()
        {
            $this->coulombs = 0;
            return true;
        }
    }
