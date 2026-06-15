<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Database;
use App\Services\ProviderFactory;
use GuzzleHttp\Client;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

ob_clean();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$stateFile = __DIR__ . '/../storage/app_state.json';

try {
    $db = new Database();
    $mysql = $db->getMysqlConnection();

    /**
     * Helper para actualizar variables en el archivo .env sin romper nada.
     */
    function update_env_file($data) {
        $envPath = __DIR__ . '/../.env';
        if (!file_exists($envPath)) return false;
        
        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        foreach ($data as $key => $value) {
            $found = false;
            foreach ($lines as $i => $line) {
                // Buscar si la llave ya existe (ej: MI_LLAVE=valor)
                if (str_starts_with(trim($line), $key . '=')) {
                    $lines[$i] = "{$key}=\"{$value}\"";
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $lines[] = "{$key}=\"{$value}\"";
            }
        }
        return file_put_contents($envPath, implode("\n", $lines)) !== false;
    }

    /**
     * Guarda o actualiza un cliente en la tabla local
     */
    function upsert_local_customer($mysql, $identification, $names, $email, $docType) {
        if ($identification === '222222222222' || empty($identification)) return;
        
        $stmt = $mysql->prepare("
            INSERT INTO clientes_locales (identification, names, email, identification_document_id)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE names = VALUES(names), email = VALUES(email), identification_document_id = VALUES(identification_document_id)
        ");
        $stmt->execute([$identification, $names, $email, $docType]);
    }

    switch ($action) {
        case 'list':
            $stmt = $mysql->query("SELECT * FROM integracion_facturacion ORDER BY id DESC LIMIT 100");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $data]);
            break;

        case 'init_db':
            $sql = "CREATE TABLE IF NOT EXISTS clientes_locales (
                identification VARCHAR(20) PRIMARY KEY,
                names VARCHAR(255),
                email VARCHAR(255),
                identification_document_id INT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $mysql->exec($sql);
            echo json_encode(['status' => 'success', 'message' => 'Tabla clientes_locales creada exitosamente.']);
            break;

        case 'stats':
            $stmt = $mysql->query("SELECT COUNT(*) as facturas_mes, COALESCE(SUM(total_sr), 0) as total_mes FROM integracion_facturacion WHERE estado = 'ENVIADO' AND MONTH(creado_en) = MONTH(CURRENT_DATE()) AND YEAR(creado_en) = YEAR(CURRENT_DATE())");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $data]);
            break;


        case 'status':
            if (file_exists($stateFile)) {
                $stateData = json_decode(file_get_contents($stateFile), true);
                if (!isset($stateData['delay_minutes'])) $stateData['delay_minutes'] = 0;
                // Incluir proveedor activo en la respuesta de status
                $stateData['billing_provider'] = ProviderFactory::getProvider();
                $stateData['alert_threshold'] = $_ENV['ALERT_THRESHOLD'] ?? 5;
                echo json_encode($stateData);
            } else {
                echo json_encode([
                    'status' => 'PLAYING',
                    'delay_minutes' => 0,
                    'billing_provider' => ProviderFactory::getProvider(),
                    'alert_threshold' => $_ENV['ALERT_THRESHOLD'] ?? 5
                ]);
            }
            break;

        case 'set_provider':
            $data = json_decode(file_get_contents("php://input"), true);
            $newProvider = $data['provider'] ?? 'factus';
            if (!in_array($newProvider, ['factus', 'datainvoice'])) {
                throw new Exception("Proveedor inválido. Use 'factus' o 'datainvoice'.");
            }
            $stateData = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['status' => 'PLAYING', 'delay_minutes' => 0];
            $stateData['billing_provider'] = $newProvider;
            file_put_contents($stateFile, json_encode($stateData));
            
            // Sincronizar también con el archivo .env
            update_env_file(['BILLING_PROVIDER' => $newProvider]);
            
            echo json_encode(['status' => 'success', 'billing_provider' => $newProvider]);
            break;

        case 'reset_payloads':
            // Borra el json_payload de todos los registros que NO están ENVIADOS,
            // preservando el estado OMITIDA y su ultimo_error para evitar re-encolar
            // facturas que fueron omitidas a menos que sea forzado.
            $provider = ProviderFactory::getProvider();
            $stmt = $mysql->prepare("
                UPDATE integracion_facturacion 
                SET json_payload = NULL, 
                    estado = CASE WHEN estado = 'OMITIDA' THEN 'OMITIDA' ELSE 'EN_COLA' END, 
                    ultimo_error = CASE WHEN estado = 'OMITIDA' THEN ultimo_error ELSE NULL END 
                WHERE estado != 'ENVIADO'
            ");
            $stmt->execute();
            $affected = $stmt->rowCount();
            echo json_encode(['status' => 'success', 'message' => "$affected registro(s) reseteados. El Watcher regenerará los payloads con proveedor: {$provider}."]);
            break;

        case 'update_settings':
            $data = json_decode(file_get_contents("php://input"), true);
            $stateData = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['status' => 'PLAYING'];
            if (isset($data['delay_minutes'])) {
                $stateData['delay_minutes'] = (int) $data['delay_minutes'];
            }
            file_put_contents($stateFile, json_encode($stateData));
            echo json_encode(['status' => 'success']);
            break;

        case 'process_omitidas':
            // Reactiva facturas omitidas que ya tienen datos de cliente
            $stmt = $mysql->query("SELECT id, json_payload FROM integracion_facturacion WHERE estado = 'OMITIDA'");
            $omitidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $reactivadas = 0;
            
            foreach ($omitidas as $f) {
                $payload = json_decode($f['json_payload'], true);
                if (!$payload) continue;
                
                $idClie = $payload['customer']['identification'] ?? $payload['customer']['identification_number'] ?? '222222222222';
                
                if ($idClie !== '222222222222') {
                    $upd = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'EN_COLA', ultimo_error = 'Reactivada manualmente con datos de cliente' WHERE id = ?");
                    $upd->execute([$f['id']]);
                    $reactivadas++;
                }
            }
            echo json_encode(['status' => 'success', 'message' => "$reactivadas factura(s) omitidas han sido enviadas a la cola de proceso."]);
            break;

        case 'toggle':
            $data = json_decode(file_get_contents("php://input"), true);
            $newStatus = $data['status'] ?? 'PLAYING';
            // Preservar todos los campos existentes del estado
            $stateData = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
            $stateData['status'] = $newStatus;
            file_put_contents($stateFile, json_encode($stateData));
            echo json_encode(['status' => 'success', 'new_status' => $newStatus]);
            break;

        case 'update':
            $data = json_decode(file_get_contents("php://input"), true);
            $id = $data['id'] ?? null;
            $newJson = $data['json_payload'] ?? null;

            if ($id && $newJson) {
                // Actualizar y marcar como EN_COLA para que el Processor lo intente
                $stmt = $mysql->prepare("UPDATE integracion_facturacion SET json_payload = ?, estado = 'EN_COLA', ultimo_error = NULL WHERE id = ?");
                $stmt->execute([$newJson, $id]);

                // Guardar cliente localmente
                $payload = json_decode($newJson, true);
                $idClie = $payload['customer']['identification'] ?? $payload['customer']['identification_number'] ?? '';
                $names = $payload['customer']['names'] ?? $payload['customer']['name'] ?? '';
                $email = $payload['customer']['email'] ?? '';
                $docType = $payload['customer']['identification_document_id'] ?? $payload['customer']['type_document_identification_id'] ?? 3;
                upsert_local_customer($mysql, $idClie, $names, $email, $docType);

                echo json_encode(['status' => 'success']);
            } else {
                throw new Exception("ID o JSON inválido.");
            }
            break;

        case 'search_customer':
            $number = $_GET['number'] ?? '';
            if (!$number) throw new Exception("Número de identificación requerido.");

            // 1. Buscar localmente
            $stmt = $mysql->prepare("SELECT * FROM clientes_locales WHERE identification = ?");
            $stmt->execute([$number]);
            $local = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($local) {
                echo json_encode(['status' => 'success', 'source' => 'local', 'data' => $local]);
                break;
            }

            // 2. Si no está local, buscar en Factus (solo si el proveedor es Factus)
            $provider = ProviderFactory::getProvider();
            if ($provider === 'factus') {
                $auth = ProviderFactory::getAuth();
                $token = $auth->getToken();
                $client = new Client(['base_uri' => $_ENV['FACTUS_API_URL'] . '/']);
                
                try {
                    // Usando el endpoint v2 sugerido para consultar a la DIAN a través de Factus
                    $response = $client->get("v2/dian/acquirer?identification_number={$number}", [
                        'headers' => [
                            'Authorization' => "Bearer {$token}",
                            'Accept' => 'application/json'
                        ]
                    ]);
                    $factusData = json_decode($response->getBody()->getContents(), true);
                    if (($factusData['status'] === 'success' || isset($factusData['data'])) && !empty($factusData['data'])) {
                        // En la V2 de Factus los datos vienen dentro de un objeto
                        $found = $factusData['data'];
                        // Normalizar para el frontend (Soporta múltiples esquemas de respuesta)
                        $normalized = [
                            'identification' => $found['identification_number'] ?? $found['identification'] ?? $number,
                            'names' => $found['company'] ?? $found['names'] ?? $found['name'] ?? ($found['first_name'] . ' ' . $found['last_name']),
                            'email' => $found['email'] ?? '',
                            'identification_document_id' => $found['identification_document_id'] ?? $found['type_document_identification_id'] ?? 3
                        ];
                        // Guardar para la próxima
                        upsert_local_customer($mysql, $normalized['identification'], $normalized['names'], $normalized['email'], $normalized['identification_document_id']);
                        
                        echo json_encode(['status' => 'success', 'source' => 'factus', 'data' => $normalized]);
                        break;
                    }
                } catch (\Exception $e) {
                    // Si falla Factus, no pasa nada, devolvemos vacío
                }
            }

            echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado.']);
            break;

        case 'send_manual':
            // Send exactly one item now, bypassing the queue status
            $data = json_decode(file_get_contents("php://input"), true);
            $id = $data['id'] ?? null;
            
            if (!$id) throw new Exception("ID requerido.");

            $stmt = $mysql->prepare("SELECT * FROM integracion_facturacion WHERE id = ?");
            $stmt->execute([$id]);
            $factura = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$factura) throw new Exception("Factura no encontrada.");

            $payload = json_decode($factura['json_payload'], true);
            $provider = ProviderFactory::getProvider();

            // Detectar si el formato del JSON coincide con el proveedor actual
            $isDataInvoiceFormat = isset($payload['invoice_lines']);
            $mismatch = ($provider === 'factus' && $isDataInvoiceFormat) || ($provider === 'datainvoice' && !$isDataInvoiceFormat && !empty($payload));

            // ── Regenerar si está vacío o si hay un cambio de proveedor ──────
            if (empty($factura['json_payload']) || $mismatch) {
                // Regenerar desde los datos originales de SQL Server
                $db = new \App\Config\Database();
                $sqlsrv = $db->getSqlServerConnection();
                $idFacturaSR = $factura['id_factura_sr'];

                // Buscar en tempcheques y cheques
                $parts = explode('-', $idFacturaSR);
                $numCheque = end($parts);
                $facturaSR = null;

                $stmtF = $sqlsrv->prepare("SELECT folio, seriefolio, numcheque, fecha, total, idcliente, cancelado, pagado FROM tempcheques WHERE numcheque = ?");
                $stmtF->execute([$numCheque]);
                $row = $stmtF->fetch();
                if ($row) $facturaSR = array_merge($row, ['origen' => 'TEMP']);

                if (!$facturaSR) {
                    $stmtF2 = $sqlsrv->prepare("SELECT folio, seriefolio, numcheque, fecha, total, idcliente, cancelado, pagado FROM cheques WHERE numcheque = ?");
                    $stmtF2->execute([$numCheque]);
                    $row2 = $stmtF2->fetch();
                    if ($row2) $facturaSR = array_merge($row2, ['origen' => 'HIST']);
                }

                if ($facturaSR) {
                    $origen = $facturaSR['origen'];
                    $tablaDetalle = ($origen === 'TEMP') ? 'tempcheqdet' : 'cheqdet';
                    $stmtDet = $sqlsrv->prepare("SELECT cd.*, p.descripcion FROM $tablaDetalle cd LEFT JOIN productos p ON cd.idproducto = p.idproducto WHERE cd.foliodet = ?");
                    $stmtDet->execute([$facturaSR['folio']]);
                    $detalles = $stmtDet->fetchAll();

                    $cliente = [];
                    if (!empty($facturaSR['idcliente'])) {
                        $stmtCli = $sqlsrv->prepare("SELECT * FROM clientes WHERE idcliente = ?");
                        $stmtCli->execute([$facturaSR['idcliente']]);
                        $cliente = $stmtCli->fetch() ?: [];
                    }

                    $mapper  = ProviderFactory::getMapper();
                    $payload = $mapper->mapInvoice($facturaSR, $detalles, $cliente);

                    // Guardar el nuevo payload para futuros intentos
                    $stmtUpd = $mysql->prepare("UPDATE integracion_facturacion SET json_payload = ? WHERE id = ?");
                    $stmtUpd->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $id]);
                }
            }

            // ── Lógica de Patrón de Emisión ─────────────────────
            // ELIMINADA: Si es envío manual (Forzar Envío), SIEMPRE debe enviarse
            // independientemente de si es 1_OF_2, etc.

            // ── Validación para Facturas Omitidas ───────────────────────────
            // Si la factura estaba OMITIDA y se intenta enviar manualmente,
            // exigimos que los datos del cliente hayan sido completados.
            if ($factura['estado'] === 'OMITIDA') {
                $isConsumidorFinal = false;
                if ($provider === 'datainvoice') {
                    $isConsumidorFinal = (($payload['customer']['identification_number'] ?? '') === '222222222222');
                } else {
                    $isConsumidorFinal = (($payload['customer']['identification'] ?? '') === '222222222222');
                }

                if ($isConsumidorFinal) {
                    throw new Exception("⚠️ ACCIÓN REQUERIDA: Esta factura fue omitida por el sistema. Para enviarla a la DIAN, primero debe editar los datos del cliente (Nombre y Cédula/NIT) para identificar al receptor.");
                }
            }

            // ── Validación de 5 UVT (Normativa DIAN) ─────────────────────────
            if ($provider === 'datainvoice') {
                $totalPayable = (float)($payload['legal_monetary_totals']['payable_amount'] ?? 0);
                $uvtLimit = (float)($_ENV['VALOR_UVT'] ?? 47065) * 5;
                $isConsumidorFinal = ($payload['customer']['identification_number'] === '222222222222');

                if ($totalPayable >= $uvtLimit && $isConsumidorFinal) {
                    $formatedLimit = number_format($uvtLimit, 0, '.', ',');
                    $formatedTotal = number_format($totalPayable, 0, '.', ',');
                    throw new Exception("⚠️ REQUISITO LEGAL DIAN: Esta factura suma $$formatedTotal, lo cual supera el tope de 5 UVT ($$formatedLimit). No se puede enviar como 'Consumidor Final'. Por favor, edite los datos del cliente (Nombre y Cédula/NIT) antes de enviar.");
                }
            }

            $auth = ProviderFactory::getAuth();
            $token = $auth->getToken();
            $baseUri = $provider === 'datainvoice' ? $_ENV['DATAINVOICE_API_URL'] : $_ENV['FACTUS_API_URL'];
            $client = new Client(['base_uri' => $baseUri . '/', 'timeout' => 30]);

            $endpoint = $provider === 'datainvoice' ? 'invoice' : 'v2/bills/validate';

            $factusId = $factura['factus_invoice_id'] ?? null;
            $isDirectQuery = false;

            if ($provider === 'factus' && !empty($factusId)) {
                $isDirectQuery = true;
                $response = $client->get("v2/bills/{$factusId}", [
                    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                    'http_errors' => false
                ]);

                // Si la consulta directa da 404 o 400, borramos el ID huerfano y reintentamos con POST limpio
                if ($response->getStatusCode() === 404 || $response->getStatusCode() === 400) {
                    $isDirectQuery = false;
                    $factusId = null;
                    $stmtReset = $mysql->prepare("UPDATE integracion_facturacion SET factus_invoice_id = NULL WHERE id = ?");
                    $stmtReset->execute([$id]);
                    
                    $response = $client->post($endpoint, [
                        'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                        'json' => $payload,
                        'http_errors' => false
                    ]);
                }
            } else {
                $response = $client->post($endpoint, [
                    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                    'json' => $payload,
                    'http_errors' => false
                ]);
            }

            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents(), true);
            $apiResponseStr = json_encode($result, JSON_UNESCAPED_UNICODE);

            // ── Recuperación de Conflicto 409 (Factura ya existente) ────────
            if ($provider === 'factus' && $statusCode === 409) {
                $referenceCode = $payload['reference_code'] ?? null;
                if ($referenceCode) {
                    $checkResponse = $client->get('v2/bills', [
                        'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                        'query' => [
                            'filter' => [
                                'reference_code' => $referenceCode
                            ]
                        ],
                        'http_errors' => false
                    ]);
                    
                    if ($checkResponse->getStatusCode() === 200) {
                        $checkResult = json_decode($checkResponse->getBody()->getContents(), true);
                        $billsList = [];
                        if (isset($checkResult['data']['data']) && is_array($checkResult['data']['data'])) {
                            $billsList = $checkResult['data']['data'];
                        } elseif (isset($checkResult['data']) && is_array($checkResult['data'])) {
                            $billsList = $checkResult['data'];
                        }
                        
                        $matchedBill = null;
                        foreach ($billsList as $bill) {
                            if (($bill['reference_code'] ?? '') === $referenceCode) {
                                $matchedBill = $bill;
                                break;
                            }
                        }
                        
                        if ($matchedBill) {
                            $factusId = $matchedBill['number'] ?? $matchedBill['id'] ?? 'OK';
                            $isValidated = $matchedBill['is_validated'] ?? false;
                            $isValidated = ($isValidated === true || $isValidated === 1 || $isValidated === '1');
                            
                            if ($isValidated) {
                                // 1. Si ya está validada, no la borramos, la sincronizamos directo
                                $statusCode = 200;
                                $result = ['data' => $matchedBill];
                                $apiResponseStr = json_encode($result, JSON_UNESCAPED_UNICODE);
                            } else {
                                // 2. Si no está validada, la eliminamos y volvemos a intentar el POST
                                $deleteResponse = $client->delete("v2/bills/destroy/reference/{$referenceCode}", [
                                    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                                    'http_errors' => false
                                ]);
                                
                                // Intentamos el POST limpio nuevamente
                                $response = $client->post($endpoint, [
                                    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                                    'json' => $payload,
                                    'http_errors' => false
                                ]);
                                
                                $statusCode = $response->getStatusCode();
                                $result = json_decode($response->getBody()->getContents(), true);
                                $apiResponseStr = json_encode($result, JSON_UNESCAPED_UNICODE);
                            }
                        }
                    }
                }
            }

            $isSuccess = false;
            $errorMessage = '';

            if ($provider === 'datainvoice') {
                // DataInvoice responde 200/201 con { "success": true, ... }
                $isSuccess = ($statusCode === 200 || $statusCode === 201)
                    && isset($result['success'])
                    && $result['success'] === true;

                if ($isSuccess) {
                    // Extraer el número generado
                    $factusId = $result['number'] ?? $result['data']['number'] ?? null;
                    
                    if (!$factusId && isset($result['urlinvoicepdf'])) {
                        // Intentar extraer de FES-SETP990005016.pdf -> SETP990005016
                        $parts = explode('-', str_replace('.pdf', '', $result['urlinvoicepdf']));
                        $factusId = end($parts); 
                    }

                    if (!$factusId) {
                        $factusId = $payload['number'] ?? 'OK';
                    }
                    
                    // Limpiar campos pesados de XML solo si fue exitoso
                    unset($result['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult']['XmlBase64Bytes']);
                    unset($result['invoicexml']);
                    unset($result['attacheddocument']);
                    unset($result['zipinvoicexml']);
                    unset($result['unsignedinvoicexml']);
                    unset($result['reqfe']);
                    unset($result['rptafe']);
                    $apiResponseStr = json_encode($result, JSON_UNESCAPED_UNICODE);
                } else {
                    $mainMsg    = $result['message'] ?? 'Error desconocido';
                    $validacion = $result['errors']  ?? null;
                    $dianError  = $result['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult']['ErrorMessage']['string'] ?? null;

                    $errorMessage = "HTTP {$statusCode}: {$mainMsg}";
                    if ($dianError) $errorMessage .= "\nDIAN: {$dianError}";
                    if ($validacion) $errorMessage .= "\nDetalles: " . json_encode($validacion, JSON_UNESCAPED_UNICODE);
                    if (!$dianError && !$validacion) $errorMessage .= "\nRespuesta: " . substr($apiResponseStr, 0, 500);
                }
            } else {
                $isSuccess = ($statusCode === 200 || $statusCode === 201);
                if ($isSuccess) {
                    $factusId = $result['data']['bill']['number'] ?? $result['data']['number'] ?? $result['number'] ?? $factusId ?? 'OK';
                    
                    $isValidated = true;
                    if (isset($result['data']['bill']['is_validated'])) {
                        $isValidated = $result['data']['bill']['is_validated'];
                    } elseif (isset($result['data']['is_validated'])) {
                        $isValidated = $result['data']['is_validated'];
                    } elseif (isset($result['is_validated'])) {
                        $isValidated = $result['is_validated'];
                    }

                    $errorsObj = $result['data']['bill']['errors'] ?? $result['data']['errors'] ?? $result['errors'] ?? null;
                    $errorsStr = is_array($errorsObj) ? json_encode($errorsObj, JSON_UNESCAPED_UNICODE) : (string)$errorsObj;
                    
                    $hasRechazo = stripos($errorsStr, 'rechazo') !== false;

                    if (!$isValidated && !$hasRechazo) {
                        // Demora DIAN
                        $isSuccess = false;
                        $errorMessage = "DIAN_PENDING|La factura se encuentra registrada en Factus como {$factusId}, pero está pendiente por enviar o procesar ante la DIAN.";
                    } elseif (!$isValidated && $hasRechazo) {
                        // Rechazo explícito
                        $isSuccess = false;
                        $errorMessage = "Rechazo DIAN: {$errorsStr}";
                    }
                } else {
                    $errorMessage = json_encode($result, JSON_UNESCAPED_UNICODE);
                }
            }

            if ($isSuccess) {
                // Si la respuesta es exitosa, la guardamos optimizada
                $stmtUpdate = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'ENVIADO', factus_invoice_id = ?, api_response = ? WHERE id = ?");
                $stmtUpdate->execute([$factusId, $apiResponseStr, $id]);
                echo json_encode(['status' => 'success', 'message' => 'Enviada con éxito']);
            } else {
                if (str_starts_with($errorMessage, 'DIAN_PENDING|')) {
                    $msgText = substr($errorMessage, 13);
                    $stmtUpdate = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'EN_COLA', ultimo_error = ?, factus_invoice_id = ?, api_response = ?, intentos = intentos + 1 WHERE id = ?");
                    $stmtUpdate->execute([$msgText, $factusId, $apiResponseStr, $id]);
                    // Responder JSON con status error pero mensaje amigable
                    echo json_encode(['status' => 'error', 'message' => $msgText]);
                } else {
                    // Truncar ultimo_error a 1000 caracteres para evitar errores de SQL
                    $safeError = mb_substr($errorMessage, 0, 1000);
                    $stmtUpdate = $mysql->prepare("UPDATE integracion_facturacion SET estado = 'ERROR', ultimo_error = ?, api_response = ?, intentos = intentos + 1 WHERE id = ?");
                    $stmtUpdate->execute([$safeError, $apiResponseStr, $id]);
                    throw new Exception("Error de Envío: " . $errorMessage);
                }
            }
            break;


        case 'download_pdf':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception("ID requerido.");

            $stmt = $mysql->prepare("SELECT api_response, estado, factus_invoice_id FROM integracion_facturacion WHERE id = ?");
            $stmt->execute([$id]);
            $factura = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$factura) throw new Exception("Factura no encontrada.");
            if ($factura['estado'] !== 'ENVIADO') throw new Exception("La factura no ha sido enviada con éxito.");
            
            $apiResp = json_decode($factura['api_response'], true);
            
            // Detección automática del proveedor
            $originalProvider = (isset($apiResp['urlinvoicepdf']) || isset($apiResp['QRStr'])) ? 'datainvoice' : 'factus';

            if ($originalProvider === 'datainvoice') {
                $fileName = $apiResp['urlinvoicepdf'] ?? null;
                if (!$fileName) throw new Exception("Nombre de PDF DataInvoice no encontrado.");
                if (!str_ends_with(strtolower($fileName), '.pdf')) $fileName .= '.pdf';

                $nitEmisor = $_ENV['DATAINVOICE_NIT'] ?? '7573772';
                $pdfUrl = "https://api.datainvoicecolombia.com/api/invoice/{$nitEmisor}/{$fileName}";
                
                header_remove('Content-Type');
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $fileName . '"');
                readfile($pdfUrl);
                exit;
            } else {
                $number = $factura['factus_invoice_id'];
                if (!$number || $number === 'OK') $number = $apiResp['data']['number'] ?? null;
                if (!$number) throw new Exception("Número de factura no encontrado.");

                $auth = \App\Services\ProviderFactory::getAuth();
                $token = $auth->getToken();
                $client = new \GuzzleHttp\Client(['base_uri' => $_ENV['FACTUS_API_URL'] . '/', 'timeout' => 30]);

                $response = $client->get("v2/bills/{$number}/download-pdf", [
                    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                    'http_errors' => false
                ]);

                $result = json_decode($response->getBody()->getContents(), true);
                if ($response->getStatusCode() === 200 && isset($result['data']['pdf_base_64_encoded'])) {
                    header_remove('Content-Type');
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="factura.pdf"');
                    echo base64_decode($result['data']['pdf_base_64_encoded']);
                    exit;
                } else {
                    throw new Exception("Error Factus PDF: " . json_encode($result));
                }
            }
            break;

        case 'company_info':
            $provider = ProviderFactory::getProvider();
            if ($provider === 'datainvoice') {
                echo json_encode(['status' => 'success', 'data' => ['company' => ['company' => 'Configuración de Empresa no soportada via API en DataInvoice.']]]);
                break;
            }
            
            $auth = ProviderFactory::getAuth();
            $token = $auth->getToken();
            $client = new Client(['base_uri' => $_ENV['FACTUS_API_URL'] . '/', 'timeout' => 30]);
            
            $response = $client->get("v2/companies", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'http_errors' => false
            ]);
            
            echo $response->getBody()->getContents();
            break;

        case 'update_company':
            $provider = ProviderFactory::getProvider();
            if ($provider === 'datainvoice') {
                throw new Exception("Edición de empresa no soportada en DataInvoice vía API desde este panel.");
            }

            $data = json_decode(file_get_contents("php://input"), true);
            if (!$data) throw new Exception("Datos inválidos.");

            $auth = ProviderFactory::getAuth();
            $token = $auth->getToken();
            $client = new Client(['base_uri' => $_ENV['FACTUS_API_URL'] . '/', 'timeout' => 30]);

            $response = $client->put("v2/companies", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'json' => $data,
                'http_errors' => false
            ]);

            echo $response->getBody()->getContents();
            break;

        case 'numbering_ranges':
            $provider = ProviderFactory::getProvider();
            if ($provider === 'datainvoice') {
                echo json_encode(['status' => 'success', 'data' => []]);
                break;
            }

            $auth = ProviderFactory::getAuth();
            $token = $auth->getToken();
            $client = new Client(['base_uri' => $_ENV['FACTUS_API_URL'] . '/', 'timeout' => 30]);
            
            $response = $client->get("v2/numbering-ranges", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'http_errors' => false
            ]);
            
            echo $response->getBody()->getContents();
            break;

        case 'search_dian':
            $provider = ProviderFactory::getProvider();
            $type = $_GET['type'] ?? '3'; // Por defecto 3 (CC)
            $number = $_GET['number'] ?? '';
            
            if (!$number) throw new Exception("Número de documento requerido.");
            
            $auth = ProviderFactory::getAuth();
            $token = $auth->getToken();
            
            if ($provider === 'datainvoice') {
                $client = new Client(['base_uri' => $_ENV['DATAINVOICE_API_URL'] . '/', 'timeout' => 30]);
                // DataInvoice endpoint para terceros: GET /reference/get_data_dian/{nit}
                // Ajustando la URL base para reference
                $dianUrl = "https://api.datainvoicecolombia.com/api/reference/get_data_dian/{$number}";
                
                $response = $client->get($dianUrl, [
                    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                    'http_errors' => false
                ]);
                
                // El resultado de DataInvoice no tiene la misma estructura de Factus
                $result = json_decode($response->getBody()->getContents(), true);
                if ($response->getStatusCode() === 200 && isset($result['data']['name'])) {
                    // Adaptar a la respuesta esperada por el frontend de Factus
                    $mappedResponse = [
                        'data' => [
                            [
                                'identification' => $result['data']['identification_number'] ?? $number,
                                'names' => $result['data']['name'],
                                'address' => '',
                                'email' => '',
                                'phone' => '',
                                'legal_organization_id' => 2,
                                'identification_document_id' => 1
                            ]
                        ]
                    ];
                    echo json_encode($mappedResponse);
                } else {
                    echo json_encode(['data' => []]);
                }
            } else {
                $client = new Client(['base_uri' => $_ENV['FACTUS_API_URL'] . '/', 'timeout' => 30]);
                $response = $client->get("v2/dian/acquirer?identification_document_id={$type}&identification_number={$number}", [
                    'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                    'http_errors' => false
                ]);
                echo $response->getBody()->getContents();
            }
            break;

        case 'get_all_settings':
            $settings = [
                'DB_HOST' => $_ENV['DB_HOST'] ?? '',
                'DB_PORT' => $_ENV['DB_PORT'] ?? '',
                'DB_DATABASE' => $_ENV['DB_DATABASE'] ?? '',
                'DB_USERNAME' => $_ENV['DB_USERNAME'] ?? '',
                'DB_PASSWORD' => $_ENV['DB_PASSWORD'] ?? '',
                'SR_DB_HOST' => $_ENV['SR_DB_HOST'] ?? '',
                'SR_DB_PORT' => $_ENV['SR_DB_PORT'] ?? '',
                'SR_DB_DATABASE' => $_ENV['SR_DB_DATABASE'] ?? '',
                'SR_DB_USERNAME' => $_ENV['SR_DB_USERNAME'] ?? '',
                'SR_DB_PASSWORD' => $_ENV['SR_DB_PASSWORD'] ?? '',
                'BILLING_PROVIDER' => $_ENV['BILLING_PROVIDER'] ?? '',
                'ALERT_THRESHOLD' => $_ENV['ALERT_THRESHOLD'] ?? '',
                'DEFAULT_TAX_RATE' => $_ENV['DEFAULT_TAX_RATE'] ?? '',
                'MERCHANT_REGISTRATION' => $_ENV['MERCHANT_REGISTRATION'] ?? '',
                'VALOR_UVT' => $_ENV['VALOR_UVT'] ?? '',
                'EMISSION_PATTERN' => $_ENV['EMISSION_PATTERN'] ?? '',
                'MAX_EDIT_HOURS' => $_ENV['MAX_EDIT_HOURS'] ?? '',
                'FACTUS_API_URL' => $_ENV['FACTUS_API_URL'] ?? '',
                'FACTUS_UNIT_MEASURE_CODE' => $_ENV['FACTUS_UNIT_MEASURE_CODE'] ?? '',
                'FACTUS_TAX_ID' => $_ENV['FACTUS_TAX_ID'] ?? '',
                'FACTUS_TAX_CODE' => $_ENV['FACTUS_TAX_CODE'] ?? '',
                'FACTUS_TAX_NAME' => $_ENV['FACTUS_TAX_NAME'] ?? '',
                'FACTUS_EMAIL' => $_ENV['FACTUS_EMAIL'] ?? '',
                'FACTUS_PASSWORD' => $_ENV['FACTUS_PASSWORD'] ?? '',
                'FACTUS_CLIENT_ID' => $_ENV['FACTUS_CLIENT_ID'] ?? '',
                'FACTUS_CLIENT_SECRET' => $_ENV['FACTUS_CLIENT_SECRET'] ?? '',
                'DATAINVOICE_API_URL' => $_ENV['DATAINVOICE_API_URL'] ?? '',
                'DATAINVOICE_NIT' => $_ENV['DATAINVOICE_NIT'] ?? '',
                'DATAINVOICE_TOKEN' => $_ENV['DATAINVOICE_TOKEN'] ?? '',
                'DATAINVOICE_TAX_ID' => $_ENV['DATAINVOICE_TAX_ID'] ?? '',
                'DATAINVOICE_REGIME_ID' => $_ENV['DATAINVOICE_REGIME_ID'] ?? '',
                'DATAINVOICE_LIABILITY_ID' => $_ENV['DATAINVOICE_LIABILITY_ID'] ?? '',
                'DATAINVOICE_RESOLUTION_NUMBER' => $_ENV['DATAINVOICE_RESOLUTION_NUMBER'] ?? '',
                'DATAINVOICE_PREFIX' => $_ENV['DATAINVOICE_PREFIX'] ?? '',
                'DATAINVOICE_NUMBER_FROM' => $_ENV['DATAINVOICE_NUMBER_FROM'] ?? '',
                'DATAINVOICE_NUMBER_TO' => $_ENV['DATAINVOICE_NUMBER_TO'] ?? '',
                'DATAINVOICE_ESTABLISHMENT_NAME' => $_ENV['DATAINVOICE_ESTABLISHMENT_NAME'] ?? '',
                'DATAINVOICE_ESTABLISHMENT_ADDRESS' => $_ENV['DATAINVOICE_ESTABLISHMENT_ADDRESS'] ?? '',
                'DATAINVOICE_ESTABLISHMENT_PHONE' => $_ENV['DATAINVOICE_ESTABLISHMENT_PHONE'] ?? '',
                'DATAINVOICE_ESTABLISHMENT_EMAIL' => $_ENV['DATAINVOICE_ESTABLISHMENT_EMAIL'] ?? '',
                'DATAINVOICE_ESTABLISHMENT_MUNICIPALITY' => $_ENV['DATAINVOICE_ESTABLISHMENT_MUNICIPALITY'] ?? '',
                'DATAINVOICE_MUNICIPALITY_ID' => $_ENV['DATAINVOICE_MUNICIPALITY_ID'] ?? '',
                'DATAINVOICE_DEFAULT_EMAIL' => $_ENV['DATAINVOICE_DEFAULT_EMAIL'] ?? '',
                'DATAINVOICE_UNIT_MEASURE_ID' => $_ENV['DATAINVOICE_UNIT_MEASURE_ID'] ?? '',
                'DATAINVOICE_SEND_MAIL' => $_ENV['DATAINVOICE_SEND_MAIL'] ?? '',
                'DATAINVOICE_SEND_MAIL_TO_ME' => $_ENV['DATAINVOICE_SEND_MAIL_TO_ME'] ?? '',
            ];
            echo json_encode(['status' => 'success', 'data' => $settings]);
            break;

        case 'save_all_settings':
            $data = json_decode(file_get_contents("php://input"), true);
            if (!$data) throw new Exception("Datos inválidos.");
            
            if (update_env_file($data)) {
                echo json_encode(['status' => 'success', 'message' => 'Configuración actualizada en .env']);
            } else {
                throw new Exception("Error al escribir en el archivo .env");
            }
            break;

        default:
            throw new Exception("Acción no válida");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
