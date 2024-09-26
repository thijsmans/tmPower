<?php
    namespace tmPower;

    class tmPower 
    {
        public $ecoflow = null;
        public $ecoflow_ps_serial = null;
        public $enever = null;
        public $p1 = null;
        public $mqtt = null;
        public $mqtt_topic = null;
        public $cache;
        private $tasks = [];
        private $lastRunTimes = [];
        private $taskCounter = [];
        private $currentTaskName;
        public $stateOfCharge;

        /**
         * Constructor to initialize tmPower with provided arguments.
         *
         * @param array $args Configuration arguments for MQTT, Powerstream, and P1-reader.
         * @throws \Exception if required parameters are missing.
         */
        public function __construct($args) 
        {
            if( !empty($args['coulombs']['enabled']) )
                $this->setupCoulomb();

            if( !empty($args['enever']) )
                $this->setupEnever($args['enever']);

            if( !empty($args['homeassistant']) )
                $this->setupHomeAssistant($args['homeassistant']);

            if( !empty($args['mqtt']) )
                $this->setupMQTT($args['mqtt']);

            if( !empty($args['powerstream']) )
            {
                $this->setupPowerstream($args['powerstream']);
                $this->ecoflow_ps_serial = $args['powerstream']['serial'];
            }

            if( !empty($args['p1']) )
                $this->setupP1($args['p1']);

            if( !empty($args['cache']) && $args['cache']['enabled'] == true )
            {
                $this->cache = new tmCache();
                $this->applyCache();

                $this->addTask('__cache', function ($tmPower){
                    $tmPower->updateCache();
                }, intval($args['cache']['interval']) ?? 15 );
            }

        }

        /**
         * Setup coulomb counter.
         * 
         */
        private function setupCoulomb()
        {
            $this->coulombs = new CoulombCounter();
        }

        /**
         * Setup Enever API/feed for real time prices.
         *
         * @param array $enever Enever token/configuration.
         * @throws \Exception if required parameters are missing.
         */
        private function setupEnever($enever)
        {
            if( ! empty($enever) )
            {
                if( empty($enever['token']) )
                {
                    throw new \Exception('Trying to setup enever without token.');
                }

                $this->enever = new EneverV2($enever['token'], $enever['supplier']??'ZP');
            }
        }

        /**
         * Setup Home Assistant API.
         *
         * @param array $hass Home Assistant token/configuration.
         * @throws \Exception if required parameters are missing.
         */
        private function setupHomeAssistant($homeassistant)
        {
            if( ! empty($homeassistant) )
            {
                if( empty($homeassistant['host']) )
                {
                    throw new \Exception('Trying to setup Home Assistant without host.');
                }

                if( empty($homeassistant['token']) )
                {
                    throw new \Exception('Trying to setup Home Assistant without token.');
                }

                $this->homeassistant = new HomeAssistantAPI($homeassistant['host'], $homeassistant['token']);
            }
        }

        /**
         * Setup MQTT connection.
         *
         * @param array $mqtt MQTT configuration.
         * @throws \Exception if required parameters are missing or connection fails.
         */
        private function setupMQTT($mqtt) 
        {
            if (!empty($mqtt)) 
            {
                if (empty($mqtt['host'])) 
                {
                    throw new \Exception('Trying to setup MQTT but host is missing.');
                }

                $this->mqtt = new \Bluerhinos\phpMQTT($mqtt['host'], $mqtt['port'] ?? 1883, uniqid());

                if (!$this->mqtt->connect(true, NULL, $mqtt['user'] ?? '', $mqtt['pass'] ?? '')) 
                {
                    throw new \Exception('Trying to setup MQTT failed while connecting.');
                }

                $this->mqtt_topic = $mqtt['topic'];
            }
        }

        /**
         * Setup Powerstream connection.
         *
         * @param array $ps Powerstream configuration.
         * @throws \Exception if required parameters are missing.
         */
        private function setupPowerstream($ps) 
        {
            if (!empty($ps)) 
            {
                if (empty($ps['serial'])) 
                {
                    throw new \Exception('Trying to setup Powerstream without serial.');
                }

                if (empty($ps['access_key'])) 
                {
                    throw new \Exception('Trying to setup Powerstream without access_key.');
                }

                if (empty($ps['secret_key'])) 
                {
                    throw new \Exception('Trying to setup Powerstream without secret_key.');
                }

                $this->ecoflow = new EcoFlowAPI($ps['access_key'], $ps['secret_key']);
                $this->ecoflow_ps_serial = $ps['serial'];
            }
        }

        /**
         * Setup P1-reader connection.
         *
         * @param array $p1 P1-reader configuration.
         * @throws \Exception if required parameters are missing.
         */
        private function setupP1($p1) 
        {
            if (!empty($p1)) 
            {
                if (empty($p1['host'])) 
                {
                    throw new \Exception('Trying to setup P1-reader without host.');
                }

                if (empty($p1['port'])) 
                {
                    throw new \Exception('Trying to setup P1-reader without port.');
                }

                $this->p1 = new P1Reader($p1['host'], $p1['port']);
            }
        }

        /**
         * Add a task to the task list.
         *
         * @param string $name The name of the task.
         * @param callable $callback The callback function to be executed.
         * @param int|null $interval The interval in seconds at which the task should be run.
         * @throws \Exception if task name or callback is missing.
         */
        public function addTask($name, $callback, $interval = null) 
        {
            if (empty($name)) 
            {
                throw new \Exception('Trying to add a task without a name.');
            }

            if (empty($callback)) 
            {
                throw new \Exception('Trying to add a task without a callback.');
            }

            if (isset($this->tasks[$name])) 
            {
                $originalName = $name;
                $i = 1;

                while (isset($this->tasks[$name])) 
                {
                    $name = $originalName . '_' . $i;
                    $i++;
                }
            }

            $this->tasks[$name] = ['callback' => $callback, 'interval' => $interval];
            $this->lastRunTimes[$name] = 0;
            $this->taskCounter[$name] = 0;
        }

        /**
         * Delete a task from the task list.
         *
         * @param string $name The name of the task to delete.
         * @return bool True if the task was successfully deleted, false otherwise.
         */
        public function deleteTask($name) 
        {
            if (isset($this->tasks[$name])) 
            {
                unset($this->tasks[$name]);
                unset($this->lastRunTimes[$name]);
                unset($this->taskCounter[$name]);

                return true;
            }

            return false;
        }

        /**
         * Delete the current running task from the task list.
         *
         * @return bool True if the task was successfully deleted, false otherwise.
         */
        public function deleteCurrentTask() 
        {
            return $this->deleteTask( $this->currentTaskName );
        }

        /**
         * Get the count of times a task has run.
         *
         * @return int|bool The count of task executions or false if task does not exist.
         */
        public function getTaskCount() 
        {
            return $this->taskCounter[$this->currentTaskName] ?? false;
        }

        /**
         * Run all tasks based on their intervals.
         */
        public function runTasks() 
        {
            $currentTime = time();

            foreach ($this->tasks as $name => $task) 
            {
                $callback = $task['callback'];
                $interval = $task['interval'];

                if ($interval === null || ($currentTime - $this->lastRunTimes[$name] >= $interval)) 
                {
                    $this->currentTaskName = $name;
                    $this->taskCounter[$name]++;

                    call_user_func($callback, $this);

                    $this->lastRunTimes[$name] = $currentTime;
                }
            }
        }
        
        /**
         * Run a specific task by its name.
         *
         * @param string $name The name of the task to run.
         * @throws \Exception if the task does not exist.
         */
        public function runTask($name) {
            if (!isset($this->tasks[$name])) {
                throw new \Exception("Task '$name' does not exist.");
            }

            $this->currentTaskName = $name;
            $callback = $this->tasks[$name]['callback'];
            $this->taskCounter[$name]++;

            // Execute the callback for the specified task
            call_user_func($callback, $this);

            // Update the last run time for the task
            $this->lastRunTimes[$name] = time();
        }

        /**
         * Initialize and continuously run tasks until no tasks remain.
         */
        public function init() 
        {
            while (!empty($this->tasks)) 
            {
                $this->runTasks();
                sleep(1);
            }
        }

        /**
         * Delegate the writeCache call to the tmCache instance.
         *
         * @param array $data The data to write to the cache.
         */
        public function writeCache(array $data) 
        {
            return $this->cache->writeCache($data);
        }

        /**
         * Delegate the applyCache call to the tmCache instance.
         */
        private function applyCache() 
        {
            return $this->cache->applyCache($this);
        }

        /**
         * Periodically write data to the cache.
         */
        public function updateCache() 
        {
            $data = [
                'cache' => [
                    'updated' => time(),
                ],
                'coulombs' => [
                    'coulombs' => $this->coulombs->getTotal(),
                ],
                'ecoflow' => [
                    'currentOutput' => $this->ecoflow->currentOutput,
                    'currentVoltage' => $this->ecoflow->currentVoltage,
                ],
            ];

            return $this->cache->writeCache($data);
        }

        /**
         * Beperkt een integer-waarde binnen een gespecificeerde range.
         *
         * @param int $value - De te controleren waarde.
         * @param int $min - De minimumwaarde van de range.
         * @param int $max - De maximumwaarde van de range.
         * @return int - De waarde beperkt binnen de range.
         */
        public function clampValue($value, $min, $max) 
        {
            if ($value < $min) {
                return $min;
            } elseif ($value > $max) {
                return $max;
            } else {
                return $value;
            }
        }
    }
