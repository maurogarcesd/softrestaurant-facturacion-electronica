<?php

namespace App;

use App\Config\Database;
use App\Services\ProviderFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Processor
{
    private $auth;
    private $apiClient;

    public function __construct()
    {
        // No inicializamos aquí para permitir cambios de proveedor en caliente
    }

    private function initProvider()
    {
        $this->auth = ProviderFactory::getAuth();
        $provider = ProviderFactory::getProvider();
        $baseUri = $provider === 'datainvoice' ? $_ENV['DATAINVOICE_API_URL'] : $_ENV['FACTUS_API_URL'];

        $this->apiClient = new Client([
            'base_uri' => $baseUri . '/',
            'timeout'  => 30,
        ]);

        return $provider;
    }

    public function run()
    {
        $provider = $this->initProvider();
        echo "Iniciando Processor {$provider}...\n";

        // Check global app state
        $stateFile = __DIR__ . '/../storage/app_state.json';
        $delayMinutes = 0;
        if (file_exists($stateFile)) {
            $stateData = json_decode(file_get_contents($stateFile), true);
            if (isset($stateData['status']) && $stateData['status'] === 'PAUSED') {
                echo "La cola está PAUSADA. Saliendo del Processor.\n";
                return;
            }
            if (isset($stateData['delay_minutes'])) {
                $delayMinutes = (int) $stateData['delay_minutes'];
            }
        }

        $db = new Database();
        $mysql = $db->getMysqlConnection();

        // Obtener facturas pendientes, o errores que el usuario haya editado (que pasan a EN_COLA)
        if ($delayMinutes > 0) {
            echo "Retraso de cola activado: {$delayMinutes} minutos.\n";
            $stmt = $mysql->query("SELECT * FROM integracion_facturacion WHERE estado IN ('PENDIENTE', 'EN_COLA') AND TIMESTAMPDIFF(MINUTE, creado_en, NOW()) >= {$delayMinutes} ORDER BY id ASC LIMIT 50");
        } else {
            $stmt = $mysql->query("SELECT * FROM integracion_facturacion WHERE estado IN ('PENDIENTE', 'EN_COLA') ORDER BY id ASC LIMIT 50");
        }
        
        $facturas = $stmt->fetchAll();

        if (empty($facturas)) {
            echo "No hay facturas en la cola para procesar.\n";
            return;
        }

        $token = $this->auth->getToken();

        // ── Cargar patrones y configuración desde .env ───────────────
        $pattern = $_ENV['EMISSION_PATTERN'] ?? 'ALL';
        $uvtValue = (float)($_ENV['VALOR_UVT'] ?? 47065);
        $uvtLimit = $uvtValue * 5;

        foreach ($facturas as $factura) {
            $id = $factura['id'];
            $idFacturaSR = $factura['id_factura_sr'];
            $payload = json_decode($factura['json_payload'], true);

            if (!$payload) {
                echo "❌ Factura $idFacturaSR no tiene un payload JSON válido. Marcando como ERROR.\n";
                $mysql->exec("UPDATE integracion_facturacion SET estado = 'ERROR', ultimo_error = 'Payload JSON inválido' WHERE id = $id");
                continue;
            }

            echo "Procesando folio SR: $idFacturaSR...\n";

            // ── Lógica de Patrón de Emisión (Throttling) ─────────────────────
            $shouldSend = true;
            
            // Determinar si es consumidor final
            $isConsumidorFinal = false;
            if ($provider === 'datainvoice') {
                $isConsumidorFinal = (($payload['customer']['identification_number'] ?? '') === '222222222222');
            } else {
                $isConsumidorFinal = (($payload['customer']['identification'] ?? '') === '222222222222');
            }

            // El patrón solo aplica a "Consumidor Final". Si tiene datos reales, SIEMPRE se envía.
            if ($pattern !== 'ALL' && $isConsumidorFinal) {
                $stmtCount = $mysql->query("SELECT COUNT(*) FROM integracion_facturacion WHERE estado IN ('ENVIADO', 'OMITIDA')");
                $totalProcessed = (int) $stmtCount->fetchColumn();

                if ($pattern === '1_OF_2' && ($totalProcessed % 2 !== 0)) $shouldSend = false;
                elseif ($pattern === '1_OF_3' && ($totalProcessed % 3 !== 0)) $shouldSend = false;
                elseif ($pattern === 'RANDOM_50' && (rand(1, 100) > 50)) $shouldSend = false;
            }

            if (!$shouldSend) {
                echo "⏭️ Factura $idFacturaSR omitida por patrón de emisión ($pattern).\n";
                $mysql->exec("UPDATE integracion_facturacion SET estado = 'OMITIDA', ultimo_error = 'Omitida por patrón de emisión ($pattern)' WHERE id = $id");
                continue;
            }

            // ── Validación de 5 UVT ──────────────────────────────────────────
            $valorUVT = isset($_ENV['VALOR_UVT']) ? (float)$_ENV['VALOR_UVT'] : 47065;
            $uvtLimit = $valorUVT * 5;

            $totalPayable = (float)($payload['legal_monetary_totals']['payable_amount'] ?? $payload['total'] ?? 0);
            
            if ($totalPayable >= $uvtLimit && $isConsumidorFinal) {
                echo "⚠️ Factura $idFacturaSR bloqueada: supera 5 UVT y es Consumidor Final.\n";
                $msg = "🚫 LÍMITE LEGAL EXCEDIDO (5 UVT): Esta factura supera los $" . number_format($uvtLimit, 0) . " y no puede enviarse a 'Consumidor Final'. Por favor, identifique al cliente en el Dashboard.";
                $mysql->exec("UPDATE integracion_facturacion SET estado = 'ERROR', ultimo_error = '$msg' WHERE id = $id");
                continue;
            }

            try {
                $endpoint = $provider === 'datainvoice' ? 'invoice' : 'v2/bills/validate';
                
                $response = $this->apiClient->post($endpoint, [
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'Accept'        => 'application/json',
                    ],
                    'json' => $payload
                ]);

                $result = json_decode($response->getBody()->getContents(), true);
                $apiResponseStr = json_encode($result, JSON_UNESCAPED_UNICODE);
                
                // Procesar respuesta según el proveedor
                if ($provider === 'datainvoice') {
                    $isSuccess = (($result['success'] ?? false) === true);
                    
                    if ($isSuccess) {
                        // Extraer el número generado
                        $factusId = $result['number'] ?? $result['data']['number'] ?? null;
                        if (!$factusId && isset($result['urlinvoicepdf'])) {
                            $parts = explode('-', str_replace('.pdf', '', $result['urlinvoicepdf']));
                            $factusId = end($parts);
                        }
                        if (!$factusId) $factusId = $payload['number'] ?? 'OK';

                        // Limpiar campos pesados de XML
                        unset($result['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult']['XmlBase64Bytes']);
                        unset($result['invoicexml'], $result['attacheddocument'], $result['zipinvoicexml']);
                        $apiResponseStr = json_encode($result, JSON_UNESCAPED_UNICODE);
                    } else {
                        $errorMsg = $result['message'] ?? 'Error desconocido en DataInvoice';
                        throw new \Exception($errorMsg);
                    }
                } else {
                    $isSuccess = ($response->getStatusCode() === 200 || $response->getStatusCode() === 201);
                    
                    if ($isSuccess) {
                        // Extracción robusta del número de factura para Factus V2
                        $factusId = $result['data']['bill']['number'] ?? $result['data']['number'] ?? $result['number'] ?? 'OK';
                        
                        // Buscar el flag is_validated (puede venir en distintos niveles según V1 o V2)
                        $isValidated = true;
                        if (isset($result['data']['bill']['is_validated'])) {
                            $isValidated = $result['data']['bill']['is_validated'];
                        } elseif (isset($result['data']['is_validated'])) {
                            $isValidated = $result['data']['is_validated'];
                        } elseif (isset($result['is_validated'])) {
                            $isValidated = $result['is_validated'];
                        }

                        // Verificar campo errors para diferenciar notificaciones de rechazos reales
                        $errorsObj = $result['data']['bill']['errors'] ?? $result['data']['errors'] ?? $result['errors'] ?? null;
                        $errorsStr = is_array($errorsObj) ? json_encode($errorsObj, JSON_UNESCAPED_UNICODE) : (string)$errorsObj;
                        
                        $hasRechazo = stripos($errorsStr, 'rechazo') !== false;

                        if (!$isValidated && !$hasRechazo) {
                            // Demora de la DIAN: HTTP 200 OK, pero no validada y sin rechazo explícito
                            throw new \Exception("DIAN_TIMEOUT|" . json_encode($result, JSON_UNESCAPED_UNICODE));
                        } elseif (!$isValidated && $hasRechazo) {
                            // Rechazo directo devuelto como HTTP 200
                            throw new \Exception("RECHAZO_DIAN|" . $errorsStr);
                        }
                    } else {
                        throw new \Exception(json_encode($result, JSON_UNESCAPED_UNICODE));
                    }
                }

                $stmtUpdate = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'ENVIADO', factus_invoice_id = ?, api_response = ? WHERE id = ?");
                $stmtUpdate->execute([$factusId, $apiResponseStr, $id]);

                // --- APRENDIZAJE: Guardar cliente en base de datos local ---
                $idClie = $payload['customer']['identification'] ?? $payload['customer']['identification_number'] ?? '';
                $names  = $payload['customer']['names'] ?? $payload['customer']['name'] ?? '';
                $email  = $payload['customer']['email'] ?? '';
                $docType = $payload['customer']['identification_document_id'] ?? $payload['customer']['type_document_identification_id'] ?? 3;
                
                if ($idClie !== '222222222222' && !empty($idClie)) {
                    $stmtIns = $mysql->prepare("
                        INSERT INTO clientes_locales (identification, names, email, identification_document_id)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE names = VALUES(names), email = VALUES(email), identification_document_id = VALUES(identification_document_id)
                    ");
                    $stmtIns->execute([$idClie, $names, $email, $docType]);
                }
                // -----------------------------------------------------------

                echo "✅ Factura $idFacturaSR enviada con éxito.\n";

            } catch (RequestException $e) {
                $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
                echo "❌ Error de validación en factura $idFacturaSR: " . $responseBody . "\n";
                
                $stmtError = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'ERROR', ultimo_error = ?, api_response = ?, intentos = intentos + 1 WHERE id = ?");
                $stmtError->execute([substr($responseBody, 0, 1000), $responseBody, $id]);
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                if (str_starts_with($msg, 'DIAN_TIMEOUT|')) {
                    $apiResponseStr = substr($msg, 13);
                    echo "⏳ Demora en la DIAN detectada para factura $idFacturaSR. Queda EN_COLA para reintento automático.\n";
                    $stmtError = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'EN_COLA', ultimo_error = 'DIAN_TIMEOUT: Demora en validación DIAN. Reintentando...', api_response = ?, intentos = intentos + 1 WHERE id = ?");
                    $stmtError->execute([$apiResponseStr, $id]);
                } elseif (str_starts_with($msg, 'RECHAZO_DIAN|')) {
                    $errorDetails = substr($msg, 13);
                    echo "❌ Rechazo explícito de DIAN en factura $idFacturaSR: " . $errorDetails . "\n";
                    $stmtError = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'ERROR', ultimo_error = ?, intentos = intentos + 1 WHERE id = ?");
                    $stmtError->execute([substr("Rechazo DIAN: " . $errorDetails, 0, 1000), $id]);
                } else {
                    echo "❌ Error general en factura $idFacturaSR: " . $msg . "\n";
                    $stmtError = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'ERROR', ultimo_error = ?, intentos = intentos + 1 WHERE id = ?");
                    $stmtError->execute([substr($msg, 0, 1000), $id]);
                }
            }
        }

        echo "Processor finalizado.\n";
    }
}
