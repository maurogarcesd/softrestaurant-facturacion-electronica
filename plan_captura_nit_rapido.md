# Plan de Implementación: Captura Rápida (Fast-Track) de NIT/CC desde el POS

Este documento detalla los pasos técnicos necesarios para implementar la captura ágil de identificaciones de clientes (NIT o Cédula) directamente desde la pantalla de cobro del POS de SoftRestaurant, sin necesidad de usar el módulo completo de registro de "Catálogo de Clientes".

## 🎯 Objetivo de la Funcionalidad
Permitir que los cajeros digiten el número de identificación del cliente en el campo de **"Referencia"**, **"Comentarios"** o **"Número de Cuenta"** del pedido. El Middleware interceptará este texto, aislará los números y facturará electrónicamente a esa cédula, evitando el tope legal de Consumidor Final (5 UVT).

---

## 🛠️ Modificaciones Técnicas Requeridas

### 1. Actualización de la Consulta SQL en `Watcher.php`
Actualmente, el `Watcher.php` solo consulta los datos básicos de los cheques. Se debe expandir la consulta en la **línea ~50** para incluir los campos `numerocuenta` y `observaciones`.

```sql
SELECT TOP 100 * FROM (
    SELECT folio, seriefolio, numcheque, fecha, total, idcliente, cancelado, pagado, numerocuenta, observaciones, 'TEMP' as origen FROM tempcheques
    UNION ALL
    SELECT folio, seriefolio, numcheque, fecha, total, idcliente, cancelado, pagado, numerocuenta, observaciones, 'HIST' as origen FROM cheques
) as combined
WHERE cancelado = 0 AND pagado = 1 
AND fecha >= DATEADD(day, -1, GETDATE())
ORDER BY fecha DESC
```

### 2. Extracción y Limpieza de la Identificación (`Watcher.php`)
Después de ejecutar la consulta del cliente original (alrededor de la **línea ~90**), se debe inyectar un bloque de código para analizar los nuevos campos.

```php
// Extraer texto de los campos de captura rápida (damos prioridad a observaciones)
$textoRapido = trim($factura['observaciones'] ?? $factura['numerocuenta'] ?? '');

// Expresión regular para extraer SOLO números (elimina espacios y letras accidentales)
$ccRapida = preg_replace('/[^0-9]/', '', $textoRapido);
```

### 3. Mapeo Inteligente y Auto-Completado de Clientes
Si el cajero mandó el ticket como "Consumidor Final" (`222222222222`) pero detectamos que escribió una cédula de más de 5 dígitos en las observaciones, el Middleware intentará buscar al cliente real en la base de datos de SoftRestaurant para usar su nombre e email reales.

```php
if (($clienteSR['identificacion'] == '222222222222' || empty($clienteSR['identificacion'])) && strlen($ccRapida) >= 6) {
    
    // 1. Intentar buscar si el cliente ya existe en el histórico de SoftRestaurant
    $stmtClieFast = $sqlsrv->prepare("SELECT TOP 1 * FROM clientes WHERE rfc = ? OR identificacion = ?");
    $stmtClieFast->execute([$ccRapida, $ccRapida]);
    $clieFast = $stmtClieFast->fetch(PDO::FETCH_ASSOC);
    
    if ($clieFast) {
        // ¡Éxito! El cliente ya existía, usamos toda su data (Nombre, Dirección, Email)
        $clienteSR = $clieFast;
    } else {
        // 2. El cliente es totalmente nuevo. Forzamos la ID para cumplir con la DIAN
        $clienteSR['identificacion'] = $ccRapida;
        $clienteSR['rfc'] = $ccRapida;
        $clienteSR['nombre'] = 'Cliente (Registro Rápido)';
        // Para DataInvoice/Factus, si es persona natural pasará por defecto. 
        // Si el número tiene 9 dígitos, los Mappers lo detectarán como Empresa (NIT).
    }
}
```

### 4. Precauciones con Factus y DataInvoice
Los Mappers (`FactusMapper.php` y `DataInvoiceMapper.php`) ya cuentan con lógica inteligente. 
* Si la cadena introducida tiene exactamente **9 dígitos**, los mappers automáticamente lo interpretarán como un NIT (Persona Jurídica) y calcularán el dígito de verificación.
* Si tiene otra longitud, lo tratarán como Cédula de Ciudadanía (Persona Natural).

---

## 👨‍🏫 Entrenamiento Operativo (Para Cajeros)

Una vez habilitada esta mejora en el código, la instrucción al personal de caja será muy simple:

1. El cliente pide factura a su nombre.
2. El cajero procede a cerrar/pagar la cuenta de manera normal sin crear el cliente en sistema.
3. Antes de darle a "Pagar", el cajero hace clic en el botón de **Referencia** o **Comentarios** del cheque.
4. Digita el número de cédula o NIT del cliente. **Ejemplo: `CC 10203040` o simplemente `10203040`**.
5. Cierra la cuenta.

El Middleware se encargará de extraer únicamente el `10203040`, lo buscará, lo mapeará y enviará la Factura Electrónica exitosamente a la DIAN.
