<?php
declare(strict_types=1);

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

$host = getenv('CN_DB_HOST') ?: 'TU_HOST_AIVEN';
$port = (int) (getenv('CN_DB_PORT') ?: '0');
$username = getenv('CN_DB_USER') ?: 'TU_USUARIO_AIVEN';
$password = getenv('CN_DB_PASSWORD') ?: '';
$databaseName = getenv('CN_DB_NAME') ?: 'TU_BASE_DE_DATOS';
$caCertificatePath = getenv('CN_DB_SSL_CA') ?: __DIR__ . DIRECTORY_SEPARATOR . 'ca.pem';

if (
    $host === 'TU_HOST_AIVEN' ||
    $port < 1 ||
    $username === 'TU_USUARIO_AIVEN' ||
    $password === '' ||
    $databaseName === 'TU_BASE_DE_DATOS' ||
    !is_file($caCertificatePath)
) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Configura las credenciales y el certificado CA de Aiven en el entorno de PHP.',
    ]);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = null;

try {
    $connection = mysqli_init();
    $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $connection->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    $connection->ssl_set(null, null, $caCertificatePath, null, null);
    $connection->real_connect(
        $host,
        $username,
        $password,
        $databaseName,
        $port,
        null,
        MYSQLI_CLIENT_SSL
    );
    $connection->query('SELECT 1');

    echo json_encode([
        'success' => true,
        'message' => 'Conexión exitosa con la base de datos.',
    ]);
} catch (Throwable $error) {
    error_log('China Nails MySQL connection check failed: ' . $error->getMessage());
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
