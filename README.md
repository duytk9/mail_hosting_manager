# MailPanel - Quản lý Mail Hosting đa tenant

MailPanel là control panel quản lý mail hosting đa khách hàng, tập trung vào mô hình vận hành an toàn cho Linux mail server. Dự án cung cấp giao diện quản trị, API, system agent có allowlist, quản lý tenant/domain/mailbox, cấu hình Exim/Dovecot/Rspamd/Fail2ban, TLS Let's Encrypt/SNI và tích hợp webmail.

## Thành phần chính

- **Admin portal**: quản lý super admin, tenant admin, gói dịch vụ, tenant, domain, mailbox, alias, forward, group mail, quota, DNS/TLS checker và lịch sử cấu hình.
- **Mail stack**: Exim, Dovecot virtual users SQL, Rspamd, ClamAV, Fail2ban, DKIM, SPF/DKIM/DMARC checker.
- **System agent**: mọi thao tác hệ thống đi qua agent/wrapper có allowlist, timeout, audit log và rollback cấu hình.
- **Config versioning**: sinh bản nháp, validate, apply/reload service, health check và rollback khi lỗi.
- **Webmail**: tích hợp webmail tại `/webmail`, tách vai trò khỏi panel quản trị.
- **Security baseline**: session auth, TOTP, CSRF, rate limit, password policy, tenant isolation, IP allowlist cho super admin.

## Cài đặt nhanh

Tài liệu triển khai chi tiết bằng tiếng Việt nằm tại `docs/INSTALL.md`.

Luồng rút gọn:

```bash
git clone https://github.com/duytk9/quan_ly_mail_hosting.git /opt/mailpanel
cd /opt/mailpanel
cp .env.example .env
composer install --no-dev --optimize-autoloader
php scripts/migrate.php
bash deploy/install_agent.sh /opt/mailpanel mailpanel-agent www-data
```

Sau đó đăng nhập portal quản trị, tạo package/tenant/domain/mailbox, sinh cấu hình, apply và kiểm thử gửi nhận mail.

## Tài liệu liên quan

- `docs/INSTALL.md`: hướng dẫn cài đặt/cấu hình từ đầu đến cuối.
- `docs/ARCHITECTURE.md`: kiến trúc hệ thống.
- `docs/SECURITY_CHECKLIST.md`: checklist bảo mật.
- `docs/FAIL2BAN.md`: vận hành, kiểm thử và troubleshooting Fail2ban.
- `docs/ACME_TLS.md`: ACME/TLS/SNI.
- `docs/WEBMAIL_INTEGRATION.md`: tích hợp webmail.
- `docs/CODEBASE_MAP.md`: bản đồ source code.

## Lưu ý bảo mật

- Không commit `.env`, mật khẩu, token, private key, DKIM private key hoặc dữ liệu runtime.
- Web app không chạy quyền `root`.
- Controller không gọi shell trực tiếp; chỉ gọi qua `SystemCommandService` hoặc mailpanel-agent.
- Bật HTTPS, secure cookie và TOTP trước khi mở production.
- Kiểm tra open relay, SMTP AUTH over TLS, tenant isolation và backup trước khi đưa khách hàng thật vào hệ thống.
