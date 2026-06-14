<?php

namespace App\Services;

class DataInvoiceMapper {

    /**
     * Calcula el dígito de verificación de un NIT colombiano.
     */
    private function calcularDV(string $nit): int {
        $nit    = preg_replace('/[^0-9]/', '', $nit);
        $primos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $j      = 0;
        $factor = 0;
        for ($i = strlen($nit) - 1; $i >= 0; $i--) {
            $factor += ((int) $nit[$i]) * $primos[$j++];
        }
        $residuo = $factor % 11;
        return $residuo > 1 ? 11 - $residuo : $residuo;
    }

    /**
     * Transforma una factura de SoftRestaurant al array JSON de DataInvoice.
     */
    public function mapInvoice(array $facturaSR, array $detallesSR, array $clienteSR): array {

        // ── Totales ────────────────────────────────────────────────────────
        $totalVenta     = (float) ($facturaSR['total'] ?? 0);
        $totalBase      = 0.0;
        $totalImpuestos = 0.0;
        $items          = [];

        foreach ($detallesSR as $detalle) {
            $cantidad               = (float) ($detalle['cantidad'] ?? 1);
            $precioTotalConImpuesto = (float) ($detalle['precio']   ?? 0);

            // Calcular base desglosando el impuesto según la tasa configurada
            $taxRate = (float) ($_ENV['DEFAULT_TAX_RATE'] ?? 8.00);
            $taxDivisor = 1 + ($taxRate / 100);
            
            $precioBase    = round($precioTotalConImpuesto / $taxDivisor, 2);
            $valorImpuesto = round($precioTotalConImpuesto - $precioBase, 2);

            $totalBase      += round($precioBase    * $cantidad, 2);
            $totalImpuestos += round($valorImpuesto * $cantidad, 2);

            $unitMeasureId = (int) ($_ENV['DATAINVOICE_UNIT_MEASURE_ID'] ?? 94);
            $taxIdDI = (int) ($_ENV['DATAINVOICE_TAX_ID'] ?? 4);

            $items[] = [
                'unit_measure_id'             => $unitMeasureId,
                'invoiced_quantity'           => $cantidad,
                'line_extension_amount'       => number_format($precioBase * $cantidad, 2, '.', ''),
                'free_of_charge_indicator'    => false,
                'tax_totals'                  => [
                    [
                        'tax_id'         => $taxIdDI,
                        'tax_amount'     => number_format($valorImpuesto * $cantidad, 2, '.', ''),
                        'percent'        => number_format($taxRate, 2, '.', ''),
                        'taxable_amount' => number_format($precioBase * $cantidad, 2, '.', ''),
                    ],
                ],
                'description'                 => mb_substr($detalle['descripcion'] ?? 'Consumo', 0, 300),
                'code'                        => (string) ($detalle['idproducto'] ?? '001'),
                'type_item_identification_id' => 4,    // 4 = Estándar de adopción del contribuyente
                'price_amount'                => number_format($precioTotalConImpuesto, 2, '.', ''),
                'base_quantity'               => 1,
            ];
        }

        // ── Número de factura dentro del rango de resolución ───────────────
        $numCheque = (int) ($facturaSR['numcheque'] ?? $facturaSR['folio'] ?? 1);
        $rangoBase = (int) ($_ENV['DATAINVOICE_NUMBER_FROM'] ?? 990005000);
        $rangoTope = (int) ($_ENV['DATAINVOICE_NUMBER_TO']   ?? 995000000);
        $numeroFactura = min($rangoBase + $numCheque - 1, $rangoTope);

        // ── Prefijo y resolución ───────────────────────────────────────────
        $serie            = trim($facturaSR['seriefolio'] ?? '');
        $resolutionNumber = $_ENV['DATAINVOICE_RESOLUTION_NUMBER'] ?? '18760000001';
        $prefix           = $serie !== '' ? $serie : ($_ENV['DATAINVOICE_PREFIX'] ?? 'SETP');

        // ── Datos del establecimiento (emisor) ─────────────────────────────
        $estName         = $_ENV['DATAINVOICE_ESTABLISHMENT_NAME']         ?? 'Restaurante';
        $estAddress      = $_ENV['DATAINVOICE_ESTABLISHMENT_ADDRESS']      ?? '';
        $estPhone        = $_ENV['DATAINVOICE_ESTABLISHMENT_PHONE']        ?? '';
        $estEmail        = $_ENV['DATAINVOICE_ESTABLISHMENT_EMAIL']        ?? '';
        $estMunicipality = (int) ($_ENV['DATAINVOICE_ESTABLISHMENT_MUNICIPALITY'] ?? 320);

        // ── Datos del cliente ──────────────────────────────────────────────
        $identificacion = preg_replace('/[^0-9]/', '',
            $clienteSR['rfc'] ?? $clienteSR['nit'] ?? $clienteSR['identificacion'] ?? ''
        );
        if (empty($identificacion)) {
            $identificacion = '222222222222'; // Consumidor Final
        }

        $municipalityId = (int) ($_ENV['DATAINVOICE_MUNICIPALITY_ID'] ?? 320);
        $emailFallback  = $_ENV['DATAINVOICE_DEFAULT_EMAIL'] ?? $estEmail;

        // NIT = exactamente 9 dígitos numéricos → Persona Jurídica
        $isNIT     = strlen($identificacion) === 9 && is_numeric($identificacion);
        $isFinal   = $identificacion === '222222222222';

        // type_document_identification_id: 3=CC, 6=NIT, 7=Pasaporte, 13=Cédula extranjería
        $tipoDocCliente = $isFinal ? 3 : ($isNIT ? 6 : 3);

        $customerData = [
            'identification_number'           => $identificacion,
            'name'                            => mb_substr($clienteSR['nombre'] ?? 'CONSUMIDOR FINAL', 0, 200),
            'phone'                           => $clienteSR['telefono1'] ?? $estPhone,
            'address'                         => $clienteSR['direccion'] ?? $estAddress,
            'email'                           => !empty($clienteSR['email']) ? $clienteSR['email'] : $emailFallback,
            'merchant_registration'           => $_ENV['MERCHANT_REGISTRATION'] ?? '0000000',
            'type_document_identification_id' => $tipoDocCliente,
            'type_organization_id'            => $isNIT ? 1 : 2, // 1=Jurídica, 2=Natural
            'municipality_id'                 => $municipalityId,
            'type_regime_id'                  => (int) ($_ENV['DATAINVOICE_REGIME_ID'] ?? 2),  // 2 = No responsable de IVA
            'type_liability_id'               => (int) ($_ENV['DATAINVOICE_LIABILITY_ID'] ?? 7),  // 7 = No responsable de IVA
            'tax_id'                          => (int) ($_ENV['DATAINVOICE_TAX_ID'] ?? 4),
        ];

        // Para NITs se debe enviar también el dígito de verificación
        if ($isNIT && !$isFinal) {
            $customerData['dv'] = $this->calcularDV($identificacion);
        }

        // ── Payload final ──────────────────────────────────────────────────
        return [
            // Cabecera
            'type_document_id'             => 1,   // 1 = Factura Electrónica de Venta
            'number'                       => (string) $numeroFactura,
            'date'                         => date('Y-m-d', strtotime($facturaSR['fecha'])),
            'time'                         => date('H:i:s', strtotime($facturaSR['fecha'])),
            'resolution_number'            => $resolutionNumber,
            'prefix'                       => $prefix,
            'sendmail'                     => filter_var($_ENV['DATAINVOICE_SEND_MAIL'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'sendmailtome'                 => filter_var($_ENV['DATAINVOICE_SEND_MAIL_TO_ME'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'foot_note'                    => 'Gracias por su preferencia — ' . $estName,

            // Establecimiento (emisor visible en la factura PDF)
            'establishment_name'           => $estName,
            'establishment_address'        => $estAddress,
            'establishment_phone'          => $estPhone,
            'establishment_email'          => $estEmail,
            'establishment_municipality'   => $estMunicipality,

            // Cliente
            'customer'                     => $customerData,

            // Forma de pago — DataInvoice requiere array (conjunto)
            'payment_form' => [
                [
                    'payment_form_id'   => 1,   // 1 = Contado
                    'payment_method_id' => 10,  // 10 = Efectivo
                    'payment_due_date'  => date('Y-m-d', strtotime($facturaSR['fecha'])),
                    'duration_measure'  => 0,
                ],
            ],

            // Totales monetarios
            'legal_monetary_totals' => [
                'line_extension_amount' => number_format($totalBase, 2, '.', ''),
                'tax_exclusive_amount'  => number_format($totalBase, 2, '.', ''),
                'tax_inclusive_amount'  => number_format($totalVenta, 2, '.', ''),
                'payable_amount'        => number_format($totalVenta, 2, '.', ''),
            ],

            // Impuestos globales
            'tax_totals' => [
                [
                    'tax_id'         => (int) ($_ENV['DATAINVOICE_TAX_ID'] ?? 4),
                    'tax_amount'     => number_format($totalImpuestos, 2, '.', ''),
                    'percent'        => number_format((float) ($_ENV['DEFAULT_TAX_RATE'] ?? 8.00), 2, '.', ''),
                    'taxable_amount' => number_format($totalBase, 2, '.', ''),
                ],
            ],

            // Líneas de detalle
            'invoice_lines' => $items,
        ];
    }
}
