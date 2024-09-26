<?php
    namespace tmPower;

    class tmCache {
        private $cacheFile;

        /**
         * Constructor to initialize the cache file location.
         *
         * @param string $cacheFile The path to the cache file.
         */
        public function __construct($cacheFile = 'cache.json') {
            $this->cacheFile = $cacheFile;
        }

        /**
         * Write data to the cache file.
         *
         * @param array $data The data to write to the cache.
         * @throws \Exception if there is an error reading or writing the cache file.
         */
        public function writeCache(array $data) {
            // Step 1: Read the existing cache from cache.json
            if (file_exists($this->cacheFile)) {
                $cacheContent = file_get_contents($this->cacheFile);
                $cache = json_decode($cacheContent, true);
                if ($cache === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Error decoding JSON from cache file.');
                }
            } else {
                $cache = [];
            }

            // Step 2: Merge the provided data into the existing cache
            $cache = $this->arrayMergeRecursive($cache, $data);

            // Step 3: Write the updated cache back to cache.json
            $newCacheContent = json_encode($cache, JSON_PRETTY_PRINT);
            if (file_put_contents($this->cacheFile, $newCacheContent) === false) {
                throw new \Exception('Error writing to cache file.');
            }
        }

        /**
         * Read the cache from the cache file and apply it to the provided class instance.
         *
         * @param object $classInstance The instance of the class to apply data to.
         * @throws \Exception if there is an error reading or decoding the cache file.
         */
        public function applyCache($classInstance) {
            if (file_exists($this->cacheFile)) {
                $cacheContent = file_get_contents($this->cacheFile);
                $cache = json_decode($cacheContent, true);
                if ($cache === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Error decoding JSON from cache file.');
                }

                // Apply the cache data to the class instance
                $this->applyDataToClass($cache, $classInstance);
            }
        }

        /**
         * Recursively apply data to a class and its properties.
         *
         * @param array $data The data to apply.
         * @param object $classInstance The instance of the class to apply data to.
         */
        private function applyDataToClass(array $data, $classInstance) {
            foreach ($data as $key => $value) {
                if (property_exists($classInstance, $key)) {
                    if (is_array($value) && is_object($classInstance->$key)) {
                        // Recursively apply to subclasses if the property is an object and the value is an array
                        $this->applyDataToClass($value, $classInstance->$key);
                    } else {
                        // Apply the value to the property
                        $classInstance->$key = $value;
                    }
                }
            }
        }

        /**
         * Recursively merge two arrays.
         *
         * @param array $array1 The original array.
         * @param array $array2 The array to merge into the original array.
         * @return array The merged array.
         */
        private function arrayMergeRecursive(array $array1, array $array2) {
            foreach ($array2 as $key => $value) {
                if (isset($array1[$key]) && is_array($array1[$key]) && is_array($value)) {
                    $array1[$key] = $this->arrayMergeRecursive($array1[$key], $value);
                } else {
                    $array1[$key] = $value;
                }
            }
            return $array1;
        }
    }
