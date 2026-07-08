<?php



declare(strict_types=1);



use MailPanel\Core\Router;

use MailPanel\Http\Controllers\AdminAuthController;
use MailPanel\Http\Controllers\AdminDashboardController;
use MailPanel\Http\Controllers\AdminSecurityController;
use MailPanel\Http\Controllers\AdminPackageController;
use MailPanel\Http\Controllers\AdminTenantController;
use MailPanel\Http\Controllers\AdminDomainController;
use MailPanel\Http\Controllers\AdminMailboxController;
use MailPanel\Http\Controllers\AdminRoutingController;
use MailPanel\Http\Controllers\AdminConfigDeploymentController;
use MailPanel\Http\Controllers\SpamPolicyController;



return static function (Router $router): void {

    $router->add('GET', '/', [AdminDashboardController::class, 'home']);

    $router->add('GET', '/admin', [AdminDashboardController::class, 'home']);

    $router->add('GET', '/admin/login', [AdminAuthController::class, 'login']);

    $router->add('POST', '/admin/login', [AdminAuthController::class, 'login']);

    $router->add('GET', '/admin/forgot-password', [AdminAuthController::class, 'forgotPassword']);

    $router->add('POST', '/admin/forgot-password', [AdminAuthController::class, 'forgotPassword']);

    $router->add('GET', '/admin/reset-password', [AdminAuthController::class, 'resetPassword']);

    $router->add('POST', '/admin/reset-password', [AdminAuthController::class, 'resetPassword']);

    $router->add('GET', '/admin/dashboard', [AdminDashboardController::class, 'dashboard']);

    $router->add('GET', '/admin/security', [AdminSecurityController::class, 'security']);

    $router->add('POST', '/admin/security', [AdminSecurityController::class, 'security']);

    $router->add('GET', '/admin/portal-settings', [AdminSecurityController::class, 'portalSettings']);

    $router->add('POST', '/admin/portal-settings', [AdminSecurityController::class, 'portalSettings']);

    $router->add('GET', '/admin/packages', [AdminPackageController::class, 'packages']);

    $router->add('POST', '/admin/packages', [AdminPackageController::class, 'packages']);

    $router->add('GET', '/admin/tenants', [AdminTenantController::class, 'tenants']);

    $router->add('POST', '/admin/tenants', [AdminTenantController::class, 'tenants']);

    $router->add('POST', '/admin/impersonate', [AdminAuthController::class, 'impersonate']);

    $router->add('POST', '/admin/impersonate/stop', [AdminAuthController::class, 'stopImpersonation']);

    $router->add('GET', '/admin/super-admins', [AdminSecurityController::class, 'superAdmins']);

    $router->add('POST', '/admin/super-admins', [AdminSecurityController::class, 'superAdmins']);

    $router->add('GET', '/admin/domains', [AdminDomainController::class, 'domains']);

    $router->add('POST', '/admin/domains', [AdminDomainController::class, 'domains']);

    $router->add('GET', '/admin/dns-checks', [AdminDomainController::class, 'dnsChecks']);

    $router->add('POST', '/admin/dns-checks', [AdminDomainController::class, 'dnsChecks']);

    $router->add('GET', '/admin/mailboxes', [AdminMailboxController::class, 'mailboxes']);

    $router->add('POST', '/admin/mailboxes', [AdminMailboxController::class, 'mailboxes']);

    $router->add('GET', '/admin/routing', [AdminRoutingController::class, 'routing']);

    $router->add('POST', '/admin/routing', [AdminRoutingController::class, 'routing']);

    $router->add('GET', '/admin/config-versions', [AdminConfigDeploymentController::class, 'configVersions']);

    $router->add('POST', '/admin/config-versions', [AdminConfigDeploymentController::class, 'configVersions']);

    $router->add('GET', '/admin/queue', [\MailPanel\Http\Controllers\MonitorController::class, 'queueList']);

    $router->add('POST', '/admin/queue', [\MailPanel\Http\Controllers\MonitorController::class, 'queueAction']);

    $router->add('GET', '/admin/logs', [\MailPanel\Http\Controllers\MonitorController::class, 'logs']);

    $router->add('GET', '/admin/fail2ban', [\MailPanel\Http\Controllers\SecuritySystemController::class, 'fail2ban']);
    $router->add('POST', '/admin/fail2ban/unban', [\MailPanel\Http\Controllers\SecuritySystemController::class, 'fail2banUnban']);
    $router->add('GET', '/admin/webmail', [\MailPanel\Http\Controllers\SecuritySystemController::class, 'webmail']);
    $router->add('POST', '/admin/webmail', [\MailPanel\Http\Controllers\SecuritySystemController::class, 'webmail']);
    

    $router->add('GET', '/admin/spam-policies', [SpamPolicyController::class, 'manage']);
    $router->add('POST', '/admin/spam-policies', [SpamPolicyController::class, 'manage']);
    $router->add('GET', '/admin/rspamd', [\MailPanel\Http\Controllers\SecuritySystemController::class, 'rspamd']);
    $router->add('POST', '/admin/rspamd', [\MailPanel\Http\Controllers\SecuritySystemController::class, 'rspamd']);

    $router->add('POST', '/admin/logout', [AdminAuthController::class, 'logout']);

};

