<?php

namespace App\Services;

class DataInvoiceAuth {
    private string $token = '';

    public function __construct() {
        $this->token = $_ENV['DATAINVOICE_TOKEN'] ?? '';
    }

    public function getToken(): string {
        if (!$this->token || $this->token === 'tus_token_aqui') {
            throw new \Exception("Token de DataInvoice no configurado en el archivo .env");
        }
        return $this->token;
    }
}
