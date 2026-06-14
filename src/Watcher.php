<?php

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Services\ProviderFactory;
use GuzzleHttp\Client;
use PDO;

// Cargar variables de entorno
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

class Watcher {
    private Database $db;
    private $auth;
    private $mapper;
    private Client $apiClient;

    public function __construct() {
        $this->db = new Database();
    }

    private function initProvider() {
        $this->auth = ProviderFactory::getAuth();
        $this->mapper = ProviderFactory::getMapper();
        
        $provider = ProviderFactory::getProvider();
        $baseUri = $provider === 'datainvoice' ? $_ENV['DATAINVOICE_API_URL'] : $_ENV['FACTUS_API_URL'];
        
        $this->apiClient = new Client([
            'base_uri' => $baseUri . '/',
            'timeout'  => 15.0,
        ]);
        return $provider;
    }

    public function run() {
        $provider = $this->initProvider();
        echo "Iniciando Watcher (Proveedor: $provider)...\n";
        
        $mysql = $this->db->getMysqlConnection();
        $sqlsrv = $this->db->getSqlServerConnection();

        // 1. Buscar tickets en tiempo real (tempcheques) y cerrados (cheques)
        // Buscamos tickets pagados (pagado=1) y no cancelados (cancelado=0)
        $sql = "
            SELECT TOP 100 * FROM (
                SELECT folio, seriefolio, numcheque, fecha, total, idcliente, cancelado, pagado, 'TEMP' as origen FROM tempcheques
                UNION ALL
                SELECT folio, seriefolio, numcheque, fecha, total, idcliente, cancelado, pagado, 'HIST' as origen FROM cheques
            ) as combined
            WHERE cancelado = 0 AND pagado = 1 
            AND fecha >= DATEADD(day, -1, GETDATE())
            ORDER BY fecha DESC
        ";
        $stmt = $sqlsrv->query($sql);
        $facturasSR = $stmt->fetchAll();

        foreach ($facturasSR as $factura) {
            $folioDb = $factura['folio'];
            $origen = $factura['origen'];

            // Usar numcheque (el ticket real) como ID único para evitar colisiones entre tablas
            $serie = trim($factura['seriefolio'] ?? '');
            $numCheque = trim($factura['numcheque'] ?? '');
            $idFacturacion = $serie !== '' ? "{$serie}-{$numCheque}" : ($numCheque !== '' ? $numCheque : "{$origen}_{$folioDb}");

            // 2. Revisar si ya existe en MySQL
            $stmtCheck = $mysql->prepare("SELECT id, json_payload, estado, ultimo_error FROM integracion_facturacion WHERE id_factura_sr = ?");
            $stmtCheck->execute([$idFacturacion]);
            $existe = $stmtCheck->fetch();

            if ($existe) {
                // Si existe pero no tiene payload (porque se reseteó), procedemos a generarlo
                if (!empty($existe['json_payload'])) {
                    continue; 
                }
                echo "♻️ Regenerando payload para factura existente SR: $idFacturacion\n";
            } else {
                echo "🆕 Añadiendo a la cola SR: $idFacturacion\n";
            }

            try {
                // 3. Obtener Detalles (según el origen temp o hist)
                $tablaDetalle = ($origen === 'TEMP') ? 'tempcheqdet' : 'cheqdet';
                
                $stmtDetalles = $sqlsrv->prepare("
                    SELECT cd.*, p.descripcion 
                    FROM $tablaDetalle cd 
                    LEFT JOIN productos p ON cd.idproducto = p.idproducto 
                    WHERE cd.foliodet = ?
                ");
                $stmtDetalles->execute([$folioDb]);
                $detalles = $stmtDetalles->fetchAll();

                // 4. Obtener Cliente
                $idCliente = $factura['idcliente'] ?? null;
                $cliente = [];
                if ($idCliente) {
                    $stmtCliente = $sqlsrv->prepare("SELECT * FROM clientes WHERE idcliente = ?");
                    $stmtCliente->execute([$idCliente]);
                    $cliente = $stmtCliente->fetch() ?: [];
                }

                // 5. Mapear a JSON Factus
                $payloadArray = $this->mapper->mapInvoice($factura, $detalles, $cliente);
                $jsonPayload = json_encode($payloadArray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                
                // Validar Tope Legal dinámicamente desde .env (5 UVT)
                $valorUVT = isset($_ENV['VALOR_UVT']) ? (float)$_ENV['VALOR_UVT'] : 47065;
                $uvtLimit = $valorUVT * 5;
                
                $totalVenta = (float) $factura['total'];
                $identificacion = $payloadArray['customer']['identification'] ?? $payloadArray['customer']['identification_number'] ?? '222222222222';
                
                $estadoFinal = 'PENDIENTE';
                $errorGuardado = null;

                if ($totalVenta > $uvtLimit && $identificacion === '222222222222') {
                    $estadoFinal = 'ERROR';
                    $errorGuardado = "Venta de $" . number_format($totalVenta, 0) . " supera el tope legal para Consumidor Final (5 UVT: $" . number_format($uvtLimit, 0) . "). Edite el cliente en la interfaz.";
                }

                // 6. Guardar en la base de datos
                if ($existe) {
                    // Si ya existía, queremos regenerar su json_payload pero preservar su estado original (ej. OMITIDA, EN_COLA)
                    // a menos que el estado calculado final sea ERROR por motivos legales (como tope de 5 UVT).
                    $nuevoEstado = $existe['estado'];
                    $nuevoError = $existe['ultimo_error'] ?? $errorGuardado;

                    if ($estadoFinal === 'ERROR') {
                        $nuevoEstado = 'ERROR';
                        $nuevoError = $errorGuardado;
                    }

                    $stmtUpdate = $mysql->prepare("
                        UPDATE integracion_facturacion 
                        SET json_payload = ?, estado = ?, ultimo_error = ? 
                        WHERE id = ?
                    ");
                    $stmtUpdate->execute([$jsonPayload, $nuevoEstado, $nuevoError, $existe['id']]);
                    echo "♻️ Factura $idFacturacion regenerada con éxito (Estado conservado: $nuevoEstado).\n";
                } else {
                    $stmtInsert = $mysql->prepare("
                        INSERT INTO integracion_facturacion 
                        (id_factura_sr, folio_sr, fecha_sr, total_sr, json_payload, estado, ultimo_error) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtInsert->execute([
                        $idFacturacion, 
                        $serie.'-'.$numCheque, 
                        $factura['fecha'], 
                        $factura['total'],
                        $jsonPayload,
                        $estadoFinal,
                        $errorGuardado
                    ]);
                    if ($estadoFinal === 'ERROR') {
                        echo "⚠️ Factura $idFacturacion bloqueada por tope legal.\n";
                    } else {
                        echo "✅ Factura $idFacturacion agregada a la cola.\n";
                    }
                }

            } catch (\Exception $e) {
                echo "❌ Error al generar payload de $idFacturacion: " . $e->getMessage() . "\n";
                $stmtError = $mysql->prepare("INSERT INTO integracion_facturacion (id_factura_sr, folio_sr, fecha_sr, total_sr, estado, ultimo_error, intentos) VALUES (?, ?, ?, ?, 'ERROR', ?, 0) ON DUPLICATE KEY UPDATE estado = 'ERROR', ultimo_error = VALUES(ultimo_error)");
                $stmtError->execute([$idFacturacion, $serie.'-'.$numCheque, $factura['fecha'], $factura['total'], substr($e->getMessage(), 0, 1000)]);
            }
        }
        
        echo "Watcher (Extractor) finalizado.\n";
    }
}

// Ejecutar
$watcher = new Watcher();
$watcher->run();
