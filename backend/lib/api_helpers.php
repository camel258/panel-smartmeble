<?php

function api_request_data(): array {
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : $_POST;
}

function api_null_if_empty($value) {
    if ($value === null) return null;
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
    return $value;
}

function api_int_or_null($value): ?int {
    $value = api_null_if_empty($value);
    if ($value === null) return null;
    return (is_numeric($value) && (int)$value > 0) ? (int)$value : null;
}

function api_dec_or_zero($value): string {
    if ($value === null || $value === '') return '0.00';
    return is_numeric($value) ? number_format((float)$value, 2, '.', '') : '0.00';
}

function api_current_role_id(PDO $pdo, array $user): int {
    $roleId = (int)($user['role_id'] ?? ($user['role'] ?? 0));
    if ($roleId > 0) return $roleId;

    $userId = (int)($user['id'] ?? ($user['uid'] ?? 0));
    if ($userId > 0) {
        try {
            $st = $pdo->prepare('SELECT role_id FROM users WHERE id=? LIMIT 1');
            $st->execute([$userId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
        }
    }
    return 0;
}

function api_role_name(PDO $pdo, int $roleId): string {
    if ($roleId <= 0) return '';
    try {
        $cols = array_column($pdo->query('SHOW COLUMNS FROM roles')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach (['name', 'title', 'label'] as $column) {
            if (in_array($column, $cols, true)) {
                $st = $pdo->prepare("SELECT `$column` FROM roles WHERE id=? LIMIT 1");
                $st->execute([$roleId]);
                return strtolower(trim((string)$st->fetchColumn()));
            }
        }
    } catch (Throwable $e) {
    }
    return '';
}

function api_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function api_is_permissions_admin(PDO $pdo, array $user): bool {
    $roleId = api_current_role_id($pdo, $user);
    if (in_array($roleId, [1, 999], true)) return true;

    $roleName = api_role_name($pdo, $roleId);
    if ($roleName !== '' && (strpos($roleName, 'super') !== false || strpos($roleName, 'admin') !== false)) {
        return true;
    }

    try {
        if (api_table_exists($pdo, 'role_module_permissions')) {
            $st = $pdo->prepare("SELECT allow_access FROM role_module_permissions WHERE role_id=? AND module_code='permissions' LIMIT 1");
            $st->execute([$roleId]);
            if ((int)$st->fetchColumn() === 1) return true;
        }
    } catch (Throwable $e) {
    }
    return false;
}

function api_private_files_base(): string {
    $candidates = [
        realpath(__DIR__ . '/../../_private_files'),
        realpath(__DIR__ . '/../_private_files'),
    ];
    foreach ($candidates as $candidate) {
        if ($candidate && is_dir($candidate)) return $candidate;
    }
    return dirname(__DIR__, 2) . '/private_files';
}

function api_scope_dir(string $scope): string {
    return $scope === 'admin' ? 'orders_admin' : 'orders_public';
}

function api_error_log_path(): string {
    return dirname(__DIR__, 2) . '/uploads/error_log.txt';
}

function api_log_error(string $context, Throwable $e): void {
    @file_put_contents(api_error_log_path(), date('Y-m-d H:i:s') . ' ' . $context . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
}
