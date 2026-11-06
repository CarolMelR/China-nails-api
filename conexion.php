<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database_connection.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

$connection = null;

try {
    $connection = china_nails_database_connection();
    $connection->query('SELECT 1');

    echo json_encode([
        'success' => true,
        'message' => 'Conexión exitosa con la base de datos.',
    ]);
} catch (Throwable $error) {
    if (
        !($error instanceof ChinaNailsDatabaseConfigurationException) &&
        !($error instanceof ChinaNailsDatabaseConnectionException)
    ) {
        error_log('China Nails MySQL connection check failed: ' . $error->getMessage());
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo conectar con la base de datos. Revisa la configuración y el certificado SSL.',
    ]);
} finally {
    if ($connection instanceof mysqli) {
        $connection->close();
    }
}
