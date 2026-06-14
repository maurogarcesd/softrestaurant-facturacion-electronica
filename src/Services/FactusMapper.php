<?php

namespace App\Services;

class FactusMapper {

    /**
     * Transforma una factura de SoftRestaurant al array de Factus
     */
    public function mapInvoice(array $facturaSR, array $detallesSR, array $clienteSR): array {
        
        $items = [];
        
        foreach ($detallesSR as $detalle) {
            $cantidad = (float)($detalle['cantidad'] ?? 1);
            $precioTotalConImpuesto = (float)($detalle['precio'] ?? 0);
            $taxRate = (float) ($_ENV['DEFAULT_TAX_RATE'] ?? 8.00);
            $taxDivisor = 1 + ($taxRate / 100);
            
            $precioBase = $precioTotalConImpuesto / $taxDivisor;
            $valorImpuesto = $precioTotalConImpuesto - $precioBase;

            $taxIdFactus = (int) ($_ENV['FACTUS_TAX_ID'] ?? 5); // 5 = INC
            $taxCodeFactus = $_ENV['FACTUS_TAX_CODE'] ?? "04"; // 04 = INC
            $taxNameFactus = $_ENV['FACTUS_TAX_NAME'] ?? "INC";

            // Calculo base desglosando el impuesto
            $items[] = [
                "code_reference"   => $detalle['idproducto'] ?? '001',
                "name"             => $detalle['descripcion'] ?? 'Consumo',
                "quantity"         => $cantidad,
                "discount_rate"    => "0.00",
                "price"            => number_format($precioBase, 2, '.', ''),
                "tax_rate"         => number_format($taxRate, 2, '.', ''),
                "unit_measure_code"  => $_ENV['FACTUS_UNIT_MEASURE_CODE'] ?? "94",  // "94" = Unidad por defecto en Factus
                "standard_code"      => "999", // "999" = Otros
                "is_excluded"        => 0,
                "tribute_id"       => $taxIdFactus,
                "taxes" => [
                    [
                        "id"     => $taxIdFactus,
                        "code"   => $taxCodeFactus,
                        "name"   => $taxNameFactus,
                        "rate"   => number_format($taxRate, 2, '.', ''),
                        "amount" => number_format($valorImpuesto * $cantidad, 2, '.', ''),
                        "base"   => number_format($precioBase * $cantidad, 2, '.', '')
                    ]
                ]
            ];
        }

        // --- Lógica de Tope de Consumidor Final (5 UVT = $235,325 COP en 2024) ---
        // Se calculan pero la excepción se lanzará en el Watcher para poder guardar el JSON y permitir edición.
        $totalVenta = (float) $facturaSR['total'];
        $identificacion = $clienteSR['rfc'] ?? '222222222222';
        
        if (empty(trim($identificacion))) {
            $identificacion = '222222222222';
        }

        // Determinar método de pago. Si la factura dice "cancelada" = 0, asumimos contado.
        $paymentMethodCode = "10"; // 10 = Efectivo (estándar DIAN)
        
        // Formatear el número de ticket real que ve el cliente (Ej: A-9 o solo 9)
        $serie = trim($facturaSR['seriefolio'] ?? '');
        $numCheque = trim($facturaSR['numcheque'] ?? '');
        $numeroReal = $serie !== '' ? "{$serie}-{$numCheque}" : ($numCheque !== '' ? $numCheque : (string) $facturaSR['folio']);

        // Mapeo Principal
        return [
            "numbering_range_id" => 389, // ID del rango de numeración validado para Sandbox
            "reference_code" => $numeroReal,
            "type_document_id" => 1, // 1 = Factura Electrónica
            "date" => date('Y-m-d', strtotime($facturaSR['fecha'])),
            "time" => date('H:i:s', strtotime($facturaSR['fecha'])),
            "customer" => [
                "identification" => $identificacion,
                "names" => $clienteSR['nombre'] ?? 'Consumidor Final',
                "email" => $clienteSR['email'] ?? 'facturacion@restaurante.com',
                "phone" => $clienteSR['telefono1'] ?? '0000000000',
                "address" => $clienteSR['direccion'] ?? 'Ciudad',
                "legal_organization_id" => 2, // 2 = Persona Natural
                "tribute_id" => 21, // 21 = IVA (Genérico para clientes)
                "identification_document_id" => 1, // 1 = Cédula de ciudadanía (ID interno)
                "identification_document_code" => "13", // 13 = Cédula de ciudadanía (Código DIAN)
            ],
            "payment_form" => "1", // 1 = Contado
            "payment_method_code" => "10", // 10 = Efectivo
            "payment_details" => [
                [
                    "payment_method_code" => "10",
                    "payment_form" => "1",
                    "amount" => number_format($totalVenta, 2, '.', '')
                ]
            ],
            "items" => $items
        ];
    }
}
