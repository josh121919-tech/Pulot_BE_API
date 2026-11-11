<?php
declare(strict_types=1);

// PickApp (campus lost and found) REST endpoint.
//   GET  /api/items.php              -> list every report, newest first
//        optional filters, combinable: ?type=lost|found  &status=open|resolved
//        &category=Keys  &q=keyword (title or description)  &from=/&to= (ISO 8601 date-times)
//   POST /api/items.php              -> create a report (multipart/form-data with optional
//                                       "photo" file, or a JSON body without a photo).
//                                       The reply includes owner_token, shown only this once.
//   PUT  /api/items.php?id=5         -> JSON { "status": "resolved"|"open", "owner_token": "..." }
//                                       marks the report resolved (or reopens it); only the
//                                       phone that created the report has the token.
// Every response is JSON shaped as { success, message?, data?, errors? }.

require_once __DIR__ . '/config.php';

send_api_headers();

const ITEM_TYPES = ['lost', 'found'];
const ITEM_STATUSES = ['open', 'resolved'];
const ITEM_CATEGORIES = ['Electronics', 'ID/Cards', 'Keys', 'Bags', 'Personal Belongings', 'Others'];
const SEARCH_MAX_LENGTH = 100;

// Public columns only: owner_token_hash never leaves the server.
const ITEM_COLUMNS = 'id, title, description, type, category, latitude, longitude, image_path, contact_info,
                      status, resolved_at, created_at';

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

// Checks every field and returns clean values, or stops with 422 and a per-field error list.
function validate_item(array $input): array
{
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $type = trim((string)($input['type'] ?? ''));
    $category = trim((string)($input['category'] ?? ''));
    $contact = trim((string)($input['contact_info'] ?? ''));
    $latitude = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

    $errors = [];
    if ($title === '') $errors['title'] = 'Title is required.';
    elseif (mb_strlen($title) > 150) $errors['title'] = 'Title must be 150 characters or fewer.';
    if (mb_strlen($description) > 2000) $errors['description'] = 'Description must be 2000 characters or fewer.';
    if (!in_array($type, ITEM_TYPES, true)) $errors['type'] = 'Type must be "lost" or "found".';
    if (!in_array($category, ITEM_CATEGORIES, true)) $errors['category'] = 'Choose one of: ' . implode(', ', ITEM_CATEGORIES) . '.';
    if ($contact === '') $errors['contact_info'] = 'Contact info is required.';
    elseif (mb_strlen($contact) > 100) $errors['contact_info'] = 'Contact info must be 100 characters or fewer.';
    if ($latitude === false || $latitude < -90 || $latitude > 90) $errors['latitude'] = 'Latitude must be a number from -90 to 90.';
    if ($longitude === false || $longitude < -180 || $longitude > 180) $errors['longitude'] = 'Longitude must be a number from -180 to 180.';

    if ($errors) {
        respond(422, ['success' => false, 'message' => 'Validation failed.', 'errors' => $errors]);
    }

    return [
        'title' => $title,
        'description' => $description === '' ? null : $description,
        'type' => $type,
        'category' => $category,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'contact_info' => $contact,
    ];
}

// Validates the uploaded "photo" and moves it into /uploads under a random name.
// Returns the relative path to store in the database, or null when no photo was sent.
function save_uploaded_photo(): ?string
{
    $file = $_FILES['photo'] ?? null;
    if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > UPLOAD_MAX_BYTES) {
        respond(413, ['success' => false, 'message' => 'Photo is too large. The limit is 5 MB.']);
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        respond(400, ['success' => false, 'message' => 'Photo upload failed. Please try again.']);
    }

    // Trust the file's real contents, never the client-supplied name or MIME type.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(UPLOAD_ALLOWED_TYPES[$mime]) || getimagesize($file['tmp_name']) === false) {
        respond(415, ['success' => false, 'message' => 'Photo must be a JPEG, PNG or WebP image.']);
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
        throw new RuntimeException('Upload folder could not be created.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . UPLOAD_ALLOWED_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $fileName)) {
        throw new RuntimeException('Photo could not be saved.');
    }

    return 'uploads/' . $fileName;
}

// Turns a stored relative path (uploads/x.jpg) into a full URL the app can load.
function public_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    // SCRIPT_NAME is /<project>/api/items.php, so two dirname() calls give the project root.
    $root = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');

    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $root . '/' . $path;
}

// Casts database strings to proper JSON types and adds image_url.
function format_item(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['latitude'] = (float)$row['latitude'];
    $row['longitude'] = (float)$row['longitude'];
    $row['image_url'] = public_url($row['image_path']);
    // e.g. 2026-10-31T11:47:03+00:00, so the phone shows it in its own time zone.
    $row['created_at'] = utc_to_iso($row['created_at']);
    $row['resolved_at'] = $row['resolved_at'] === null ? null : utc_to_iso($row['resolved_at']);

    return $row;
}

function utc_to_iso(string $timestamp): string
{
    return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->format(DATE_ATOM);
}

// Reads an ISO 8601 date-time filter (e.g. 2026-10-31T00:00:00+08:00) as a UTC timestamp string,
// so "today" means the phone's own midnight whatever time zone the server is in.
function parse_date_filter(string $name): ?string
{
    $value = trim((string)($_GET[$name] ?? ''));
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value)
        ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vP', $value);
    if ($date === false) {
        respond(400, ['success' => false, 'message' => "The $name filter must be an ISO 8601 date-time, e.g. 2026-10-31T00:00:00+08:00."]);
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

// Builds the WHERE clause for GET from the optional filters in the query string.
function build_filters(): array
{
    $where = [];
    $params = [];

    $type = $_GET['type'] ?? '';
    if ($type !== '') {
        if (!in_array($type, ITEM_TYPES, true)) {
            respond(400, ['success' => false, 'message' => 'The type filter must be "lost" or "found".']);
        }
        $where[] = 'type = :type';
        $params['type'] = $type;
    }

    $status = $_GET['status'] ?? '';
    if ($status !== '') {
        if (!in_array($status, ITEM_STATUSES, true)) {
            respond(400, ['success' => false, 'message' => 'The status filter must be "open" or "resolved".']);
        }
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    $category = $_GET['category'] ?? '';
    if ($category !== '') {
        if (!in_array($category, ITEM_CATEGORIES, true)) {
            respond(400, ['success' => false, 'message' => 'Unknown category. Use one of: ' . implode(', ', ITEM_CATEGORIES) . '.']);
        }
        $where[] = 'category = :category';
        $params['category'] = $category;
    }

    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '') {
        if (mb_strlen($search) > SEARCH_MAX_LENGTH) {
            respond(400, ['success' => false, 'message' => 'Search text must be ' . SEARCH_MAX_LENGTH . ' characters or fewer.']);
        }
        // Escape LIKE wildcards so "100%" searches for the text, not "100 followed by anything".
        $where[] = '(title LIKE :q_title OR description LIKE :q_description)';
        $params['q_title'] = $params['q_description'] = '%' . addcslashes($search, '\\%_') . '%';
    }

    $from = parse_date_filter('from');
    if ($from !== null) {
        $where[] = 'created_at >= :from';
        $params['from'] = $from;
    }
    $to = parse_date_filter('to');
    if ($to !== null) {
        $where[] = 'created_at < :to';
        $params['to'] = $to;
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function find_item(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT ' . ITEM_COLUMNS . ' FROM items WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

$savedPhoto = null;

try {
    $pdo = get_db_connection();

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            [$where, $params] = build_filters();
            $stmt = $pdo->prepare('SELECT ' . ITEM_COLUMNS . " FROM items $where ORDER BY created_at DESC, id DESC");
            $stmt->execute($params);

            respond(200, ['success' => true, 'data' => array_map('format_item', $stmt->fetchAll())]);

        case 'POST':
            // If the body was bigger than PHP's post_max_size, PHP silently drops every field.
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($contentLength > 0 && empty($_POST) && empty($_FILES) && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
                respond(413, ['success' => false, 'message' => 'The upload is too large for the server.']);
            }

            $isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
            $item = validate_item($isJson ? read_json_body() : $_POST);
            $savedPhoto = $isJson ? null : save_uploaded_photo();
            $item['image_path'] = $savedPhoto;

            // A random secret for the reporter's phone; the database keeps only its hash,
            // so even someone who reads the table cannot resolve other people's reports.
            $ownerToken = bin2hex(random_bytes(32));
            $item['owner_token_hash'] = hash('sha256', $ownerToken);

            $stmt = $pdo->prepare(
                'INSERT INTO items (title, description, type, category, latitude, longitude, image_path, contact_info, owner_token_hash)
                 VALUES (:title, :description, :type, :category, :latitude, :longitude, :image_path, :contact_info, :owner_token_hash)'
            );
            $stmt->execute($item);

            $created = format_item(find_item($pdo, (int)$pdo->lastInsertId()));
            $created['owner_token'] = $ownerToken;

            respond(201, [
                'success' => true,
                'message' => 'Report submitted.',
                'data' => $created,
            ]);

        case 'PUT':
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                respond(400, ['success' => false, 'message' => 'Add the report id to the URL, e.g. items.php?id=5.']);
            }

            $input = read_json_body();
            $status = $input['status'] ?? '';
            $token = (string)($input['owner_token'] ?? '');
            if (!in_array($status, ITEM_STATUSES, true)) {
                respond(422, ['success' => false, 'message' => 'Validation failed.', 'errors' => ['status' => 'Status must be "open" or "resolved".']]);
            }

            $stmt = $pdo->prepare('SELECT owner_token_hash FROM items WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $storedHash = $stmt->fetchColumn();
            if ($storedHash === false) {
                respond(404, ['success' => false, 'message' => 'Report not found.']);
            }
            // hash_equals compares in constant time, so the hash cannot be guessed byte by byte.
            if ($storedHash === null || $token === '' || !hash_equals($storedHash, hash('sha256', $token))) {
                respond(403, ['success' => false, 'message' => 'Only the person who submitted this report can change its status.']);
            }

            $stmt = $pdo->prepare(
                "UPDATE items
                 SET status = :status, resolved_at = IF(:resolved = 1, COALESCE(resolved_at, CURRENT_TIMESTAMP), NULL)
                 WHERE id = :id"
            );
            $stmt->execute(['status' => $status, 'resolved' => $status === 'resolved' ? 1 : 0, 'id' => $id]);

            respond(200, [
                'success' => true,
                'message' => $status === 'resolved' ? 'Report marked as resolved.' : 'Report reopened.',
                'data' => format_item(find_item($pdo, $id)),
            ]);

        default:
            header('Allow: GET, POST, PUT, OPTIONS');
            respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    // Don't leave an orphan photo behind if the database insert failed.
    if ($savedPhoto !== null && is_file(__DIR__ . '/../' . $savedPhoto)) {
        unlink(__DIR__ . '/../' . $savedPhoto);
    }
    error_log('[lost-found] ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Server error. Please try again later.']);
}
