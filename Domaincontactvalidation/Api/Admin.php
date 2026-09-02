<?php
/**
 * FOSSBilling Contact Validation (https://fossbilling.org/)
 *
 * Displays ICANN / NIS2-style registrant contact validation tracking
 * Written in 2026 by Taras Kondratyuk (https://namingo.org)
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Box\Mod\Domaincontactvalidation\Api;

use FOSSBilling\InformationException;

/**
 * Admin API for ICANN / NIS2 registrant contact validation.
 */
class Admin extends \FOSSBilling\Api\AbstractApi
{
    private const TABLE = 'domain_contact_validation';

    public function stats(array $data = []): array
    {
        $this->ensureTableExists();
        $db = $this->getDi()['db'];

        return [
            'total_clients' => (int) $db->getCell('SELECT COUNT(*) FROM client'),
            'validated' => (int) $db->getCell('SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE is_validated = 1'),
            'unvalidated' => (int) $db->getCell(
                'SELECT COUNT(*)
                 FROM client c
                 LEFT JOIN `' . self::TABLE . '` v ON v.client_id = c.id
                 WHERE v.id IS NULL OR v.is_validated = 0'
            ),
            'tokens_open' => (int) $db->getCell(
                "SELECT COUNT(*)
                 FROM `" . self::TABLE . "`
                 WHERE is_validated = 0
                   AND validation_token IS NOT NULL
                   AND validation_token <> ''"
            ),
        ];
    }

    public function list_contacts(array $data = []): array
    {
        $this->ensureTableExists();

        $validated = filter_var($data['validated'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $search = trim((string) ($data['q'] ?? ''));
        $page = max(1, (int) ($data['page'] ?? 1));
        $perPage = (int) ($data['per_page'] ?? 25);
        $perPage = $perPage > 0 && $perPage <= 200 ? $perPage : 25;

        [$whereSql, $params] = $this->buildWhereSql($validated, $search);

        $db = $this->getDi()['db'];
        $total = (int) $db->getCell(
            'SELECT COUNT(*)
             FROM client c
             LEFT JOIN `' . self::TABLE . '` v ON v.client_id = c.id
             ' . $whereSql,
            $params
        );

        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $order = $validated ? 'v.validation_checked_at DESC, c.id DESC' : 'c.id DESC';

        $rows = $db->getAll(
            'SELECT
                c.id,
                c.first_name,
                c.last_name,
                c.company,
                c.email,
                c.phone_cc,
                c.phone,
                c.country,
                c.status,
                v.validation_method,
                v.validation_checked_at,
                v.validation_token,
                COALESCE(v.is_validated, 0) AS is_validated
             FROM client c
             LEFT JOIN `' . self::TABLE . '` v ON v.client_id = c.id
             ' . $whereSql . '
             ORDER BY ' . $order . '
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        $rows = array_map(fn (array $row): array => $this->normalizeListRow($row), $rows ?: []);

        return [
            'list' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $totalPages,
            'page_numbers' => $this->pageNumbers($page, $totalPages),
            'validated' => $validated,
            'q' => $search,
        ];
    }

    public function get_contact(array $data): array
    {
        $this->ensureTableExists();

        $clientId = (int) ($data['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw new InformationException('Missing or invalid client ID.');
        }

        $client = $this->getClient($clientId);
        $validation = $this->getValidation($clientId);

        return [
            'client' => $this->normalizeClientRow($client),
            'validation' => $this->normalizeValidationRow($validation),
            'is_validated' => $validation && (int) ($validation['is_validated'] ?? 0) === 1,
        ];
    }

    public function validate(array $data): array
    {
        $this->ensureTableExists();
        $client = $this->requireClient((int) ($data['client_id'] ?? 0));
        $method = $this->cleanMethod((string) ($data['validation_method'] ?? 'admin_manual'));
        $note = $this->cleanNote((string) ($data['note'] ?? ''));

        $logLine = $this->adminLogLine('Validated manually', $note, $method);
        $this->upsertValidation((int) $client['id'], [
            'is_validated' => 1,
            'validation_method' => $method,
            'validation_token' => null,
            'validation_log' => $this->appendValidationLog((int) $client['id'], $logLine),
        ]);

        $this->activity('Domain Contact Validation: validated contact for client #' . (int) $client['id'] . ' (' . $this->clientDisplayName($client) . ')', (int) $client['id']);

        return [
            'success' => true,
            'message' => 'Client #' . (int) $client['id'] . ' has been marked as validated.',
        ];
    }

    public function unvalidate(array $data): array
    {
        $this->ensureTableExists();
        $client = $this->requireClient((int) ($data['client_id'] ?? 0));
        $method = $this->cleanMethod((string) ($data['validation_method'] ?? 'admin_manual'));
        $note = $this->cleanNote((string) ($data['note'] ?? ''));

        $logLine = $this->adminLogLine('Marked as unvalidated', $note, $method);
        $this->upsertValidation((int) $client['id'], [
            'is_validated' => 0,
            'validation_method' => $method,
            'validation_token' => null,
            'validation_log' => $this->appendValidationLog((int) $client['id'], $logLine),
        ]);

        $this->activity('Domain Contact Validation: marked contact as unvalidated for client #' . (int) $client['id'] . ' (' . $this->clientDisplayName($client) . ')', (int) $client['id']);

        return [
            'success' => true,
            'message' => 'Client #' . (int) $client['id'] . ' has been marked as unvalidated.',
        ];
    }

    public function reset_token(array $data): array
    {
        $this->ensureTableExists();
        $client = $this->requireClient((int) ($data['client_id'] ?? 0));
        $note = $this->cleanNote((string) ($data['note'] ?? ''));
        $token = $this->generateToken();

        $logLine = $this->adminLogLine('Generated validation token', $note, 'token_generated');
        $this->upsertValidation((int) $client['id'], [
            'is_validated' => 0,
            'validation_method' => 'token_generated',
            'validation_token' => $token,
            'validation_log' => $this->appendValidationLog((int) $client['id'], $logLine),
        ]);

        $this->activity('Domain Contact Validation: generated validation token for client #' . (int) $client['id'] . ' (' . $this->clientDisplayName($client) . ')', (int) $client['id']);

        return [
            'success' => true,
            'message' => 'A new validation token has been generated and the client is now pending validation.',
            'token' => $token,
        ];
    }

    public function save_note(array $data): array
    {
        $this->ensureTableExists();
        $client = $this->requireClient((int) ($data['client_id'] ?? 0));
        $note = $this->cleanNote((string) ($data['note'] ?? ''));

        if ($note === '') {
            throw new InformationException('Cannot save an empty note.');
        }

        $existing = $this->getValidation((int) $client['id']);
        $method = $this->cleanMethod((string) ($existing['validation_method'] ?? ($data['validation_method'] ?? 'admin_manual')));
        $logLine = $this->adminLogLine('Added admin note', $note, $method);

        $this->upsertValidation((int) $client['id'], [
            'is_validated' => $existing ? (int) $existing['is_validated'] : 0,
            'validation_method' => $method,
            'validation_token' => $existing['validation_token'] ?? null,
            'validation_log' => $this->appendValidationLog((int) $client['id'], $logLine),
        ]);

        $this->activity('Domain Contact Validation: added validation note for client #' . (int) $client['id'] . ' (' . $this->clientDisplayName($client) . ')', (int) $client['id']);

        return [
            'success' => true,
            'message' => 'Note saved for client #' . (int) $client['id'] . '.',
        ];
    }

    private function ensureTableExists(): void
    {
        $exists = (int) $this->getDi()['db']->getCell(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name",
            [':table_name' => self::TABLE]
        );

        if ($exists <= 0) {
            throw new InformationException('The table domain_contact_validation does not exist. Install/enable the module first or create the table manually.');
        }
    }

    private function buildWhereSql(bool $validated, string $search): array
    {
        $where = $validated ? 'WHERE v.is_validated = 1' : 'WHERE (v.id IS NULL OR v.is_validated = 0)';
        $params = [];

        if ($search !== '') {
            $params[':like'] = '%' . $search . '%';
            $searchParts = [
                'c.first_name LIKE :like',
                'c.last_name LIKE :like',
                'c.company LIKE :like',
                'c.email LIKE :like',
                'c.phone LIKE :like',
                'c.address_1 LIKE :like',
                'c.city LIKE :like',
                'c.state LIKE :like',
                'c.country LIKE :like',
            ];

            if (ctype_digit($search)) {
                $params[':client_id'] = (int) $search;
                array_unshift($searchParts, 'c.id = :client_id');
            }

            $where .= ' AND (' . implode(' OR ', $searchParts) . ')';
        }

        return [$where, $params];
    }

    private function getClient(int $clientId): array
    {
        $row = $this->getDi()['db']->getRow(
            'SELECT id, first_name, last_name, company, email, phone_cc, phone, address_1, address_2, city, state, postcode, country, status, created_at, updated_at
             FROM client
             WHERE id = :id',
            [':id' => $clientId]
        );

        if (!$row) {
            throw new InformationException('Client not found.');
        }

        return $row;
    }

    private function requireClient(int $clientId): array
    {
        if ($clientId <= 0) {
            throw new InformationException('Missing or invalid client ID.');
        }

        return $this->getClient($clientId);
    }

    private function getValidation(int $clientId): ?array
    {
        $row = $this->getDi()['db']->getRow(
            'SELECT id, client_id, is_validated, validation_checked_at, validation_method, validation_token, validation_log, created_at, updated_at
             FROM `' . self::TABLE . '`
             WHERE client_id = :client_id',
            [':client_id' => $clientId]
        );

        return $row ?: null;
    }

    private function upsertValidation(int $clientId, array $data): void
    {
        $this->getDi()['db']->exec(
            'INSERT INTO `' . self::TABLE . '`
                (client_id, is_validated, validation_checked_at, validation_method, validation_token, validation_log, created_at, updated_at)
             VALUES
                (:client_id, :is_validated, NOW(), :validation_method, :validation_token, :validation_log, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                is_validated = VALUES(is_validated),
                validation_checked_at = VALUES(validation_checked_at),
                validation_method = VALUES(validation_method),
                validation_token = VALUES(validation_token),
                validation_log = VALUES(validation_log),
                updated_at = NOW()',
            [
                ':client_id' => $clientId,
                ':is_validated' => (int) ($data['is_validated'] ?? 0),
                ':validation_method' => $data['validation_method'] ?? null,
                ':validation_token' => $data['validation_token'] ?? null,
                ':validation_log' => $data['validation_log'] ?? null,
            ]
        );
    }

    private function appendValidationLog(int $clientId, string $line): string
    {
        $existing = $this->getValidation($clientId);
        $old = $existing && !empty($existing['validation_log']) ? rtrim((string) $existing['validation_log']) : '';

        return $old === '' ? $line : $old . "\n" . $line;
    }

    private function adminLogLine(string $action, string $note, string $method): string
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $action . ' by ' . $this->currentAdminLabel() . ' using method: ' . $method;
        if ($note !== '') {
            $line .= ' | Note: ' . preg_replace('/\s+/', ' ', $note);
        }

        return $line;
    }

    private function currentAdminLabel(): string
    {
        try {
            $admin = $this->getDi()['loggedin_admin'];
            $name = trim((string) (method_exists($admin, 'getName') ? $admin->getName() : ($admin->name ?? '')));
            $email = trim((string) (method_exists($admin, 'getEmail') ? $admin->getEmail() : ($admin->email ?? '')));
            $id = (int) (method_exists($admin, 'getId') ? $admin->getId() : ($admin->id ?? 0));

            return ($name ?: $email ?: 'admin') . ($id > 0 ? ' (#' . $id . ')' : '');
        } catch (\Throwable) {
            return 'admin';
        }
    }

    private function activity(string $message, ?int $clientId = null): void
    {
        try {
            $admin = $this->getDi()['loggedin_admin'];
            $this->getDi()['db']->exec(
                'INSERT INTO activity_system (priority, admin_id, client_id, message, ip, created_at)
                 VALUES (:priority, :admin_id, :client_id, :message, :ip, NOW())',
                [
                    ':priority' => 6,
                    ':admin_id' => (int) (method_exists($admin, 'getId') ? $admin->getId() : ($admin->id ?? 0)),
                    ':client_id' => $clientId,
                    ':message' => $message,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );
        } catch (\Throwable) {
            // Activity logging must never block the validation workflow.
        }
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function cleanMethod(string $method): string
    {
        $method = trim($method) ?: 'admin_manual';
        $method = preg_replace('/[^a-zA-Z0-9_\-:.]/', '_', $method) ?: 'admin_manual';

        return mb_substr($method, 0, 100);
    }

    private function cleanNote(string $note): string
    {
        return mb_substr(trim($note), 0, 5000);
    }

    private function normalizeListRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_validated'] = (int) $row['is_validated'];
        $row['name'] = $this->clientDisplayName($row);
        $row['phone_full'] = $this->formatPhone($row['phone_cc'] ?? '', $row['phone'] ?? '');
        $row['validation_checked_at_formatted'] = $this->formatDateTime($row['validation_checked_at'] ?? null);

        return $row;
    }

    private function normalizeClientRow(array $client): array
    {
        $client['id'] = (int) $client['id'];
        $client['name'] = $this->clientDisplayName($client);
        $client['phone_full'] = $this->formatPhone($client['phone_cc'] ?? '', $client['phone'] ?? '');
        $client['address_full'] = implode(', ', array_filter([
            $client['address_1'] ?? null,
            $client['address_2'] ?? null,
            $client['city'] ?? null,
            $client['state'] ?? null,
            $client['postcode'] ?? null,
            $client['country'] ?? null,
        ]));

        return $client;
    }

    private function normalizeValidationRow(?array $validation): ?array
    {
        if (!$validation) {
            return null;
        }

        $validation['id'] = (int) $validation['id'];
        $validation['client_id'] = (int) $validation['client_id'];
        $validation['is_validated'] = (int) $validation['is_validated'];
        $validation['validation_checked_at_formatted'] = $this->formatDateTime($validation['validation_checked_at'] ?? null);
        $validation['created_at_formatted'] = $this->formatDateTime($validation['created_at'] ?? null);
        $validation['updated_at_formatted'] = $this->formatDateTime($validation['updated_at'] ?? null);

        return $validation;
    }

    private function clientDisplayName(array $client): string
    {
        $name = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));

        return $name !== '' ? $name : 'Client #' . (int) ($client['id'] ?? 0);
    }

    private function formatPhone(?string $phoneCc, ?string $phone): string
    {
        $phoneCc = trim((string) $phoneCc);
        $phone = trim((string) $phone);

        if ($phoneCc !== '' && $phone !== '') {
            $prefix = str_starts_with($phoneCc, '+') ? $phoneCc : '+' . $phoneCc;
            return trim($prefix . ' ' . $phone);
        }

        return $phone ?: '-';
    }

    private function formatDateTime(mixed $value): string
    {
        if (!$value) {
            return '-';
        }

        return preg_replace('/\.\d+$/', '', (string) $value) ?: '-';
    }

    private function pageNumbers(int $page, int $totalPages): array
    {
        $start = max(1, $page - 3);
        $end = min($totalPages, $page + 3);

        return $start <= $end ? range($start, $end) : [1];
    }
}
