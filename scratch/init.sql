CREATE DATABASE IF NOT EXISTS integracion_factus;
USE integracion_factus;

-- Tabla para el control de facturas enviadas a la DIAN (Factus o DataInvoice)
CREATE TABLE IF NOT EXISTS integracion_facturacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_factura_sr VARCHAR(50) NOT NULL UNIQUE COMMENT 'ID de la factura en SoftRestaurant',
    folio_sr VARCHAR(50) NULL COMMENT 'Folio/Serie en SoftRestaurant',
    fecha_sr DATETIME NOT NULL COMMENT 'Fecha de la factura original',
    total_sr DECIMAL(12,2) NOT NULL COMMENT 'Total facturado',
    factus_invoice_id VARCHAR(100) NULL COMMENT 'ID devuelto por la API al crear la factura',
    json_payload LONGTEXT NULL COMMENT 'Estructura JSON generada para enviar a la API',
    api_response LONGTEXT NULL COMMENT 'Respuesta devuelta por la API',
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE' COMMENT 'PENDIENTE, EN_COLA, ENVIADO, ERROR, OMITIDA',
    intentos INT NOT NULL DEFAULT 0,
    ultimo_error TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para guardar localmente la información aprendida de los clientes
CREATE TABLE IF NOT EXISTS clientes_locales (
    identification VARCHAR(20) PRIMARY KEY,
    names VARCHAR(255),
    email VARCHAR(255),
    identification_document_id INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
