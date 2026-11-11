<?php
declare(strict_types=1);

// Database settings. The defaults match a fresh XAMPP install (root, no password).
// On a hosted server, copy config.local.example.php to config.local.php and put
// the real credentials there; config.local.php is git-ignored so they are never committed.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'campus_lost_found');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

// Photo upload rules.
const UPLOAD_DIR = __DIR__ . '/../uploads';
const UPLOAD_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const UPLOAD_ALLOWED_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// Origins allowed to call the API (same allow-list idea as the class reference backend).
// http://localhost is the Android app (capacitor.config.ts sets androidScheme to http);
// :8100 is `ionic serve` in the browser.
const ALLOWED_ORIGINS = [
    'http://localhost',
    'http://localhost:8100',
    'http://127.0.0.1:8100',
    'http://localhost:4200',
    'https://localhost',
    'capacitor://localhost',
    'ionic://localhost',
];

function get_db_connection(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Read TIMESTAMP columns in UTC so the API can label them unambiguously.
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

// Sends the CORS and JSON headers, and ends OPTIONS preflight requests early.
function send_api_headers(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Owner-Token');
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Reads a JSON request body into an array (used when no photo is sent).
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        respond(400, ['success' => false, 'message' => 'Request body is required.']);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(400, ['success' => false, 'message' => 'Invalid JSON request body.']);
    }

    return $decoded;
}

// A UTC TIMESTAMP from MariaDB as ISO 8601, e.g. 2026-10-31T11:47:03+00:00.
function utc_to_iso(string $timestamp): string
{
    return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->format(DATE_ATOM);
}

// Returns the report when $token is its owner token; otherwise stops with 404 or 403.
function require_owner(PDO $pdo, int $id, string $token, string $action): array
{
    $stmt = $pdo->prepare('SELECT image_path, owner_token_hash FROM items WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        respond(404, ['success' => false, 'message' => 'Report not found.']);
    }
    // hash_equals compares in constant time, so the hash cannot be guessed byte by byte.
    if ($row['owner_token_hash'] === null || $token === '' || !hash_equals($row['owner_token_hash'], hash('sha256', $token))) {
        respond(403, ['success' => false, 'message' => "Only the person who submitted this report can $action it."]);
    }

    return $row;
}
