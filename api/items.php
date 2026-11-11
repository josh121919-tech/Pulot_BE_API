<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

send_api_headers();

const ITEM_TYPES = ['lost', 'found'];
const ITEM_STATUSES = ['open', 'resolved'];
const ITEM_CATEGORIES = ['Electronics', 'ID/Cards', 'Keys', 'Bags', 'Personal Belongings', 'Others'];
const SEARCH_MAX_LENGTH = 100;

const ITEM_COLUMNS = 'id, title, description, type, category, latitude, longitude, image_path, contact_info,
                      status, resolved_at, found_contact, found_at, created_at,
                      (SELECT COUNT(*) FROM claims WHERE claims.item_id = items.id) AS claim_count';

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

function public_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    $root = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');

    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $root . '/' . $path;
}

function format_item(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['latitude'] = (float)$row['latitude'];
    $row['longitude'] = (float)$row['longitude'];
    $row['claim_count'] = (int)$row['claim_count'];
    $row['image_url'] = public_url($row['image_path']);
    $row['created_at'] = utc_to_iso($row['created_at']);
    $row['resolved_at'] = $row['resolved_at'] === null ? null : utc_to_iso($row['resolved_at']);
    $row['found_at'] = $row['found_at'] === null ? null : utc_to_iso($row['found_at']);

    return $row;
}

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

function record_found_notice(PDO $pdo, int $id, mixed $contact): void
{
    $contact = trim(is_string($contact) ? $contact : '');
    if ($contact === '' || mb_strlen($contact) > 100) {
        respond(422, ['success' => false, 'message' => 'Validation failed.', 'errors' => ['found_contact' => 'Add your contact number or Facebook name (100 characters or fewer).']]);
    }

    $item = find_item($pdo, $id);
    if ($item === null) {
        respond(404, ['success' => false, 'message' => 'Report not found.']);
    }
    if ($item['type'] !== 'lost' || $item['status'] !== 'open') {
        respond(409, ['success' => false, 'message' => 'You can only answer an open Lost report.']);
    }

    $stmt = $pdo->prepare(
        'UPDATE items SET found_contact = :contact, found_at = CURRENT_TIMESTAMP
         WHERE id = :id AND found_at IS NULL'
    );
    $stmt->execute(['contact' => $contact, 'id' => $id]);
    if ($stmt->rowCount() === 0) {
        respond(409, ['success' => false, 'message' => 'Someone has already told the owner they found this.']);
    }

    respond(200, [
        'success' => true,
        'message' => 'Sent. The owner gets an alert in PickApp.',
        'data' => format_item(find_item($pdo, $id)),
    ]);
}

function read_id(): int
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        respond(400, ['success' => false, 'message' => 'Add the report id to the URL, e.g. items.php?id=5.']);
    }

    return $id;
}

function delete_photo(?string $path): void
{
    if ($path !== null && $path !== '' && is_file(__DIR__ . '/../' . $path)) {
        unlink(__DIR__ . '/../' . $path);
    }
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
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($contentLength > 0 && empty($_POST) && empty($_FILES) && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
                respond(413, ['success' => false, 'message' => 'The upload is too large for the server.']);
            }

            $isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
            $input = $isJson ? read_json_body() : $_POST;

            if (isset($_GET['id'])) {
                $id = read_id();
                $old = require_owner($pdo, $id, (string)($input['owner_token'] ?? ''), 'edit');
                $item = validate_item($input);
                $savedPhoto = $isJson ? null : save_uploaded_photo();
                $removePhoto = ($input['remove_photo'] ?? '') === '1';
                $item['image_path'] = $savedPhoto ?? ($removePhoto ? null : $old['image_path']);
                $item['id'] = $id;

                $stmt = $pdo->prepare(
                    'UPDATE items SET title = :title, description = :description, type = :type, category = :category,
                            latitude = :latitude, longitude = :longitude, image_path = :image_path, contact_info = :contact_info
                     WHERE id = :id'
                );
                $stmt->execute($item);
                if ($item['image_path'] !== $old['image_path']) {
                    delete_photo($old['image_path']);
                }
                $savedPhoto = null;

                respond(200, ['success' => true, 'message' => 'Report updated.', 'data' => format_item(find_item($pdo, $id))]);
            }

            $item = validate_item($input);
            $savedPhoto = $isJson ? null : save_uploaded_photo();
            $item['image_path'] = $savedPhoto;

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
            $id = read_id();

            $input = read_json_body();
            if (array_key_exists('found_contact', $input)) {
                record_found_notice($pdo, $id, $input['found_contact']);
            }
            $status = $input['status'] ?? '';
            $token = (string)($input['owner_token'] ?? '');
            if (!in_array($status, ITEM_STATUSES, true)) {
                respond(422, ['success' => false, 'message' => 'Validation failed.', 'errors' => ['status' => 'Status must be "open" or "resolved".']]);
            }

            require_owner($pdo, $id, $token, 'change');

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

        case 'DELETE':
            $id = read_id();
            $old = require_owner($pdo, $id, (string)(read_json_body()['owner_token'] ?? ''), 'delete');

            $pdo->prepare('DELETE FROM claims WHERE item_id = :id')->execute(['id' => $id]);
            $stmt = $pdo->prepare('DELETE FROM items WHERE id = :id');
            $stmt->execute(['id' => $id]);
            delete_photo($old['image_path']);

            respond(200, ['success' => true, 'message' => 'Report deleted.']);

        default:
            header('Allow: GET, POST, PUT, DELETE, OPTIONS');
            respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    if ($savedPhoto !== null && is_file(__DIR__ . '/../' . $savedPhoto)) {
        unlink(__DIR__ . '/../' . $savedPhoto);
    }
    error_log('[lost-found] ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Server error. Please try again later.']);
}
