# Deployment Notes

## Môi trường ứng dụng

- App root mặc định: `/opt/mailpanel`
- Public root mặc định: `/opt/mailpanel/public`
- Generated config root: `storage/generated` hoặc `/var/lib/mailpanel/generated`

## Dịch vụ liên quan

- `nginx`
- `php-fpm`
- `exim4`
- `dovecot`
- `rspamd`
- `fail2ban`
- `webmail` nếu bật

## Luồng deploy an toàn

1. Cập nhật code app
2. Chạy migration nếu có
3. Generate config draft
4. Validate config
5. Apply version
6. Reload service
7. Health-check
8. Rollback nếu fail

## Port thường dùng

- `80` / `443` cho web + ACME
- `25` SMTP
- `465` SMTPS
- `587` Submission TLS
- `143` / `993` IMAP / IMAPS
- `110` / `995` POP3 / POP3S nếu bật
- `4190` ManageSieve nếu bật

## Ghi chú

- Port thực tế phải kiểm tra cùng firewall cloud + OS firewall.
- TLS per-domain/ACME đã có nền renderer + script riêng trong repo.
- Xem thêm:
  - `docs/INSTALL.md`
  - `docs/ARCHITECTURE.md`
  - `docs/LETSENCRYPT.md`
  - `docs/SNAPPYMAIL.md`
