# Security Checklist

## Web app

- [x] App không cần chạy quyền root
- [x] CSRF cho form web
- [x] Session cookie có `HttpOnly`
- [x] `SameSite` cấu hình được
- [x] Session timeout cấu hình được
- [x] Rate limit admin login
- [x] Rate limit mailbox login
- [x] Rate limit mailbox password change
- [x] Rate limit API
- [x] 2FA TOTP cho admin
- [x] TOTP secrets encrypted at rest when `APP_KEY` or `TOTP_ENCRYPTION_KEY` is configured
- [x] Password policy cấu hình được
- [x] Password history cho admin/mailbox
- [x] Force password change
- [x] Audit log cho thao tác quan trọng
- [x] Login Key / API token scope, session-only issuance, current password/OTP verification
- [x] Tenant scope ở controller/test nền tảng
- [x] CSP app-level và Nginx `script-src`/`style-src` không còn cần `unsafe-inline` cho admin UI

## System command

- [x] Không gọi shell trực tiếp từ controller
- [x] Có `SystemCommandService`
- [x] Có agent/wrapper allowlist
- [x] Web process gọi agent có timeout và redaction lỗi
- [x] Validate trước apply config
- [x] Apply/reload/rollback qua agent

## Mail

- [x] Virtual users cho mailbox
- [x] SQL-backed auth/quota groundwork
- [x] TLS/default cert + SNI inventory groundwork
- [ ] Outbound anomaly detection đầy đủ
- [ ] Open relay simulation test
- [ ] Alias send-policy enforcement đầy đủ

## UI / module còn thiếu

- [ ] Fail2ban admin module hoàn chỉnh
- [ ] Webmail integration module hoàn chỉnh
- [ ] Roles & permissions UI chi tiết
- [ ] Audit log explorer UI
