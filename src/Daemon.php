<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Watcher;
use App\Processor;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

echo "=========================================\n";
echo "  🚀 INICIANDO DEMONIO FACTUS V2 \n";
echo "=========================================\n";

$watcher = new Watcher();
$processor = new Processor();

while (true) {
    try {
        echo "\n[".date('Y-m-d H:i:s')."] Ciclo de extracción...\n";
        $watcher->run();
        
        echo "\n[".date('Y-m-d H:i:s')."] Ciclo de procesamiento...\n";
        $processor->run();

    } catch (\Exception $e) {
        echo "[!] Error Crítico en el Demonio: " . $e->getMessage() . "\n";
    }

    // Esperar 10 segundos antes del siguiente ciclo
    sleep(10);
}
