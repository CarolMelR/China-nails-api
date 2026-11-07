<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database_connection.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'catalogo.php';

date_default_timezone_set(CHINA_NAILS_CATALOG['schedule']['timezone']);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

final class ApiException extends RuntimeException
{
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail_request(int $status, string $message): never
{
    throw new ApiException($message, $status);
}

function read_request_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        fail_request(400, 'El cuerpo de la solicitud no contiene JSON válido.');
    }
    return $body;
}

function public_user(array $user): array
{
    return [
        'id' => (int) $user['user_id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'displayName' => $user['full_name'],
        'phone' => $user['phone'],
        'role' => $user['role'],
    ];
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function create_token(array $user): string
{
    $secret = getenv('CN_JWT_SECRET') ?: '';
    if (strlen($secret) < 32) {
        fail_request(500, 'La firma de sesiones no está configurada correctamente.');
    }

    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $now = time();
    $payload = base64url_encode(json_encode([
        'sub' => (string) $user['user_id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'iat' => $now,
        'exp' => $now + 28800,
    ]));
    $signature = base64url_encode(
        hash_hmac('sha256', "$header.$payload", $secret, true)
    );
    return "$header.$payload.$signature";
}

function authenticated_user(): array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authorization = $headers['Authorization']
        ?? $headers['authorization']
        ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!str_starts_with($authorization, 'Bearer ')) {
        fail_request(401, 'Inicia sesión para continuar.');
    }

    $token = substr($authorization, 7);
    $parts = explode('.', $token);
    $secret = getenv('CN_JWT_SECRET') ?: '';
    if (count($parts) !== 3 || strlen($secret) < 32) {
        fail_request(401, 'La sesión expiró. Inicia sesión de nuevo.');
    }

    $expectedSignature = base64url_encode(
        hash_hmac('sha256', "$parts[0].$parts[1]", $secret, true)
    );
    if (!hash_equals($expectedSignature, $parts[2])) {
        fail_request(401, 'La sesión expiró. Inicia sesión de nuevo.');
    }

    $payload = json_decode(
        base64_decode(strtr($parts[1], '-_', '+/')),
        true
    );
    if (
        !is_array($payload) ||
        !isset($payload['sub'], $payload['exp'], $payload['role']) ||
        (int) $payload['exp'] <= time()
    ) {
        fail_request(401, 'La sesión expiró. Inicia sesión de nuevo.');
    }

    return $payload;
}

function require_role(array $user, string $role): void
{
    if (($user['role'] ?? null) !== $role) {
        fail_request(403, 'No tienes permiso para esta acción.');
    }
}

function catalog_service(mysqli $connection, string $serviceCode): array
{
    $techniques = CHINA_NAILS_CATALOG['techniques'];
    if (!isset($techniques[$serviceCode])) {
        fail_request(404, 'La técnica seleccionada no está disponible.');
    }

    $statement = $connection->prepare(
        'SELECT codigo_servicio FROM servicios
         WHERE codigo_servicio = ? AND activo = TRUE LIMIT 1'
    );
    $statement->bind_param('s', $serviceCode);
    $statement->execute();
    if (!$statement->get_result()->fetch_assoc()) {
        fail_request(404, 'La técnica seleccionada ya no está disponible.');
    }

    return $techniques[$serviceCode];
}

function calculate_booking_quote(
    mysqli $connection,
    mixed $serviceCode,
    mixed $length,
    mixed $effects,
    mixed $removal
): array {
    if (!is_string($serviceCode) || !is_array($effects) || !is_bool($removal)) {
        fail_request(400, 'La selección del servicio está incompleta o no es válida.');
    }
    $technique = catalog_service($connection, $serviceCode);

    if ($technique['usesLength']) {
        if (!is_int($length) || $length < 1 || $length > 8) {
            fail_request(400, 'Selecciona un largo válido del 1 al 8.');
        }
        $basePrice = CHINA_NAILS_CATALOG['lengths'][$length][$serviceCode];
    } else {
        if ($length !== null) {
            fail_request(400, 'Esta técnica no permite seleccionar largo.');
        }
        $basePrice = $technique['price'];
    }

    $effectPrices = [];
    foreach (CHINA_NAILS_CATALOG['effects'] as $price => $options) {
        foreach ($options as $option) {
            $effectPrices[$option['id']] = [
                'name' => $option['name'],
                'price' => (int) $price,
            ];
        }
    }

    $seenEffects = [];
    $effectTotal = 0;
    $effectNotes = [];
    foreach ($effects as $effect) {
        if (
            !is_array($effect) ||
            !isset($effect['id'], $effect['count']) ||
            !is_string($effect['id']) ||
            !is_int($effect['count']) ||
            $effect['count'] < 0 ||
            $effect['count'] > 10 ||
            !isset($effectPrices[$effect['id']]) ||
            isset($seenEffects[$effect['id']])
        ) {
            fail_request(400, 'Revisa los efectos seleccionados y su cantidad de uñas.');
        }
        $seenEffects[$effect['id']] = true;
        if ($effect['count'] > 0) {
            $effectTotal += $effectPrices[$effect['id']]['price'] * $effect['count'];
            $effectNotes[] = $effectPrices[$effect['id']]['name'] . ' x' . $effect['count'];
        }
    }

    $removalConfig = CHINA_NAILS_CATALOG['removals'][$serviceCode];
    $removalPrice = $removal ? $removalConfig['price'] : 0;
    $duration = $technique['durationMinutes']
        + ($removal ? $removalConfig['durationMinutes'] : 0);
    $noteParts = [];
    if ($technique['usesLength']) {
        $noteParts[] = 'Largo ' . $length;
    }
    if ($effectNotes !== []) {
        $noteParts[] = implode(', ', $effectNotes);
    }
    if ($removal) {
        $noteParts[] = 'Con retiro';
    }

    return [
        'serviceCode' => $serviceCode,
        'durationMinutes' => $duration,
        'basePrice' => $basePrice,
        'effectsPrice' => $effectTotal,
        'removalPrice' => $removalPrice,
        'total' => $basePrice + $effectTotal + $removalPrice,
        'notes' => $noteParts === [] ? null : implode(' | ', $noteParts),
    ];
}

function selection_from_query(): array
{
    $serviceCode = $_GET['serviceCode'] ?? null;
    $rawLength = $_GET['length'] ?? null;
    $rawEffects = $_GET['effects'] ?? '[]';
    $rawRemoval = $_GET['removal'] ?? null;
    if (
        !is_string($serviceCode) ||
        ($rawLength !== null && (!is_string($rawLength) || !preg_match('/^[1-8]$/', $rawLength))) ||
        !is_string($rawEffects) ||
        !in_array($rawRemoval, ['true', 'false'], true)
    ) {
        fail_request(400, 'Indica una selección de servicio válida para consultar disponibilidad.');
    }

    $effects = json_decode($rawEffects, true);
    if (!is_array($effects)) {
        fail_request(400, 'La lista de efectos no es válida.');
    }

    return [
        'serviceCode' => $serviceCode,
        'length' => $rawLength === null ? null : (int) $rawLength,
        'effects' => $effects,
        'removal' => $rawRemoval === 'true',
    ];
}

function mexico_datetime(string $date, string $time = '00:00'): ?DateTimeImmutable
{
    $timezone = new DateTimeZone(CHINA_NAILS_CATALOG['schedule']['timezone']);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", $timezone);
    return $parsed && $parsed->format('Y-m-d H:i') === "$date $time" ? $parsed : null;
}

function appointment_service_finished(string $date, string $time, int $durationMinutes): bool
{
    $start = mexico_datetime($date, $time);
    if ($start === null) {
        return false;
    }
    return $start->modify("+$durationMinutes minutes") <= new DateTimeImmutable(
        'now',
        new DateTimeZone(CHINA_NAILS_CATALOG['schedule']['timezone'])
    );
}

function business_slots(string $date, int $durationMinutes, array $appointments): array
{
    $day = mexico_datetime($date);
    if ($day === null) {
        fail_request(400, 'Indica una fecha válida.');
    }
    $schedule = CHINA_NAILS_CATALOG['schedule'];
    $now = new DateTimeImmutable('now', new DateTimeZone($schedule['timezone']));
    if (
        !in_array((int) $day->format('N'), $schedule['days'], true) ||
        $date < $now->format('Y-m-d')
    ) {
        return ['available' => [], 'total' => 0];
    }

    [$openHour, $openMinute] = array_map('intval', explode(':', $schedule['openingTime']));
    [$closeHour, $closeMinute] = array_map('intval', explode(':', $schedule['closingTime']));
    $open = $openHour * 60 + $openMinute;
    $close = $closeHour * 60 + $closeMinute;
    $currentMinute = ((int) $now->format('H')) * 60 + (int) $now->format('i');
    $available = [];
    $total = 0;

    for (
        $startMinute = $open;
        $startMinute + $durationMinutes <= $close;
        $startMinute += $schedule['slotMinutes']
    ) {
        if ($date === $now->format('Y-m-d') && $startMinute <= $currentMinute) {
            continue;
        }
        $total++;
        $slotStart = $startMinute * 60;
        $slotEnd = ($startMinute + $durationMinutes) * 60;
        $overlaps = false;
        foreach ($appointments as $appointment) {
            $appointmentStart = (int) $appointment['startSeconds'];
            $appointmentEnd = $appointmentStart + (int) $appointment['durationMinutes'] * 60;
            if ($appointmentStart < $slotEnd && $appointmentEnd > $slotStart) {
                $overlaps = true;
                break;
            }
        }
        if (!$overlaps) {
            $available[] = sprintf('%02d:%02d', intdiv($startMinute, 60), $startMinute % 60);
        }
    }

    return ['available' => $available, 'total' => $total];
}

function appointment_rows(mysqli $connection, string $where, string $types, array $values): array
{
    $statement = $connection->prepare(
        "SELECT a.id_cita AS id, a.fecha_cita AS date,
                TIME_FORMAT(a.hora_inicio, '%H:%i') AS time,
                a.duracion_minutos AS durationMinutes,
                a.precio_reservado AS price, a.estado AS status,
                a.notas AS notes,
                pago.monto AS amountPaid, pago.metodo_pago AS paymentMethod,
                s.nombre AS service, u.nombre_completo AS clientName,
                u.telefono AS phone
         FROM citas a
         JOIN usuarios u ON u.id_usuario = a.id_clienta
         JOIN servicios s ON s.codigo_servicio = a.codigo_servicio
         LEFT JOIN movimientos_financieros pago
           ON pago.id_cita = a.id_cita
          AND pago.tipo_movimiento = 'income'
         $where"
    );
    if ($types !== '') {
        $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (string) $row['id'];
        $row['durationMinutes'] = (int) $row['durationMinutes'];
        $row['serviceFinished'] = appointment_service_finished(
            (string) $row['date'],
            (string) $row['time'],
            $row['durationMinutes']
        );
        $row['notes'] = $row['notes'] === null ? null : (string) $row['notes'];
        $row['price'] = (float) $row['price'];
        $row['amountPaid'] = $row['amountPaid'] === null
            ? null
            : (float) $row['amountPaid'];
        $row['paymentMethod'] = $row['paymentMethod'] === null
            ? null
            : (string) $row['paymentMethod'];
    }
    unset($row);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$route = '/' . trim((string) ($_GET['path'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'];
$connection = null;
$appointmentLock = null;

try {
    $connection = china_nails_database_connection();

    if ($route === '/health' && $method === 'GET') {
        $connection->query('SELECT 1');
        respond(['status' => 'ok', 'database' => getenv('CN_DB_NAME')]);
    }

    if ($route === '/finance/summary' && $method === 'GET') {
        $tokenUser = authenticated_user();
        require_role($tokenUser, 'admin');
        $result = $connection->query(
            "SELECT
                COALESCE(SUM(CASE WHEN tipo_movimiento = 'income'
                    THEN monto ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN tipo_movimiento = 'expense'
                    THEN monto ELSE 0 END), 0) AS expenses,
                COALESCE(SUM(CASE WHEN tipo_movimiento = 'income'
                    AND metodo_pago = 'Efectivo' THEN monto ELSE 0 END), 0) AS incomeCash,
                COALESCE(SUM(CASE WHEN tipo_movimiento = 'income'
                    AND metodo_pago = 'Transferencia' THEN monto ELSE 0 END), 0) AS incomeTransfer,
                COALESCE(SUM(CASE WHEN tipo_movimiento = 'expense'
                    AND metodo_pago = 'Efectivo' THEN monto ELSE 0 END), 0) AS expenseCash,
                COALESCE(SUM(CASE WHEN tipo_movimiento = 'expense'
                    AND metodo_pago = 'Transferencia' THEN monto ELSE 0 END), 0) AS expenseTransfer
             FROM movimientos_financieros
             WHERE ocurrido_en >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
               AND ocurrido_en < DATE_ADD(
                    DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00'), INTERVAL 1 MONTH
               )"
        );
        $summary = $result->fetch_assoc();
        foreach ($summary as $key => $value) {
            $summary[$key] = (float) $value;
        }
        $summary['net'] = $summary['income'] - $summary['expenses'];
        $summary['netCash'] = $summary['incomeCash'] - $summary['expenseCash'];
        $summary['netTransfer'] =
            $summary['incomeTransfer'] - $summary['expenseTransfer'];
        respond(['summary' => $summary]);
    }

    if ($route === '/finance/expenses' && $method === 'GET') {
        $tokenUser = authenticated_user();
        require_role($tokenUser, 'admin');
        $result = $connection->query(
            "SELECT id_movimiento AS id, categoria AS category,
                    metodo_pago AS paymentMethod, monto AS amount,
                    referencia AS reference, notas AS notes, ocurrido_en AS occurredAt
             FROM movimientos_financieros
             WHERE tipo_movimiento = 'expense'
             ORDER BY ocurrido_en DESC, id_movimiento DESC
             LIMIT 200"
        );
        $expenses = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($expenses as &$expense) {
            $expense['id'] = (string) $expense['id'];
            $expense['amount'] = (float) $expense['amount'];
        }
        unset($expense);
        respond(['expenses' => $expenses]);
    }

    if ($route === '/finance/expenses' && $method === 'POST') {
        $tokenUser = authenticated_user();
        require_role($tokenUser, 'admin');
        $body = read_request_body();
        $amountValue = $body['amount'] ?? null;
        $category = $body['category'] ?? null;
        $paymentMethod = $body['paymentMethod'] ?? null;
        $notes = $body['notes'] ?? '';
        if (
            (!is_int($amountValue) && !is_float($amountValue) && !is_string($amountValue)) ||
            !is_numeric($amountValue) ||
            !is_finite((float) $amountValue) ||
            (float) $amountValue <= 0 ||
            (float) $amountValue > 9999999999.99 ||
            abs((float) $amountValue - round((float) $amountValue, 2)) > 0.00001 ||
            !is_string($category) ||
            trim($category) === '' ||
            mb_strlen(trim($category), 'UTF-8') > 100 ||
            !in_array($paymentMethod, ['Efectivo', 'Transferencia'], true) ||
            !is_string($notes) ||
            mb_strlen(trim($notes), 'UTF-8') > 1000
        ) {
            fail_request(400, 'Revisa el monto, la categoría, el método de pago y el detalle del egreso.');
        }

        $amount = round((float) $amountValue, 2);
        $category = trim($category);
        $paymentMethod = (string) $paymentMethod;
        $notes = trim($notes);
        $reference = null;
        $recordedBy = (int) $tokenUser['sub'];
        $statement = $connection->prepare(
            "INSERT INTO movimientos_financieros
               (tipo_movimiento, categoria, metodo_pago, id_cita, monto,
                referencia, notas, registrado_por, ocurrido_en)
             VALUES ('expense', ?, ?, NULL, ?, ?, ?, ?, NOW())"
        );
        $statement->bind_param(
            'ssdssi',
            $category,
            $paymentMethod,
            $amount,
            $reference,
            $notes,
            $recordedBy
        );
        $statement->execute();
        respond([
            'id' => (string) $connection->insert_id,
            'message' => 'El egreso quedó registrado.',
        ], 201);
    }

    if ($route === '/inventory' && $method === 'GET') {
        $tokenUser = authenticated_user();
        require_role($tokenUser, 'admin');
        $result = $connection->query(
            "SELECT insumo.id_insumo AS id, insumo.codigo_producto AS sku,
                    insumo.nombre AS name, insumo.descripcion AS description,
                    insumo.unidad AS unit, insumo.nivel_reorden AS reorderLevel,
                    COALESCE(SUM(CASE movimiento.direccion
                        WHEN 'in' THEN movimiento.cantidad
                        ELSE -movimiento.cantidad END), 0) AS stock
             FROM insumos insumo
             LEFT JOIN movimientos_inventario movimiento
               ON movimiento.id_insumo = insumo.id_insumo
             WHERE insumo.activo = TRUE
             GROUP BY insumo.id_insumo, insumo.codigo_producto, insumo.nombre,
                      insumo.descripcion, insumo.unidad, insumo.nivel_reorden
             ORDER BY insumo.nombre"
        );
        $items = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($items as &$item) {
            $item['id'] = (string) $item['id'];
            $item['stock'] = (float) $item['stock'];
            $item['reorderLevel'] = (float) $item['reorderLevel'];
        }
        unset($item);
        respond(['items' => $items]);
    }

    if ($route === '/inventory/entries' && $method === 'POST') {
        $tokenUser = authenticated_user();
        require_role($tokenUser, 'admin');
        $body = read_request_body();
        $itemIdValue = $body['itemId'] ?? null;
        $itemName = $body['itemName'] ?? null;
        $unit = $body['unit'] ?? null;
        $quantityValue = $body['quantity'] ?? null;
        $amountValue = $body['amount'] ?? null;
        $paymentMethod = $body['paymentMethod'] ?? null;
        $notes = $body['notes'] ?? '';
        $quantityIsValid =
            (is_int($quantityValue) || is_float($quantityValue) || is_string($quantityValue)) &&
            is_numeric($quantityValue) &&
            is_finite((float) $quantityValue) &&
            (float) $quantityValue > 0 &&
            (float) $quantityValue <= 999999999.999 &&
            abs((float) $quantityValue * 1000 - round((float) $quantityValue * 1000)) < 0.00001;
        $amountIsValid =
            (is_int($amountValue) || is_float($amountValue) || is_string($amountValue)) &&
            is_numeric($amountValue) &&
            is_finite((float) $amountValue) &&
            (float) $amountValue > 0 &&
            (float) $amountValue <= 9999999999.99 &&
            abs((float) $amountValue * 100 - round((float) $amountValue * 100)) < 0.00001;
        $isExistingItem =
            (is_int($itemIdValue) || (is_string($itemIdValue) && ctype_digit($itemIdValue))) &&
            (int) $itemIdValue > 0;
        $isNewItem =
            is_string($itemName) &&
            trim($itemName) !== '' &&
            mb_strlen(trim($itemName), 'UTF-8') <= 160 &&
            is_string($unit) &&
            trim($unit) !== '' &&
            mb_strlen(trim($unit), 'UTF-8') <= 60;
        if (
            !$quantityIsValid ||
            !$amountIsValid ||
            (!$isExistingItem && !$isNewItem) ||
            !in_array($paymentMethod, ['Efectivo', 'Transferencia'], true) ||
            !is_string($notes) ||
            mb_strlen(trim($notes), 'UTF-8') > 500
        ) {
            fail_request(400, 'Revisa el insumo, la cantidad, el costo, el método de pago y el detalle.');
        }

        $quantity = round((float) $quantityValue, 3);
        $amount = round((float) $amountValue, 2);
        $paymentMethod = (string) $paymentMethod;
        $notes = trim($notes);
        $recordedBy = (int) $tokenUser['sub'];
        $connection->begin_transaction();

        if ($isExistingItem) {
            $itemId = (int) $itemIdValue;
            $statement = $connection->prepare(
                'SELECT id_insumo FROM insumos WHERE id_insumo = ? AND activo = TRUE FOR UPDATE'
            );
            $statement->bind_param('i', $itemId);
            $statement->execute();
            $existingItem = $statement->get_result()->fetch_assoc();
            if (!$existingItem) {
                $connection->rollback();
                fail_request(404, 'No se encontró el insumo seleccionado.');
            }
            $itemName = (string) $existingItem['nombre'];
        } else {
            $itemName = trim($itemName);
            $unit = trim($unit);
            $statement = $connection->prepare(
                'INSERT INTO insumos (nombre, unidad) VALUES (?, ?)'
            );
            $statement->bind_param('ss', $itemName, $unit);
            $statement->execute();
            $itemId = (int) $connection->insert_id;
        }

        $movementType = 'adjustment';
        $direction = 'in';
        $inventoryNote = $notes === '' ? 'Entrada por compra de insumos' : $notes;
        $statement = $connection->prepare(
            'INSERT INTO movimientos_inventario
               (id_insumo, tipo_movimiento, direccion, cantidad, id_cita,
                nota, registrado_por, ocurrido_en)
             VALUES (?, ?, ?, ?, NULL, ?, ?, NOW())'
        );
        $statement->bind_param(
            'issdsi',
            $itemId,
            $movementType,
            $direction,
            $quantity,
            $inventoryNote,
            $recordedBy
        );
        $statement->execute();

        $category = 'Compra de insumos';
        $reference = mb_substr(
            'Entrada de inventario · ' . $itemName,
            0,
            120,
            'UTF-8'
        );
        $expenseNotes = $notes === '' ? null : $notes;
        $statement = $connection->prepare(
            "INSERT INTO movimientos_financieros
               (tipo_movimiento, categoria, metodo_pago, id_cita, monto,
                referencia, notas, registrado_por, ocurrido_en)
             VALUES ('expense', ?, ?, NULL, ?, ?, ?, ?, NOW())"
        );
        $statement->bind_param(
            'ssdssi',
            $category,
            $paymentMethod,
            $amount,
            $reference,
            $expenseNotes,
            $recordedBy
        );
        $statement->execute();
        $connection->commit();
        respond([
            'itemId' => (string) $itemId,
            'message' => 'La entrada de inventario y su egreso quedaron registrados.',
        ], 201);
    }

    if ($route === '/catalog' && $method === 'GET') {
        $result = $connection->query(
            'SELECT codigo_servicio FROM servicios WHERE activo = TRUE'
        );
        $activeCodes = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $activeCodes[strtoupper((string) $row['codigo_servicio'])] = true;
        }

        $techniques = [];
        foreach (CHINA_NAILS_CATALOG['techniques'] as $code => $technique) {
            if (!isset($activeCodes[$code])) {
                continue;
            }
            $lengthPrices = [];
            foreach (CHINA_NAILS_CATALOG['lengths'] as $length => $prices) {
                if (isset($prices[$code])) {
                    $lengthPrices[] = [
                        'length' => (int) $length,
                        'price' => $prices[$code],
                    ];
                }
            }
            $techniques[] = [
                'serviceCode' => $code,
                'name' => $technique['name'],
                'description' => $technique['description'],
                'durationMinutes' => $technique['durationMinutes'],
                'usesLength' => $technique['usesLength'],
                'price' => $technique['usesLength']
                    ? $lengthPrices[0]['price']
                    : $technique['price'],
                'lengthPrices' => $lengthPrices,
            ];
        }
        $effects = [];
        foreach (CHINA_NAILS_CATALOG['effects'] as $price => $options) {
            $effects[] = ['price' => (int) $price, 'options' => $options];
        }
        respond([
            'techniques' => $techniques,
            'effectsByPrice' => $effects,
            'removals' => CHINA_NAILS_CATALOG['removals'],
            'schedule' => CHINA_NAILS_CATALOG['schedule'],
        ]);
    }

    if ($route === '/services' && $method === 'GET') {
        $result = $connection->query(
            "SELECT codigo_servicio AS serviceCode, nombre AS name,
                    duracion_minutos AS durationMinutes, precio AS price
             FROM servicios WHERE activo = TRUE ORDER BY nombre"
        );
        $services = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($services as &$service) {
            $service['durationMinutes'] = (int) $service['durationMinutes'];
            $service['price'] = (float) $service['price'];
        }
        unset($service);
        respond(['services' => $services]);
    }

    if ($route === '/auth/register' && $method === 'POST') {
        $body = read_request_body();
        $username = $body['username'] ?? null;
        $email = $body['email'] ?? null;
        $password = $body['password'] ?? null;
        $fullName = $body['fullName'] ?? null;
        $phone = $body['phone'] ?? null;

        if (
            !is_string($username) ||
            !is_string($email) ||
            !is_string($password) ||
            !is_string($fullName) ||
            !is_string($phone) ||
            strlen(trim($username)) < 3 ||
            strlen(trim($username)) > 50 ||
            !filter_var(trim($email), FILTER_VALIDATE_EMAIL) ||
            strlen($password) < 10 ||
            strlen(trim($fullName)) < 2 ||
            strlen(trim($fullName)) > 120 ||
            strlen(trim($phone)) < 7 ||
            strlen(trim($phone)) > 30
        ) {
            fail_request(400, 'Revisa tus datos: el usuario debe tener al menos 3 caracteres, la contraseña 10, y correo, nombre y teléfono deben ser válidos.');
        }

        $username = trim($username);
        $email = strtolower(trim($email));
        $fullName = trim($fullName);
        $phone = trim($phone);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $connection->begin_transaction();
            $statement = $connection->prepare(
                'INSERT INTO usuarios
                   (nombre_usuario, correo, hash_contrasena, nombre_completo, telefono)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->bind_param('sssss', $username, $email, $passwordHash, $fullName, $phone);
            $statement->execute();
            $user = [
                'user_id' => $connection->insert_id,
                'username' => $username,
                'email' => $email,
                'full_name' => $fullName,
                'phone' => $phone,
                'role' => 'client',
            ];
            $statement = $connection->prepare(
                'INSERT INTO perfiles_clientas (id_clienta) VALUES (?)'
            );
            $statement->bind_param('i', $user['user_id']);
            $statement->execute();
            $connection->commit();
        } catch (mysqli_sql_exception $error) {
            $connection->rollback();
            if ($error->getCode() === 1062) {
                fail_request(409, 'Ese nombre de usuario o correo electrónico ya está registrado.');
            }
            throw $error;
        }

        respond([
            'user' => public_user($user),
            'token' => create_token($user),
        ], 201);
    }

    if ($route === '/auth/login' && $method === 'POST') {
        $body = read_request_body();
        $username = $body['username'] ?? null;
        $password = $body['password'] ?? null;
        if (!is_string($username) || !is_string($password) || trim($username) === '' || $password === '') {
            fail_request(400, 'Ingresa tu usuario y contraseña.');
        }

        $username = trim($username);
        $statement = $connection->prepare(
            "SELECT id_usuario AS user_id, nombre_usuario AS username,
                    correo AS email, hash_contrasena AS password_hash,
                    nombre_completo AS full_name, telefono AS phone, rol AS role
             FROM usuarios WHERE nombre_usuario = ? AND activo = TRUE LIMIT 1"
        );
        $statement->bind_param('s', $username);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            fail_request(401, 'Usuario o contraseña incorrectos.');
        }

        respond([
            'user' => public_user($user),
            'token' => create_token($user),
        ]);
    }

    if ($route === '/auth/me' && $method === 'GET') {
        $tokenUser = authenticated_user();
        $userId = (int) $tokenUser['sub'];
        $statement = $connection->prepare(
            "SELECT id_usuario AS user_id, nombre_usuario AS username,
                    correo AS email, nombre_completo AS full_name,
                    telefono AS phone, rol AS role
             FROM usuarios WHERE id_usuario = ? AND activo = TRUE LIMIT 1"
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        if (!$user) {
            fail_request(401, 'La cuenta no está disponible.');
        }
        respond(['user' => public_user($user)]);
    }

    if (str_starts_with($route, '/appointments')) {
        $tokenUser = authenticated_user();
        $userId = (int) $tokenUser['sub'];

        if ($route === '/appointments/availability' && $method === 'GET') {
            $date = $_GET['date'] ?? '';
            if (!is_string($date) || mexico_datetime($date) === null) {
                fail_request(400, 'Indica una fecha y servicio válidos.');
            }
            $selection = selection_from_query();
            $quote = calculate_booking_quote(
                $connection,
                $selection['serviceCode'],
                $selection['length'],
                $selection['effects'],
                $selection['removal']
            );
            $statement = $connection->prepare(
                "SELECT TIME_TO_SEC(hora_inicio) AS startSeconds,
                        duracion_minutos AS durationMinutes
                 FROM citas
                 WHERE fecha_cita = ? AND estado IN ('pending', 'confirmed')"
            );
            $statement->bind_param('s', $date);
            $statement->execute();
            $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
            $slots = business_slots($date, $quote['durationMinutes'], $rows);
            respond([
                'availableTimes' => $slots['available'],
                'totalSlots' => $slots['total'],
            ]);
        }

        if ($route === '/appointments/calendar' && $method === 'GET') {
            $month = $_GET['month'] ?? '';
            if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                fail_request(400, 'Indica un mes y servicio válidos.');
            }
            $selection = selection_from_query();
            $quote = calculate_booking_quote(
                $connection,
                $selection['serviceCode'],
                $selection['length'],
                $selection['effects'],
                $selection['removal']
            );

            $timezone = new DateTimeZone(CHINA_NAILS_CATALOG['schedule']['timezone']);
            $monthStart = new DateTimeImmutable("$month-01", $timezone);
            $nextMonth = $monthStart->modify('+1 month')->format('Y-m-d');
            $monthStartKey = $monthStart->format('Y-m-d');
            $statement = $connection->prepare(
                "SELECT DATE_FORMAT(fecha_cita, '%Y-%m-%d') AS appointmentDate,
                        TIME_TO_SEC(hora_inicio) AS startSeconds,
                        duracion_minutos AS durationMinutes
                 FROM citas
                 WHERE fecha_cita >= ? AND fecha_cita < ?
                   AND estado IN ('pending', 'confirmed')"
            );
            $statement->bind_param('ss', $monthStartKey, $nextMonth);
            $statement->execute();
            $appointmentsByDate = [];
            foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $appointment) {
                $appointmentsByDate[$appointment['appointmentDate']][] = $appointment;
            }

            $daysInMonth = (int) $monthStart->format('t');
            $availableTimesByDate = [];
            $totalSlotsByDate = [];
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateKey = $monthStart->setDate(
                    (int) $monthStart->format('Y'),
                    (int) $monthStart->format('m'),
                    $day
                )->format('Y-m-d');
                $slots = business_slots(
                    $dateKey,
                    $quote['durationMinutes'],
                    $appointmentsByDate[$dateKey] ?? []
                );
                $availableTimesByDate[$dateKey] = $slots['available'];
                $totalSlotsByDate[$dateKey] = $slots['total'];
            }
            respond([
                'availableTimesByDate' => $availableTimesByDate,
                'totalSlotsByDate' => $totalSlotsByDate,
            ]);
        }

        if ($route === '/appointments/mine' && $method === 'GET') {
            require_role($tokenUser, 'client');
            respond([
                'appointments' => appointment_rows(
                    $connection,
                    'WHERE a.id_clienta = ? ORDER BY a.fecha_cita DESC, a.hora_inicio DESC',
                    'i',
                    [$userId]
                ),
            ]);
        }

        if ($route === '/appointments' && $method === 'GET') {
            require_role($tokenUser, 'admin');
            respond([
                'appointments' => appointment_rows(
                    $connection,
                    'ORDER BY a.fecha_cita, a.hora_inicio',
                    '',
                    []
                ),
            ]);
        }

        if (
            $method === 'PATCH' &&
            preg_match('#^/appointments/([1-9][0-9]*)/confirm$#', $route, $matches)
        ) {
            require_role($tokenUser, 'admin');
            $appointmentId = (int) $matches[1];
            $statement = $connection->prepare(
                "UPDATE citas SET estado = 'confirmed'
                 WHERE id_cita = ? AND estado = 'pending'"
            );
            $statement->bind_param('i', $appointmentId);
            $statement->execute();
            if ($statement->affected_rows === 0) {
                $statement = $connection->prepare(
                    'SELECT estado FROM citas WHERE id_cita = ? LIMIT 1'
                );
                $statement->bind_param('i', $appointmentId);
                $statement->execute();
                $existingAppointment = $statement->get_result()->fetch_assoc();
                if (!$existingAppointment) {
                    fail_request(404, 'No se encontró la cita.');
                }
                fail_request(409, 'La cita ya no está pendiente de confirmación.');
            }
            respond([
                'id' => (string) $appointmentId,
                'status' => 'confirmed',
                'message' => 'La cita fue confirmada.',
            ]);
        }

        if (
            $method === 'PATCH' &&
            preg_match('#^/appointments/([1-9][0-9]*)/complete$#', $route, $matches)
        ) {
            require_role($tokenUser, 'admin');
            $appointmentId = (int) $matches[1];
            $body = read_request_body();
            $amountValue = $body['amount'] ?? null;
            $paymentMethod = $body['paymentMethod'] ?? null;
            if (
                (!is_int($amountValue) && !is_float($amountValue) && !is_string($amountValue)) ||
                !is_numeric($amountValue) ||
                !is_finite((float) $amountValue) ||
                (float) $amountValue <= 0 ||
                (float) $amountValue > 9999999999.99 ||
                abs((float) $amountValue - round((float) $amountValue, 2)) > 0.00001 ||
                !in_array($paymentMethod, ['Efectivo', 'Transferencia'], true)
            ) {
                fail_request(400, 'Indica un total válido y un método de pago permitido.');
            }

            $amount = round((float) $amountValue, 2);
            $connection->begin_transaction();
            $statement = $connection->prepare(
                "SELECT estado, fecha_cita AS date,
                        TIME_FORMAT(hora_inicio, '%H:%i') AS time,
                        duracion_minutos AS durationMinutes
                 FROM citas WHERE id_cita = ? FOR UPDATE"
            );
            $statement->bind_param('i', $appointmentId);
            $statement->execute();
            $appointment = $statement->get_result()->fetch_assoc();
            if (!$appointment) {
                $connection->rollback();
                fail_request(404, 'No se encontró la cita.');
            }
            if (!in_array($appointment['estado'], ['pending', 'confirmed'], true)) {
                $connection->rollback();
                fail_request(409, 'La cita ya fue completada o cancelada.');
            }
            if (!appointment_service_finished(
                (string) $appointment['date'],
                (string) $appointment['time'],
                (int) $appointment['durationMinutes']
            )) {
                $connection->rollback();
                fail_request(409, 'La cita solo se puede completar cuando termine el servicio.');
            }

            $statement = $connection->prepare(
                "UPDATE citas SET estado = 'completed'
                 WHERE id_cita = ? AND estado IN ('pending', 'confirmed')"
            );
            $statement->bind_param('i', $appointmentId);
            $statement->execute();

            $category = 'Pago de servicio';
            $reference = 'Cobro de cita #' . $appointmentId;
            $notes = null;
            $recordedBy = (int) $tokenUser['sub'];
            $statement = $connection->prepare(
                "INSERT INTO movimientos_financieros
                   (tipo_movimiento, categoria, metodo_pago, id_cita, monto,
                    referencia, notas, registrado_por, ocurrido_en)
                 VALUES ('income', ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $statement->bind_param(
                'ssidssi',
                $category,
                $paymentMethod,
                $appointmentId,
                $amount,
                $reference,
                $notes,
                $recordedBy
            );
            $statement->execute();
            $connection->commit();
            respond([
                'id' => (string) $appointmentId,
                'status' => 'completed',
                'amountPaid' => $amount,
                'paymentMethod' => $paymentMethod,
                'message' => 'El pago quedó registrado.',
            ]);
        }

        if (
            $method === 'PATCH' &&
            preg_match('#^/appointments/([1-9][0-9]*)/cancel$#', $route, $matches)
        ) {
            require_role($tokenUser, 'admin');
            $appointmentId = (int) $matches[1];
            $statement = $connection->prepare(
                "UPDATE citas SET estado = 'cancelled'
                 WHERE id_cita = ? AND estado IN ('pending', 'confirmed')"
            );
            $statement->bind_param('i', $appointmentId);
            $statement->execute();
            if ($statement->affected_rows === 0) {
                $statement = $connection->prepare(
                    'SELECT estado FROM citas WHERE id_cita = ? LIMIT 1'
                );
                $statement->bind_param('i', $appointmentId);
                $statement->execute();
                $existingAppointment = $statement->get_result()->fetch_assoc();
                if (!$existingAppointment) {
                    fail_request(404, 'No se encontró la cita.');
                }
                fail_request(409, 'La cita ya fue completada o cancelada.');
            }
            respond([
                'id' => (string) $appointmentId,
                'status' => 'cancelled',
                'message' => 'La cita quedó cancelada y se conservó en el historial.',
            ]);
        }

        if ($route === '/appointments' && $method === 'POST') {
            require_role($tokenUser, 'client');
            $body = read_request_body();
            $serviceCode = $body['serviceCode'] ?? null;
            $date = $body['date'] ?? null;
            $time = $body['time'] ?? null;
            if (
                !is_string($serviceCode) ||
                !is_string($date) ||
                mexico_datetime($date) === null ||
                !is_string($time) ||
                !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) ||
                !array_key_exists('removal', $body)
            ) {
                fail_request(400, 'Selecciona un servicio, fecha y horario válidos.');
            }
            $quote = calculate_booking_quote(
                $connection,
                $serviceCode,
                $body['length'] ?? null,
                $body['effects'] ?? null,
                $body['removal']
            );
            $now = new DateTimeImmutable(
                'now',
                new DateTimeZone(CHINA_NAILS_CATALOG['schedule']['timezone'])
            );
            if ($date < $now->format('Y-m-d')) {
                fail_request(400, 'La fecha de la cita no puede ser anterior a hoy.');
            }
            if (!in_array($time, business_slots($date, $quote['durationMinutes'], [])['available'], true)) {
                fail_request(400, 'Ese horario no está dentro del horario de atención para la duración seleccionada.');
            }

            $appointmentLock = 'china-nails:' . $date;
            $statement = $connection->prepare('SELECT GET_LOCK(?, 5) AS acquired');
            $statement->bind_param('s', $appointmentLock);
            $statement->execute();
            if ((int) $statement->get_result()->fetch_assoc()['acquired'] !== 1) {
                $appointmentLock = null;
                fail_request(409, 'No se pudo reservar el horario en este momento. Inténtalo de nuevo.');
            }

            $connection->begin_transaction();
            $statement = $connection->prepare(
                "SELECT TIME_TO_SEC(hora_inicio) AS startSeconds,
                        duracion_minutos AS durationMinutes
                 FROM citas
                 WHERE fecha_cita = ? AND estado IN ('pending', 'confirmed')"
            );
            $statement->bind_param('s', $date);
            $statement->execute();
            $appointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!in_array(
                $time,
                business_slots($date, $quote['durationMinutes'], $appointments)['available'],
                true
            )) {
                $connection->rollback();
                fail_request(409, 'Ese horario ya no está disponible. Elige otro.');
            }

            $statement = $connection->prepare(
                "INSERT INTO citas
                   (id_clienta, codigo_servicio, fecha_cita, hora_inicio,
                    duracion_minutos, precio_reservado, notas)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $price = (float) $quote['total'];
            $statement->bind_param(
                'isssids',
                $userId,
                $serviceCode,
                $date,
                $time,
                $quote['durationMinutes'],
                $price,
                $quote['notes']
            );
            $statement->execute();
            $appointmentId = (string) $connection->insert_id;
            $connection->commit();
            $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
            $statement->bind_param('s', $appointmentLock);
            $statement->execute();
            $appointmentLock = null;
            respond(['id' => $appointmentId, 'message' => 'Cita registrada.'], 201);
        }
    }

    fail_request(404, 'No se encontró el recurso solicitado.');
} catch (ChinaNailsDatabaseConfigurationException $error) {
    respond(['message' => $error->getMessage()], 500);
} catch (ApiException $error) {
    if ($connection instanceof mysqli) {
        try {
            $connection->rollback();
        } catch (Throwable $rollbackError) {
            error_log('China Nails API transaction rollback failed: ' . $rollbackError->getMessage());
        }
    }
    $status = $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    respond(['message' => $error->getMessage()], $status);
} catch (Throwable $error) {
    if ($connection instanceof mysqli) {
        try {
            $connection->rollback();
        } catch (Throwable $rollbackError) {
            error_log('China Nails API transaction rollback failed: ' . $rollbackError->getMessage());
        }
    }
    if (!($error instanceof ChinaNailsDatabaseConnectionException)) {
        error_log('China Nails PHP API request failed: ' . $error->getMessage());
    }
    respond([
        'message' => 'Ocurrió un error interno. Revisa la conexión y los registros de Apache.',
    ], 500);
} finally {
    if ($connection instanceof mysqli) {
        if ($appointmentLock !== null) {
            try {
                $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
                $statement->bind_param('s', $appointmentLock);
                $statement->execute();
            } catch (Throwable $error) {
                error_log('China Nails appointment lock release failed: ' . $error->getMessage());
            }
        }
        $connection->close();
    }
}
