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

1. Khóa deploy để không có hai tiến trình chạy đồng thời.
2. Resolve Git ref thành commit SHA bất biến và quét credential.
3. Build release mới; `.env` và runtime storage dùng vùng shared.
4. Chạy migration, cập nhật system agent và kiểm tra Roundcube.
5. Ghi release đang chạy vào `/opt/mailpanel-previous`.
6. Chuyển symlink `/opt/mailpanel` sang release mới.
7. Chạy healthcheck đầy đủ và kiểm tra HTTP trang đăng nhập.
8. Nếu lỗi: phục hồi symlink + agent, xóa release hỏng và giữ database migration
   theo nguyên tắc forward-only.

## Deploy trực tiếp từ Git

Tạo cấu hình một lần:

```bash
sudo install -d -m 0755 /etc/mailpanel
sudo install -m 0644 deploy/deploy-from-git.conf.example /etc/mailpanel/deploy.conf
sudo editor /etc/mailpanel/deploy.conf
```

Mỗi lần cập nhật:

```bash
sudo bash /opt/mailpanel/deploy/deploy-from-git.sh --dry-run
sudo bash /opt/mailpanel/deploy/deploy-from-git.sh
sudo bash /opt/mailpanel/deploy/deploy-from-git.sh --status
```

Rollback dùng đúng symlink `PREVIOUS_LINK`, không suy đoán theo thời gian sửa
thư mục:

```bash
sudo bash /opt/mailpanel/deploy/deploy-from-git.sh --rollback
```

Không xóa `/etc/mailpanel/.env`, `/var/lib/mailpanel/storage`,
`/opt/mailpanel-previous` hoặc các backup Roundcube trong
`/root/mailpanel-roundcube-backups`.

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
  - `docs/ACME_TLS.md`
  - `docs/ROUNDCUBE.md`
