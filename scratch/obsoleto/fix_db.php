<?php
require __DIR__ . '/vendor/autoload.php';
use App\Config\Database;

try {
    $db = new Database();
    $mysql = $db->getMysqlConnection();
    
    // Cambiar la columna estado a VARCHAR(20) para permitir 'OMITIDA'
    $sql = "ALTER TABLE integracion_facturacion MODIFY COLUMN estado VARCHAR(20) DEFAULT 'PENDIENTE'";
    $mysql->exec($sql);
    
    echo "¡Éxito! La columna 'estado' ha sido actualizada correctamente.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
