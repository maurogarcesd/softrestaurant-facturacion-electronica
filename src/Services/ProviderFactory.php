<?php

namespace App\Services;

class ProviderFactory {

    /**
     * Obtiene el proveedor activo.
     * Prioridad: app_state.json (UI) > .env (configuración base)
     */
    public static function getProvider(): string {
        $stateFile = __DIR__ . '/../../storage/app_state.json';
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            if (!empty($state['billing_provider'])) {
                return $state['billing_provider'];
            }
        }
        return $_ENV['BILLING_PROVIDER'] ?? 'factus';
    }

    public static function getAuth() {
        if (self::getProvider() === 'datainvoice') {
            return new DataInvoiceAuth();
        }
        return new FactusAuth();
    }

    public static function getMapper() {
        if (self::getProvider() === 'datainvoice') {
            return new DataInvoiceMapper();
        }
        return new FactusMapper();
    }
}
