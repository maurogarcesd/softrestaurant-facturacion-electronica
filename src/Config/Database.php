<?php

namespace App\Config;

use PDO;
use PDOException;

class Database {
    private ?PDO $mysqlConnection = null;
    private ?PDO $sqlServerConnection = null;

    public function getMysqlConnection(): PDO {
        if ($this->mysqlConnection === null) {
            try {
                $host = $_ENV['DB_HOST'];
                $port = $_ENV['DB_PORT'];
                $db   = $_ENV['DB_DATABASE'];
                $user = $_ENV['DB_USERNAME'];
                $pass = $_ENV['DB_PASSWORD'];

                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
                $this->mysqlConnection = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                die("Error de conexión MySQL: " . $e->getMessage());
            }
        }
        return $this->mysqlConnection;
    }

    public function getSqlServerConnection(): PDO {
        if ($this->sqlServerConnection === null) {
            try {
                $host = $_ENV['SR_DB_HOST'];
                $port = $_ENV['SR_DB_PORT'];
                $db   = $_ENV['SR_DB_DATABASE'];
                $user = $_ENV['SR_DB_USERNAME'];
                $pass = $_ENV['SR_DB_PASSWORD'];

                // DSN string for sqlsrv
                $dsn = "sqlsrv:Server=$host,$port;Database=$db;TrustServerCertificate=1";
                $this->sqlServerConnection = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e) {
                die("Error de conexión SQL Server: " . $e->getMessage());
            }
        }
        return $this->sqlServerConnection;
    }
}
