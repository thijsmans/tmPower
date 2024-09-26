<?php
    namespace tmPower;

    class EneverV2
    {
        private $token; // API token for authentication
        private $apiUrlToday = "https://enever.nl/api/stroomprijs_vandaag.php?token="; // URL for today's prices
        private $apiUrlTomorrow = "https://enever.nl/api/stroomprijs_morgen.php?token="; // URL for tomorrow's prices
        private $prices = []; // Cached prices data
        private $supplier; // Supplier identifier

        /**
         * Constructor to initialize the object with the API token and supplier.
         * 
         * @param string $token API token for authentication.
         * @param string $supplier Supplier identifier, default is 'ZP' (Zonneplan).
         */
        public function __construct($token, $supplier = 'ZP') 
        {
            $this->token = $token;
            $this->supplier = $supplier;
        }

        /**
         * Retrieves energy price data from the API.
         * 
         * @param bool $forceUpdate Whether to force a refresh of the cached data.
         * @return array Returns an associative array of energy prices by date.
         * @throws \Exception If there's an issue fetching data or if the API returns an error status.
         */
        public function getData($forceUpdate = false)
        {
            // Return cached prices if available and update is not forced
            if (!empty($this->prices) && !$forceUpdate) {
                return $this->prices;
            }

            // URLs to fetch data from (today and tomorrow)
            $urls = [/*$this->apiUrlToday, */$this->apiUrlTomorrow];
            $prices = []; // Initialize prices array

            // Loop through each URL to fetch and process data
            foreach ($urls as $url) {
                $response = @file_get_contents($url . $this->token); // Suppress errors and fetch API data

                // Check if fetching data was successful
                if ($response === false) {
                    throw new \Exception("Error fetching data from API");
                }

                // Decode the JSON response into an associative array
                $json = json_decode($response, true);

                // Validate the status from the API response
                if (!isset($json['status']) || $json['status'] !== "true") {
                    throw new \Exception("API returned an error status");
                }

                // Construct the supplier-specific price key
                $key = 'prijs' . $this->supplier;

                // Process and store the price data for each date
                foreach ($json['data'] as $data) {
                    if (isset($data[$key])) {
                        $prices[$data['datum']] = $data[$key];
                    }
                }
            }

            // Cache the fetched prices
            $this->prices = $prices;

            // Return the price data
            return $this->prices;
        }
    }
