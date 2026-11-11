<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

send_api_headers();

const CLAIM_PROOF_MIN = 5;
const CLAIM_PROOF_MAX = 500;

function read_item_id(): int
{
    $id = filter_var($_GET['item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        respond(400, ['success' => false, 'message' => 'Add the report id to the URL, e.g. claims.php?item_id=5.']);
    }

    return $id;
}

function format_claim(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['item_id'] = (int)$row['item_id'];
    $row['created_at'] = utc_to_iso($row['created_at']);

    return $row;
}

try {
    $pdo = get_db_connection();
    $itemId = read_item_id();

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            require_owner($pdo, $itemId, (string)($_SERVER['HTTP_X_OWNER_TOKEN'] ?? ''), 'read the claims on');
            $stmt = $pdo->prepare(
                'SELECT id, item_id, contact, proof, created_at FROM claims
                 WHERE item_id = :id ORDER BY created_at DESC, id DESC'
            );
            $stmt->execute(['id' => $itemId]);

            respond(200, ['success' => true, 'data' => array_map('format_claim', $stmt->fetchAll())]);

        case 'POST':
            $input = read_json_body();
            $contact = trim(is_string($input['contact'] ?? null) ? $input['contact'] : '');
            $proof = trim(is_string($input['proof'] ?? null) ? $input['proof'] : '');

            $errors = [];
            if ($contact === '' || mb_strlen($contact) > 100) {
                $errors['contact'] = 'Add your contact number or Facebook name (100 characters or fewer).';
            }
            if (mb_strlen($proof) < CLAIM_PROOF_MIN || mb_strlen($proof) > CLAIM_PROOF_MAX) {
                $errors['proof'] = 'Describe something only the owner would know (5 to 500 characters).';
            }
            if ($errors) {
                respond(422, ['success' => false, 'message' => 'Validation failed.', 'errors' => $errors]);
            }

            $stmt = $pdo->prepare('SELECT type, status FROM items WHERE id = :id');
            $stmt->execute(['id' => $itemId]);
            $item = $stmt->fetch();
            if ($item === false) {
                respond(404, ['success' => false, 'message' => 'Report not found.']);
            }
            if ($item['type'] !== 'found' || $item['status'] !== 'open') {
                respond(409, ['success' => false, 'message' => 'You can only claim an open Found report.']);
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO claims (item_id, contact, proof) VALUES (:item_id, :contact, :proof)');
                $stmt->execute(['item_id' => $itemId, 'contact' => $contact, 'proof' => $proof]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    respond(409, ['success' => false, 'message' => 'You have already claimed this item.']);
                }
                throw $e;
            }

            respond(201, [
                'success' => true,
                'message' => 'Claim sent. The person who found it gets an alert and can contact you.',
            ]);

        default:
            header('Allow: GET, POST, OPTIONS');
            respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    error_log('[lost-found] ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Server error. Please try again later.']);
}
