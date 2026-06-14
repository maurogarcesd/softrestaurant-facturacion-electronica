<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class FactusAuth {
    private Client $client;
    private string $token = '';
    private int $expiresAt = 0;

    public function __construct() {
        $this->client = new Client([
            'base_uri' => $_ENV['FACTUS_API_URL'] . '/',
            'timeout'  => 10.0,
        ]);
    }

    public function getToken(): string {
        // Simple cache in memory
        if ($this->token && time() < $this->expiresAt) {
            return $this->token;
        }

        return $this->authenticate();
    }

    private function authenticate(): string {
        try {
            $response = $this->client->post('oauth/token', [
                'form_params' => [
                    'grant_type'    => 'password',
                    'client_id'     => $_ENV['FACTUS_CLIENT_ID'],
                    'client_secret' => $_ENV['FACTUS_CLIENT_SECRET'],
                    'username'      => $_ENV['FACTUS_EMAIL'],
                    'password'      => $_ENV['FACTUS_PASSWORD'],
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['access_token'])) {
                $this->token = $data['access_token'];
                // Usually expires in 3600 seconds (1 hour). We buffer by 5 minutes.
                $expiresIn = $data['expires_in'] ?? 3600;
                $this->expiresAt = time() + $expiresIn - 300; 
                
                return $this->token;
            }

            throw new \Exception("No se recibió el token de acceso: " . json_encode($data));
            
        } catch (GuzzleException $e) {
            die("Error de autenticación Factus: " . $e->getMessage());
        }
    }
}
