<?php

declare(strict_types=1);

use MailPanel\Core\Router;
use MailPanel\Http\Controllers\AdminAuthController;
use MailPanel\Http\Controllers\AdminConfigDeploymentController;
use MailPanel\Http\Controllers\AdminDashboardController;
use MailPanel\Http\Controllers\AdminDomainController;
use MailPanel\Http\Controllers\AdminMailboxController;
use MailPanel\Http\Controllers\AdminPackageController;
use MailPanel\Http\Controllers\AdminRoutingController;
use MailPanel\Http\Controllers\AdminSecurityController;
use MailPanel\Http\Controllers\AdminTenantController;
use MailPanel\Http\Controllers\MonitorController;
use MailPanel\Http\Controllers\SecuritySystemController;
use MailPanel\Http\Controllers\SpamPolicyController;

/**
 * Admin web routes.
 *
 * Every route MUST declare either 'auth' => true or 'public' => true; Router::add()
 * throws otherwise. This file previously declared no meta at all, which left the
 * router authorising nothing — access control existed only inside the controllers,
 * so adding a route and forgetting guardAuthenticatedPage() silently published it.
 *
 * The router now enforces authentication and coarse role checks. Fine-grained
 * permission checks stay in the controllers because they flash a message and
 * redirect, which is better UX than the router's bare 403 page.
 *
 * 'allow_password_change' => true marks routes an admin may still reach while their
 * account is flagged force_password_change; everything else is blocked until the
 * password is rotated.
 */
return static function (Router $router): void {
    // --- Public: authentication entry points -------------------------------
    $router->add('GET', '/admin/login', [AdminAuthController::class, 'login'], ['public' => true]);
    $router->add('POST', '/admin/login', [AdminAuthController::class, 'login'], ['public' => true]);

    // --- Dashboard ---------------------------------------------------------
    $router->add('GET', '/', [AdminDashboardController::class, 'home'], ['auth' => true, 'allow_password_change' => true]);
    $router->add('GET', '/admin', [AdminDashboardController::class, 'home'], ['auth' => true, 'allow_password_change' => true]);
    $router->add('GET', '/admin/dashboard', [AdminDashboardController::class, 'dashboard'], ['auth' => true]);

    // --- Account security --------------------------------------------------
    // Reachable during a forced rotation; this is where the password is changed.
    $router->add('GET', '/admin/security', [AdminSecurityController::class, 'security'], ['auth' => true, 'allow_password_change' => true]);
    $router->add('POST', '/admin/security', [AdminSecurityController::class, 'security'], ['auth' => true, 'allow_password_change' => true]);
    $router->add('POST', '/admin/logout', [AdminAuthController::class, 'logout'], ['auth' => true, 'allow_password_change' => true]);

    // --- Packages ----------------------------------------------------------
    $router->add('GET', '/admin/packages', [AdminPackageController::class, 'packages'], ['auth' => true]);
    $router->add('POST', '/admin/packages', [AdminPackageController::class, 'packages'], ['auth' => true]);

    // --- Tenants / user level ---------------------------------------------
    $router->add('GET', '/admin/tenants', [AdminTenantController::class, 'tenants'], ['auth' => true]);
    $router->add('POST', '/admin/tenants', [AdminTenantController::class, 'tenants'], ['auth' => true]);
    $router->add('POST', '/admin/impersonate', [AdminAuthController::class, 'impersonate'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('POST', '/admin/impersonate/stop', [AdminAuthController::class, 'stopImpersonation'], ['auth' => true, 'allow_password_change' => true]);

    // --- Super admins ------------------------------------------------------
    $router->add('GET', '/admin/super-admins', [AdminSecurityController::class, 'superAdmins'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('POST', '/admin/super-admins', [AdminSecurityController::class, 'superAdmins'], ['auth' => true, 'roles' => ['super_admin']]);

    // --- Domains -----------------------------------------------------------
    $router->add('GET', '/admin/domains', [AdminDomainController::class, 'domains'], ['auth' => true]);
    $router->add('POST', '/admin/domains', [AdminDomainController::class, 'domains'], ['auth' => true]);
    $router->add('GET', '/admin/dns-checks', [AdminDomainController::class, 'dnsChecks'], ['auth' => true]);
    $router->add('POST', '/admin/dns-checks', [AdminDomainController::class, 'dnsChecks'], ['auth' => true]);

    // --- Mailboxes ---------------------------------------------------------
    $router->add('GET', '/admin/mailboxes', [AdminMailboxController::class, 'mailboxes'], ['auth' => true]);
    $router->add('POST', '/admin/mailboxes', [AdminMailboxController::class, 'mailboxes'], ['auth' => true]);

    // --- Routing (aliases / forwards / groups) -----------------------------
    $router->add('GET', '/admin/routing', [AdminRoutingController::class, 'routing'], ['auth' => true]);
    $router->add('POST', '/admin/routing', [AdminRoutingController::class, 'routing'], ['auth' => true]);

    // --- Config deployment -------------------------------------------------
    $router->add('GET', '/admin/config-versions', [AdminConfigDeploymentController::class, 'configVersions'], ['auth' => true]);
    $router->add('POST', '/admin/config-versions', [AdminConfigDeploymentController::class, 'configVersions'], ['auth' => true]);

    // --- Monitoring (super admin only) -------------------------------------
    $router->add('GET', '/admin/queue', [MonitorController::class, 'queueList'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('POST', '/admin/queue', [MonitorController::class, 'queueAction'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('GET', '/admin/logs', [MonitorController::class, 'logs'], ['auth' => true, 'roles' => ['super_admin']]);

    // --- System security (super admin only) --------------------------------
    $router->add('GET', '/admin/fail2ban', [SecuritySystemController::class, 'fail2ban'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('POST', '/admin/fail2ban/unban', [SecuritySystemController::class, 'fail2banUnban'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('GET', '/admin/rspamd', [SecuritySystemController::class, 'rspamd'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('POST', '/admin/rspamd', [SecuritySystemController::class, 'rspamd'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('GET', '/admin/webmail', [SecuritySystemController::class, 'webmail'], ['auth' => true, 'roles' => ['super_admin']]);
    $router->add('POST', '/admin/webmail', [SecuritySystemController::class, 'webmail'], ['auth' => true, 'roles' => ['super_admin']]);

    // --- Spam policies -----------------------------------------------------
    $router->add('GET', '/admin/spam-policies', [SpamPolicyController::class, 'manage'], ['auth' => true]);
    $router->add('POST', '/admin/spam-policies', [SpamPolicyController::class, 'manage'], ['auth' => true]);
};
