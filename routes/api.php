<?php

declare(strict_types=1);

use MailPanel\Core\Router;
use MailPanel\Http\Controllers\AdminController;
use MailPanel\Http\Controllers\AuthController;
use MailPanel\Http\Controllers\UserController;

return static function (Router $router): void {
    $router->add('POST', '/api/admin/login', [AuthController::class, 'adminLogin']);
    $router->add('POST', '/api/user/login', [AuthController::class, 'mailboxLogin']);
    $router->add('POST', '/api/user/password/change', [AuthController::class, 'mailboxPasswordChange']);
    $router->add('POST', '/api/webmail/password-change', [AuthController::class, 'webmailMailboxPasswordChange']);
    $router->add('POST', '/api/logout', [AuthController::class, 'logout'], ['auth' => true]);
    $router->add('POST', '/api/admin/tokens', [AuthController::class, 'issueToken'], ['auth' => true, 'permission' => 'api_tokens.create', 'token_scopes' => ['tokens.write']]);
    $router->add('POST', '/api/admin/login-keys', [AuthController::class, 'issueLoginKey'], ['auth' => true, 'permission' => 'api_tokens.create', 'token_scopes' => ['tokens.write']]);
    $router->add('GET', '/api/admin/dashboard', [AdminController::class, 'dashboard'], ['auth' => true, 'permission' => 'dashboard.view', 'token_scopes' => ['dashboard.read']]);
    $router->add('GET', '/api/admin/packages', [AdminController::class, 'packages'], ['auth' => true, 'permission' => 'packages.view', 'token_scopes' => ['packages.read']]);
    $router->add('POST', '/api/admin/packages', [AdminController::class, 'packages'], ['auth' => true, 'permission' => 'packages.create', 'writable' => true, 'token_scopes' => ['packages.write']]);
    $router->add('GET', '/api/admin/tenants', [AdminController::class, 'tenants'], ['auth' => true, 'permission' => 'tenants.view', 'token_scopes' => ['tenants.read']]);
    $router->add('POST', '/api/admin/tenants', [AdminController::class, 'tenants'], ['auth' => true, 'permission' => 'tenants.create', 'writable' => true, 'token_scopes' => ['tenants.write']]);
    $router->add('GET', '/api/admin/domains', [AdminController::class, 'domains'], ['auth' => true, 'permission' => 'domains.view', 'token_scopes' => ['domains.read']]);
    $router->add('POST', '/api/admin/domains', [AdminController::class, 'domains'], ['auth' => true, 'permission' => 'domains.create', 'writable' => true, 'tenant_body_field' => 'tenant_id', 'token_scopes' => ['domains.write']]);
    $router->add('GET', '/api/admin/mailboxes', [AdminController::class, 'mailboxes'], ['auth' => true, 'permission' => 'mailboxes.view', 'token_scopes' => ['mailboxes.read']]);
    $router->add('POST', '/api/admin/mailboxes', [AdminController::class, 'mailboxes'], ['auth' => true, 'permission' => 'mailboxes.create', 'writable' => true, 'tenant_body_field' => 'tenant_id', 'token_scopes' => ['mailboxes.write']]);
    $router->add('GET', '/api/admin/aliases', [AdminController::class, 'aliases'], ['auth' => true, 'permission' => 'aliases.view', 'token_scopes' => ['aliases.read']]);
    $router->add('POST', '/api/admin/aliases', [AdminController::class, 'aliases'], ['auth' => true, 'permission' => 'aliases.create', 'writable' => true, 'tenant_body_field' => 'tenant_id', 'token_scopes' => ['aliases.write']]);
    $router->add('GET', '/api/admin/forwards', [AdminController::class, 'forwards'], ['auth' => true, 'permission' => 'forwards.view', 'token_scopes' => ['forwards.read']]);
    $router->add('POST', '/api/admin/forwards', [AdminController::class, 'forwards'], ['auth' => true, 'permission' => 'forwards.create', 'writable' => true, 'tenant_body_field' => 'tenant_id', 'token_scopes' => ['forwards.write']]);
    $router->add('POST', '/api/admin/config-versions/generate', [AdminController::class, 'generateConfig'], ['auth' => true, 'permission' => 'config_versions.create', 'writable' => true, 'token_scopes' => ['config.write']]);
    $router->add('GET', '/api/admin/config-versions', [AdminController::class, 'configVersions'], ['auth' => true, 'permission' => 'config_versions.view', 'token_scopes' => ['config.read']]);
    $router->add('POST', '/api/admin/config-versions/apply', [AdminController::class, 'applyConfigVersion'], ['auth' => true, 'permission' => 'config_versions.update', 'writable' => true, 'token_scopes' => ['config.write']]);
    $router->add('POST', '/api/admin/config-versions/rollback', [AdminController::class, 'rollbackConfigVersion'], ['auth' => true, 'permission' => 'config_versions.restore', 'writable' => true, 'token_scopes' => ['config.write']]);
    $router->add('POST', '/api/admin/quota-usage', [AdminController::class, 'updateQuota'], ['auth' => true, 'roles' => ['super_admin'], 'permission' => 'quota.update', 'writable' => true, 'token_scopes' => ['quota.write']]);
    $router->add('GET', '/api/user/quota', [UserController::class, 'quota'], ['auth' => true, 'roles' => ['mailbox_user'], 'token_scopes' => ['quota.read']]);
    $router->add('POST', '/api/user/password', [UserController::class, 'password'], ['auth' => true, 'roles' => ['mailbox_user'], 'token_scopes' => ['profile.write']]);
    $router->add('GET', '/api/user/forwarding', [UserController::class, 'forwarding'], ['auth' => true, 'roles' => ['mailbox_user'], 'token_scopes' => ['forwards.read']]);
    $router->add('POST', '/api/user/forwarding', [UserController::class, 'forwarding'], ['auth' => true, 'roles' => ['mailbox_user'], 'tenant_body_field' => 'tenant_id', 'token_scopes' => ['forwards.write']]);
};
