<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/token_service.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';
require_once __DIR__ . '/../includes/customer_context.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error('method_not_allowed', 'Use POST.', 405);
}

$body = api_input_json();
$username = trim((string) ($body['username'] ?? $body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($username === '' || $password === '') {
    api_error('validation_error', 'username and password are required.', 422);
}

$allowedMobileRoles = ['mechanic', 'customer', 'admin', 'cashier'];

try {
    $pdo = Database::getInstance();

    $sql = '
        SELECT
            u.user_id,
            u.tenant_id,
            u.full_name,
            u.username,
            u.password_hash,
            u.must_change_password,
            u.role,
            u.status AS user_status,
            t.business_name,
            t.status AS tenant_status
        FROM users u
        INNER JOIN tenants t ON u.tenant_id = t.tenant_id
        WHERE u.username = :username
        LIMIT 1
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        api_error('invalid_credentials', 'Invalid username or password.', 401);
    }

    if (($user['user_status'] ?? '') !== 'active') {
        api_error('account_inactive', 'Your account is inactive.', 403);
    }
    if (($user['tenant_status'] ?? '') !== 'active') {
        api_error('tenant_inactive', 'This tenant is currently inactive.', 403);
    }

    if ((int) ($user['must_change_password'] ?? 0) === 1) {
        api_error('password_change_required', 'You must change your password on the web app before using mobile.', 403);
    }

    $role = (string) $user['role'];
    if (!in_array($role, $allowedMobileRoles, true)) {
        api_error('role_not_allowed', 'This role cannot use the mobile API.', 403);
    }

    $userId = (int) $user['user_id'];
    $tenantId = (int) $user['tenant_id'];

    $tokens = api_issue_tokens($pdo, $userId, $tenantId);

    $fmap = api_get_tenant_feature_map($pdo, $tenantId);
    $features = [
        'appointments' => !empty($fmap['appointments']),
        'jobs' => !empty($fmap['jobs']),
        'invoicing' => !empty($fmap['invoicing']),
        'payments' => !empty($fmap['payments']),
        'customerModule' => !empty($fmap['customer_module']),
        'mechanicModule' => !empty($fmap['mechanic_module']),
        'inventory' => !empty($fmap['inventory']),
    ];

    $profile = null;
    if ($role === 'customer') {
        $cid = api_resolve_customer_id($pdo, $tenantId, (string) $user['username']);
        $display = (string) $user['full_name'];
        $email = '';
        $phone = '';
        if ($cid !== null) {
            $pst = $pdo->prepare('SELECT name, email, contact FROM customers WHERE tenant_id = :t AND customer_id = :c LIMIT 1');
            $pst->execute(['t' => $tenantId, 'c' => $cid]);
            $crow = $pst->fetch(PDO::FETCH_ASSOC);
            if ($crow) {
                $display = (string) ($crow['name'] ?? $display);
                $email = (string) ($crow['email'] ?? '');
                $phone = (string) ($crow['contact'] ?? '');
            }
        }
        if ($email === '') {
            $email = (string) $user['username'];
        }
        $profile = [
            'displayName' => $display,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    $payload = [
        'ok' => true,
        'accessToken' => $tokens['access_token'],
        'refreshToken' => $tokens['refresh_token'],
        'expiresIn' => $tokens['expires_in'],
        'tokenType' => 'Bearer',
        'userId' => $userId,
        'tenantId' => $tenantId,
        'role' => $role,
        'fullName' => $user['full_name'],
        'businessName' => $user['business_name'],
        'features' => $features,
    ];
    if ($profile !== null) {
        $payload['profile'] = $profile;
    }

    api_json($payload);
} catch (PDOException $e) {
    api_error('server_error', 'Login failed.', 500);
}
