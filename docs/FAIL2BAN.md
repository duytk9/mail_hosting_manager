# Fail2ban vận hành và kiểm thử

Tài liệu này mô tả baseline Fail2ban do MailPanel sinh ra qua `Config Versions`. Mục tiêu là chặn brute-force SMTP/IMAP/POP3/webmail mà không chỉnh trực tiếp regex tùy ý từ UI.

## Kiến trúc

- MailPanel render jail/filter bằng `src/Services/Fail2banConfigRenderer.php`.
- Config được ghi vào `/var/lib/mailpanel/generated/fail2ban/<version>/`.
- Khi apply, agent copy jail chính vào `/etc/fail2ban/jail.d/99-mailpanel.local` và filter vào `/etc/fail2ban/filter.d/mailpanel-*.conf`.
- Mọi thay đổi production phải đi qua `Config Versions`: generate, validate, apply, reload và có rollback.
- Web app không chạy `fail2ban-client` trực tiếp; thao tác hệ thống đi qua agent/wrapper allowlist.

## Jail chuẩn

| Jail | Filter | Nguồn log | Mục tiêu |
| --- | --- | --- | --- |
| `dovecot` | `mailpanel-dovecot` | `journalctl -u dovecot` | IMAP/POP3/ManageSieve auth fail |
| `exim-smtp-auth` | `mailpanel-exim-auth` | `/var/log/exim4/mainlog` | SMTP AUTH sai mật khẩu |
| `exim-reject` | `mailpanel-exim-reject` | `/var/log/exim4/mainlog` | relay test, recipient reject, unrouteable |
| `webmail-auth` | `mailpanel-webmail-auth` | `WEBMAIL_LOG_PATH` | brute-force webmail |
| `sshd` | distro default | tùy chọn | chỉ bật khi cần quản lý SSH qua policy riêng |

Giá trị mặc định an toàn:

- `maxretry = 5`
- `findtime = 10m`
- `bantime = 1h`

## Biến môi trường liên quan

```dotenv
WEBMAIL_ENABLED=1
WEBMAIL_LOG_PATH=/var/log/roundcube/userlogins.log
```

Nếu webmail runtime dùng đường dẫn log khác, cập nhật `WEBMAIL_LOG_PATH`, sau đó generate/apply lại `fail2ban`.

## Kiểm tra nhanh trên server

```bash
fail2ban-client ping
fail2ban-client -t
fail2ban-client status

fail2ban-client status dovecot
fail2ban-client status exim-smtp-auth
fail2ban-client status exim-reject
fail2ban-client status webmail-auth

fail2ban-client get exim-smtp-auth logpath
fail2ban-client get exim-reject logpath
fail2ban-client get webmail-auth logpath
```

Kỳ vọng:

- `fail2ban-client -t` trả về `OK: configuration test is successful`.
- `exim-smtp-auth` và `exim-reject` phải đọc `/var/log/exim4/mainlog`.
- `webmail-auth` phải đọc đúng log webmail đang dùng.
- Không có jail lạ hoặc jail bị disabled ngoài chủ đích.

## Kiểm thử regex bằng log thật

```bash
fail2ban-regex /var/log/exim4/mainlog /etc/fail2ban/filter.d/mailpanel-exim-auth.conf
fail2ban-regex /var/log/exim4/mainlog /etc/fail2ban/filter.d/mailpanel-exim-reject.conf

journalctl -u dovecot --since '24 hours ago' --no-pager \
  | grep -Ei 'auth failed|invalid credentials|Password mismatch' \
  > /tmp/mailpanel-dovecot-fail2ban.log || true

fail2ban-regex /tmp/mailpanel-dovecot-fail2ban.log /etc/fail2ban/filter.d/mailpanel-dovecot.conf
rm -f /tmp/mailpanel-dovecot-fail2ban.log
```

Regex phải có số dòng `matched` khi log có auth fail hoặc reject thật. Nếu `matched = 0`, kiểm tra lại format log trước khi apply production.

## Apply cấu hình an toàn

1. Vào **Lịch sử cấu hình** trong admin.
2. Bấm **Build cấu hình** để sinh version mới.
3. Validate version `fail2ban`.
4. Apply version `fail2ban`.
5. Kiểm tra `fail2ban-client -t` và các jail status.
6. Nếu reload lỗi, rollback về version `fail2ban` trước đó.

Không sửa trực tiếp `/etc/fail2ban/jail.d/99-mailpanel.local` trừ khi đang hotfix khẩn cấp. Nếu hotfix, phải backup file cũ và đưa thay đổi về renderer ngay sau đó.

## Troubleshooting

### Exim auth/reject không tăng failed count

Kiểm tra jail đang đọc đúng file:

```bash
fail2ban-client get exim-smtp-auth logpath
fail2ban-client get exim-reject logpath
tail -n 100 /var/log/exim4/mainlog
```

Nếu jail đang dùng `journalmatch = _SYSTEMD_UNIT=exim4.service`, cần generate/apply lại cấu hình mới vì Exim trên Ubuntu thường ghi sự kiện auth/reject vào `/var/log/exim4/mainlog`.

### Webmail auth không bị bắt

```bash
grep -Ei 'auth failed|failed login|login failed' "$WEBMAIL_LOG_PATH"
fail2ban-regex "$WEBMAIL_LOG_PATH" /etc/fail2ban/filter.d/mailpanel-webmail-auth.conf
```

Nếu webmail đổi client hoặc đổi log path, cập nhật `WEBMAIL_LOG_PATH` và apply lại `fail2ban`.

### Log Fail2ban có warning `UnknownJailException`

Lỗi này thường do lệnh kiểm tra truyền sai tên jail. Xác nhận danh sách jail hợp lệ:

```bash
fail2ban-client status
```

Chỉ thao tác với các jail có trong danh sách.

