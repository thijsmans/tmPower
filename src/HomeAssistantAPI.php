<?php
    namespace tmPower;

    class HomeAssistantAPI {
        private $baseUrl;
        private $token;

        public function __construct($baseUrl, $token) {
            $this->baseUrl = rtrim($baseUrl, '/');
            $this->token = $token;
        }

        private function makeRequest($endpoint, $method = 'GET', $data = null) {
            $url = $this->baseUrl . $endpoint;
            $ch = curl_init($url);

            $headers = [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json'
            ];

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($method === 'POST' || $method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new \Exception('Curl error: ' . $error);
            }

            curl_close($ch);

            $decodedResponse = json_decode($response, true);

            if ($httpCode >= 400) {
                throw new \Exception('API error: ' . ($decodedResponse['message'] ?? 'Unknown error'));
            }

            return $decodedResponse;
        }

        public function getEntityState($entityId) {
            $endpoint = '/api/states/' . urlencode($entityId);
            return $this->makeRequest($endpoint);
        }

        public function setEntityState($entityId, $state, $attributes = []) {
            $endpoint = '/api/states/' . urlencode($entityId);
            $data = [
                'state' => $state,
                'attributes' => $attributes
            ];
            return $this->makeRequest($endpoint, 'POST', $data);
        }
    }
