#!/usr/bin/env python3
import argparse
import ipaddress
import json
import os
import re
import shlex
import subprocess
import sys
import tempfile
import time
from pathlib import Path


LOG_PATH = Path("/var/log/mailpanel/agent.log")
GENERATED_ROOT = Path("/var/lib/mailpanel/generated")
ACTIVE_ROOT = GENERATED_ROOT / "active"
WRAPPER = "/usr/local/bin/mailpanel-system-wrapper"

VALID_SERVICES = {"nginx", "exim", "dovecot", "rspamd", "fail2ban", "clamav"}
VALID_ACME_PROFILES = {"mail_only", "mail_and_web"}
MAX_TIMEOUT_SECONDS = 900
RESERVED_LINUX_USERNAMES = {
    "root", "daemon", "bin", "sys", "sync", "games", "man", "lp", "mail",
    "news", "uucp", "proxy", "www-data", "backup", "list", "irc", "gnats",
    "nobody", "systemd-network", "systemd-resolve", "sshd", "mysql",
    "postgres", "redis", "nginx", "apache", "postfix", "dovecot", "exim",
    "clamav", "rspamd", "fail2ban", "vmail", "mailpanel", "mailpanel-agent",
}

SECRET_PATTERNS = [
    re.compile(r"((?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)\s*[=:]\s*)([^\s,\"']+)", re.I),
    re.compile(r'("(?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)"\s*:\s*")([^"]+)(")', re.I),
    re.compile(r"(Authorization:\s*(?:Bearer|Basic)\s+)[A-Za-z0-9+/._~=-]+", re.I),
]


def log_event(message: str, payload: dict | None = None) -> None:
    line = {
        "ts": int(time.time()),
        "message": message,
        "payload": redact_sensitive(payload or {}),
    }
    encoded = json.dumps(line, ensure_ascii=False)
    try:
        LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
        with LOG_PATH.open("a", encoding="utf-8") as handle:
            handle.write(encoded + "\n")
    except OSError:
        print(encoded, file=sys.stderr)


def run_command(args: list[str], timeout: int) -> dict:
    process = subprocess.run(args, capture_output=True, text=True, timeout=normalize_timeout(timeout), check=False)
    return {
        "command": args,
        "returncode": process.returncode,
        "stdout": process.stdout.strip(),
        "stderr": process.stderr.strip(),
    }


def redact_sensitive(value):
    if isinstance(value, dict):
        redacted = {}
        for key, item in value.items():
            if is_secret_key(str(key)):
                redacted[key] = "[redacted]"
            else:
                redacted[key] = redact_sensitive(item)
        return redacted
    if isinstance(value, list):
        return [redact_sensitive(item) for item in value]
    if isinstance(value, str):
        redacted = value
        redacted = SECRET_PATTERNS[0].sub(r"\1[redacted]", redacted)
        redacted = SECRET_PATTERNS[1].sub(r"\1[redacted]\3", redacted)
        redacted = SECRET_PATTERNS[2].sub(r"\1[redacted]", redacted)
        return redacted
    return value


def is_secret_key(key: str) -> bool:
    return bool(re.search(r"(password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)", key, re.I))


def normalize_timeout(value, default: int = 30, maximum: int = MAX_TIMEOUT_SECONDS) -> int:
    try:
        timeout = int(value)
    except (TypeError, ValueError):
        timeout = default
    return max(1, min(maximum, timeout))


def require_pattern(value: str, pattern: str, message: str) -> str:
    if not re.fullmatch(pattern, value):
        raise ValueError(message)
    return value


def require_message_id(value: str) -> str:
    return require_pattern(value, r"[A-Za-z0-9][A-Za-z0-9-]{5,31}", "Invalid message id")


def require_jail(value: str) -> str:
    return require_pattern(value, r"[A-Za-z0-9_.:-]{1,80}", "Invalid fail2ban jail")


def require_ip(value: str) -> str:
    try:
        ipaddress.ip_address(value)
    except ValueError as exc:
        raise ValueError("Invalid IP address") from exc
    return value


def require_decimal(value, message: str = "Invalid numeric value") -> str:
    raw = str(value).strip()
    if not re.fullmatch(r"[0-9]+(?:\.[0-9]+)?", raw):
        raise ValueError(message)
    numeric = float(raw)
    if numeric < 0 or numeric > 100:
        raise ValueError(message)
    return f"{numeric:.2f}".rstrip("0").rstrip(".")


def require_positive_int(value, default: int = 200, maximum: int = 5000) -> str:
    try:
        number = int(value)
    except (TypeError, ValueError) as exc:
        raise ValueError("Invalid line count") from exc
    if number < 1 or number > maximum:
        raise ValueError("Invalid line count")
    return str(number)


def normalize_keyword(value: str, maximum: int = 160) -> str:
    keyword = str(value or "").replace("\x00", "").strip()
    keyword = re.sub(r"[\r\n]", "", keyword)
    return keyword[:maximum]


def require_domain(value: str) -> str:
    domain = str(value or "").strip().lower()
    return require_pattern(
        domain,
        r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}",
        "Invalid domain",
    )


def require_email(value: str) -> str:
    email = str(value or "").strip().lower()
    return require_pattern(
        email,
        r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}",
        "Invalid email",
    )


def require_linux_username(value: str) -> str:
    username = require_pattern(str(value or "").strip(), r"[a-z_][a-z0-9_-]{0,31}", "Invalid linux username")
    if username in RESERVED_LINUX_USERNAMES:
        raise ValueError("Reserved linux username")
    return username


def require_numeric_id(value, message: str = "Invalid numeric id") -> str:
    raw = str(value or "").strip()
    if not re.fullmatch(r"[0-9]+", raw):
        raise ValueError(message)
    return raw


def require_absolute_path(value: str | None, label: str) -> str:
    raw = str(value or "").strip()
    if not raw:
        raise ValueError(f"{label} is required")
    if re.search(r"[\x00-\x1f\x7f]", raw):
        raise ValueError(f"Invalid {label}")

    path = Path(raw)
    if not path.is_absolute():
        raise ValueError(f"{label} must be absolute")
    if ".." in path.parts:
        raise ValueError(f"{label} cannot contain path traversal")
    if path == Path(path.anchor):
        raise ValueError(f"{label} cannot be the filesystem root")

    return str(path)


def write_secure_temp(content: str) -> str:
    fd, path = tempfile.mkstemp()
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(content)
        os.chmod(path, 0o600)
        return path
    except Exception:
        try:
            os.close(fd)
        except OSError:
            pass
        safe_unlink(path)
        raise


def safe_unlink(path: str) -> None:
    if path and Path(path).exists():
        Path(path).unlink()


def execute_action(action: str, timeout: int, params: dict, dry_run: bool) -> dict:
    if action == "service.reload":
        service = require_service(params.get("service"))
        args = ["sudo", WRAPPER, "service-reload", service]
    elif action == "service.status":
        service = require_service(params.get("service"))
        args = ["sudo", WRAPPER, "service-status", service]
    elif action == "nginx.validate":
        rendered_path = safe_path(params.get("rendered_path"))
        args = ["sudo", WRAPPER, "validate-nginx", str(rendered_path)]
    elif action == "exim.validate":
        rendered_path = safe_path(params.get("rendered_path"))
        args = ["sudo", WRAPPER, "validate-exim", str(rendered_path)]
    elif action == "dovecot.validate":
        rendered_path = safe_path(params.get("rendered_path"))
        args = ["sudo", WRAPPER, "validate-dovecot", str(rendered_path)]
    elif action == "rspamd.validate":
        rendered_path = safe_path(params.get("rendered_path"))
        args = ["sudo", WRAPPER, "validate-rspamd", str(rendered_path)]
    elif action == "fail2ban.status":
        args = ["sudo", WRAPPER, "fail2ban-status"]
    elif action == "fail2ban.validate":
        rendered_path = safe_path(params.get("rendered_path"))
        args = ["sudo", WRAPPER, "validate-fail2ban", str(rendered_path)]
    elif action == "activate.version":
        service = require_service(params.get("service"))
        rendered_path = safe_path(params.get("rendered_path"))
        active_path = safe_path(params.get("active_path"))
        args = ["sudo", WRAPPER, "activate-version", service, str(rendered_path), str(active_path)]
    elif action == "rollback.version":
        service = require_service(params.get("service"))
        rendered_path = safe_path(params.get("rendered_path"))
        active_path = safe_path(params.get("active_path"))
        args = ["sudo", WRAPPER, "activate-version", service, str(rendered_path), str(active_path)]
    else:
        raise ValueError(f"Unsupported action [{action}]")

    if dry_run:
        return {
            "command": args,
            "returncode": 0,
            "stdout": "dry-run",
            "stderr": "",
            "dry_run": True,
        }

    return run_command(args, timeout)


def require_service(value: str | None) -> str:
    if value not in VALID_SERVICES:
        raise ValueError("Unsupported service")
    return value


def safe_path(value: str | None) -> Path:
    if not value:
        raise ValueError("Path is required")
    path = Path(value).resolve()
    generated = GENERATED_ROOT.resolve()
    if path == generated:
        raise ValueError("Path cannot be generated root")
    if generated not in path.parents and path != generated:
        raise ValueError("Path is outside generated root")
    return path


def handle_plan(payload: dict) -> dict:
    results = []
    for item in payload.get("actions", []):
        action = item["action"]
        params = item.get("params", {})
        timeout = normalize_timeout(item.get("timeout", 15), 15)
        dry_run = bool(item.get("dry_run", True))
        result = execute_action(action, timeout, params, dry_run)
        results.append({"action": action, "result": result})
        if result["returncode"] != 0:
            break
    return {"results": results}


def handle_apply(payload: dict) -> dict:
    service = require_service(payload.get("service"))
    rendered_path = safe_path(payload.get("rendered_path"))
    active_path = safe_path(payload.get("active_path"))
    previous_path = payload.get("previous_rendered_path")
    dry_run = bool(payload.get("dry_run", True))

    validation_map = {
        "nginx": "nginx.validate",
        "exim": "exim.validate",
        "dovecot": "dovecot.validate",
        "rspamd": "rspamd.validate",
        "fail2ban": "fail2ban.validate",
    }
    validate = execute_action(validation_map[service], 20, {"rendered_path": str(rendered_path)}, dry_run)
    if validate["returncode"] != 0:
        return {"service": service, "stage": "validate", "result": validate}

    activate = execute_action("activate.version", 20, {
        "service": service,
        "rendered_path": str(rendered_path),
        "active_path": str(active_path),
    }, dry_run)
    if activate["returncode"] != 0:
        return {"service": service, "stage": "activate", "result": activate}

    reload_result = execute_action("service.reload", 20, {"service": service}, dry_run)
    if reload_result["returncode"] == 0:
        return {"service": service, "stage": "reload", "result": reload_result}

    rollback = None
    if previous_path:
        rollback = execute_action("rollback.version", 20, {
            "service": service,
            "rendered_path": str(safe_path(previous_path)),
            "active_path": str(active_path),
        }, dry_run)
    return {
        "service": service,
        "stage": "reload_failed",
        "result": reload_result,
        "rollback": rollback,
    }


def handle_super_admin(payload: dict) -> dict:
    action = payload.get("action")
    linux_username = require_linux_username(str(payload.get("linux_username") or "").strip())

    if action == "provision":
        ssh_enabled = bool(payload.get("ssh_enabled", False))
        ssh_sudo_enabled = bool(payload.get("ssh_sudo_enabled", False))
        ssh_public_key = str(payload.get("ssh_public_key") or "").strip()
        linux_password_hash = str(payload.get("linux_password_hash") or "").strip()
        temp_key_path = ""
        temp_password_hash_path = ""

        try:
            if ssh_enabled:
                if ssh_public_key:
                    with tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False) as handle:
                        handle.write(ssh_public_key + "\n")
                        temp_key_path = handle.name
                    os.chmod(temp_key_path, 0o600)
            if linux_password_hash:
                with tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False) as handle:
                    handle.write(linux_password_hash + "\n")
                    temp_password_hash_path = handle.name
                os.chmod(temp_password_hash_path, 0o600)

            result = run_command([
                "sudo",
                WRAPPER,
                "provision-super-admin",
                linux_username,
                "1" if ssh_enabled else "0",
                "1" if ssh_sudo_enabled else "0",
                temp_key_path,
                temp_password_hash_path,
            ], 20)
        finally:
            if temp_key_path and Path(temp_key_path).exists():
                Path(temp_key_path).unlink()
            if temp_password_hash_path and Path(temp_password_hash_path).exists():
                Path(temp_password_hash_path).unlink()

        return {"action": action, "result": result}

    if action == "verify-password":
        password = str(payload.get("password") or "")
        temp_password_path = ""

        try:
            with tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False) as handle:
                handle.write(password)
                temp_password_path = handle.name
            os.chmod(temp_password_path, 0o600)

            result = run_command([
                "sudo",
                WRAPPER,
                "verify-super-admin-password",
                linux_username,
                temp_password_path,
            ], 20)
        finally:
            if temp_password_path and Path(temp_password_path).exists():
                Path(temp_password_path).unlink()

        return {"action": action, "verified": result["returncode"] == 0, "result": result}

    if action == "revoke":
        result = run_command(["sudo", WRAPPER, "revoke-super-admin", linux_username], 20)
        return {"action": action, "result": result}

    if action == "purge":
        result = run_command(["sudo", WRAPPER, "purge-super-admin", linux_username], 20)
        return {"action": action, "result": result}

    raise ValueError(f"Unsupported super admin action [{action}]")


def handle_acme_tls(payload: dict) -> dict:
    action = payload.get("action")
    app_root = require_absolute_path(os.environ.get("MAILPANEL_APP_ROOT", "/opt/mailpanel"), "app root")
    domain = require_domain(str(payload.get("domain") or "").strip().lower())
    email = require_email(str(payload.get("email") or "").strip().lower())
    profile = str(payload.get("profile") or "").strip()
    timeout = normalize_timeout(payload.get("timeout", 600), 600)

    if action not in {"provision-domain", "renew-domain"}:
        raise ValueError(f"Unsupported acme action [{action}]")
    if profile not in VALID_ACME_PROFILES:
        raise ValueError("Unsupported acme profile")

    result = run_command([
        "sudo",
        WRAPPER,
        "acme-tls-renew-domain" if action == "renew-domain" else "acme-tls-provision-domain",
        app_root,
        domain,
        email,
        profile,
    ], timeout)

    return {
        "action": action,
        "domain": domain,
        "profile": profile,
        "result": result,
    }


def handle_monitor(payload: dict) -> dict:
    action = payload.get("action")
    timeout = normalize_timeout(payload.get("timeout", 30), 30)
    if action == "queue-list":
        result = run_command(["sudo", WRAPPER, "queue-list"], timeout)
    elif action == "queue-count":
        result = run_command(["sudo", WRAPPER, "queue-count"], timeout)
    elif action == "queue-action":
        action_type = str(payload.get("action_type", "")).strip()
        if action_type not in {"deliver", "delete", "view"}:
            raise ValueError("Invalid queue action")
        msg_id = require_message_id(str(payload.get("msg_id", "")).strip())
        result = run_command(["sudo", WRAPPER, "queue-action", action_type, msg_id], timeout)
    elif action == "read-log":
        service = require_service(payload.get("service"))
        lines = require_positive_int(payload.get("lines", 200))
        keyword = normalize_keyword(str(payload.get("keyword", "")).strip())
        result = run_command(["sudo", WRAPPER, "read-log", service, lines, keyword], timeout)
    else:
        raise ValueError(f"Unsupported monitor action [{action}]")
        
    return {"action": action, "result": result}


def handle_security(payload: dict) -> dict:
    action = payload.get("action")
    timeout = normalize_timeout(payload.get("timeout", 30), 30)
    if action == "fail2ban-status":
        result = run_command(["sudo", WRAPPER, "fail2ban-status"], timeout)
    elif action == "fail2ban-unban":
        jail = require_jail(str(payload.get("jail", "")).strip())
        ip = require_ip(str(payload.get("ip", "")).strip())
        result = run_command(["sudo", WRAPPER, "fail2ban-unban", jail, ip], timeout)
    elif action == "rspamd-get-scores":
        result = run_command(["sudo", WRAPPER, "rspamd-get-scores"], timeout)
    elif action == "rspamd-set-scores":
        reject = require_decimal(payload.get("reject", 15), "Invalid rspamd score")
        add_header = require_decimal(payload.get("add_header", 6), "Invalid rspamd score")
        greylist = require_decimal(payload.get("greylist", 4), "Invalid rspamd score")
        result = run_command(["sudo", WRAPPER, "rspamd-set-scores", reject, add_header, greylist], timeout)
    elif action == "rspamd-get-multimap":
        import os
        def read_map(name):
            try:
                with open(f"/etc/rspamd/local.d/{name}.map", "r") as f:
                    return f.read()
            except FileNotFoundError:
                return ""
        return {
            "action": action,
            "ip_wl": read_map("ip_whitelist"),
            "ip_bl": read_map("ip_blacklist"),
            "sender_wl": read_map("sender_whitelist"),
            "sender_bl": read_map("sender_blacklist"),
            "rcpt_wl": read_map("rcpt_whitelist"),
            "rcpt_bl": read_map("rcpt_blacklist"),
            "result": {"stdout": "", "stderr": "", "exit_code": 0}
        }
    elif action == "rspamd-set-multimap":
        ip_wl = str(payload.get("ip_wl", ""))
        ip_bl = str(payload.get("ip_bl", ""))
        sender_wl = str(payload.get("sender_wl", ""))
        sender_bl = str(payload.get("sender_bl", ""))
        rcpt_wl = str(payload.get("rcpt_wl", ""))
        rcpt_bl = str(payload.get("rcpt_bl", ""))
        
        paths = []
        try:
            paths = [
                write_secure_temp(ip_wl),
                write_secure_temp(ip_bl),
                write_secure_temp(sender_wl),
                write_secure_temp(sender_bl),
                write_secure_temp(rcpt_wl),
                write_secure_temp(rcpt_bl),
            ]
            result = run_command(["sudo", WRAPPER, "rspamd-set-multimap", *paths], timeout)
        finally:
            for path in paths:
                safe_unlink(path)
    elif action == "rspamd-sync-tenant-rules":
        json_data = str(payload.get("json_data", ""))
        json.loads(json_data or "{}")
        p_json = ""
        try:
            p_json = write_secure_temp(json_data)
            result = run_command(["sudo", WRAPPER, "rspamd-set-tenant-rules", "rspamd", p_json], timeout)
        finally:
            safe_unlink(p_json)
    else:
        raise ValueError(f"Unsupported security action [{action}]")
        
    return {"action": action, "result": result}



def handle_manage_domain(payload: dict) -> dict:
    action = payload.get("action")
    if action == "rename":
        old_domain = require_domain(str(payload.get("old_domain", "")).strip().lower())
        new_domain = require_domain(str(payload.get("new_domain", "")).strip().lower())
        if not old_domain or not new_domain:
            return {"action": action, "result": {"returncode": 1, "stdout": "", "stderr": "Invalid domains"}}
            
        # Instead of doing it directly which fails due to permissions,
        # we call the wrapper script via sudo
        result = run_command(["sudo", WRAPPER, "rename-domain", old_domain, new_domain], 30)
        return {"action": action, "result": result}
    return {"action": action, "result": {"returncode": 1, "stdout": "", "stderr": "Unknown action"}}


def handle_mail_storage(payload: dict) -> dict:
    action = str(payload.get("action") or "").strip()
    vmail_root = require_absolute_path(str(payload.get("vmail_root") or "/var/vmail").strip(), "vmail root")
    timeout = normalize_timeout(payload.get("timeout", 30), 30)

    if action == "provision-mailbox-defaults":
        email = require_email(str(payload.get("email") or "").strip().lower())
        vmail_uid = require_numeric_id(payload.get("vmail_uid") or "", "Invalid vmail uid")
        vmail_gid = require_numeric_id(payload.get("vmail_gid") or "", "Invalid vmail gid")
        result = run_command(["sudo", WRAPPER, "provision-mailbox-data", vmail_root, email, vmail_uid, vmail_gid], timeout)
        return {"action": action, "email": email, "result": result}

    if action == "purge-mailbox":
        email = require_email(str(payload.get("email") or "").strip().lower())
        result = run_command(["sudo", WRAPPER, "purge-mailbox-data", vmail_root, email], timeout)
        return {"action": action, "email": email, "result": result}

    if action == "purge-domain":
        domain = require_domain(str(payload.get("domain") or "").strip().lower())
        result = run_command(["sudo", WRAPPER, "purge-domain-data", vmail_root, domain], timeout)
        return {"action": action, "domain": domain, "result": result}

    raise ValueError(f"Unsupported mail storage action [{action}]")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["execute-plan", "apply-config", "manage-super-admin", "manage-acme-tls", "monitor-system", "security-system", "manage-domain", "manage-mail-storage"])
    parser.add_argument("--payload-file")
    args = parser.parse_args()

    payload_text = Path(args.payload_file).read_text(encoding="utf-8") if args.payload_file else sys.stdin.read()
    payload = json.loads(payload_text or "{}")
    log_event("request.received", {"mode": args.mode})

    if args.mode == "execute-plan":
        result = handle_plan(payload)
    elif args.mode == "apply-config":
        result = handle_apply(payload)
    elif args.mode == "manage-acme-tls":
        result = handle_acme_tls(payload)
    elif args.mode == "monitor-system":
        result = handle_monitor(payload)
    elif args.mode == "security-system":
        result = handle_security(payload)
    elif args.mode == "manage-domain":
        result = handle_manage_domain(payload)
    elif args.mode == "manage-mail-storage":
        result = handle_mail_storage(payload)
    else:
        result = handle_super_admin(payload)

    log_event("request.completed", {"mode": args.mode, "result": result})
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
