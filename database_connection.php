<?php
declare(strict_types=1);

final class ChinaNailsDatabaseConfigurationException extends RuntimeException
{
}

final class ChinaNailsDatabaseConnectionException extends RuntimeException
{
}

function china_nails_database_connection(): mysqli
{
    $host = getenv('CN_DB_HOST') ?: '';
    $port = (int) (getenv('CN_DB_PORT') ?: '0');
    $username = getenv('CN_DB_USER') ?: '';
    $password = getenv('CN_DB_PASSWORD') ?: '';
    $databaseName = getenv('CN_DB_NAME') ?: '';
    $caCertificatePath = getenv('CN_DB_SSL_CA')
        ?: __DIR__ . DIRECTORY_SEPARATOR . 'ca.pem';

    $missingSettings = [];
    foreach ([
        'CN_DB_HOST' => $host,
        'CN_DB_PORT' => $port > 0 ? (string) $port : '',
        'CN_DB_USER' => $username,
        'CN_DB_PASSWORD' => $password,
        'CN_DB_NAME' => $databaseName,
    ] as $name => $value) {
        if ($value === '') {
            $missingSettings[] = $name;
        }
    }

    if (!is_file($caCertificatePath)) {
        $missingSettings[] = 'CN_DB_SSL_CA (CA certificate not found)';
    }

    if ($missingSettings !== []) {
        error_log(
            'China Nails MySQL configuration incomplete: '
            . implode(', ', $missingSettings)
        );
        throw new ChinaNailsDatabaseConfigurationException(
            'La conexión a la base de datos no está configurada.'
        );
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
        $connection->set_charset('utf8mb4');

        return $connection;
    } catch (Throwable $error) {
        $connectionError = $error instanceof mysqli_sql_exception
            ? $error->getMessage()
            : (mysqli_connect_error() ?: $error->getMessage());
        if ($password !== '') {
            $connectionError = str_replace($password, '[REDACTED]', $connectionError);
        }
        error_log('China Nails MySQL connection failed: ' . $connectionError);

        if ($connection instanceof mysqli) {
            $connection->close();
        }
        throw new ChinaNailsDatabaseConnectionException(
            'MySQL connection failed.',
            0,
            $error
        );
    }
}
