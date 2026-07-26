#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/opt/mailpanel}"
AGENT_USER="${2:-mailpanel-agent}"
WEB_USER="${3:-www-data}"

id -u "$AGENT_USER" >/dev/null 2>&1 || useradd --system --home "$APP_ROOT" --shell /usr/sbin/nologin "$AGENT_USER"

install -d -m 0755 "$APP_ROOT"
install -d -m 0750 /var/lib/mailpanel /var/lib/mailpanel/generated
install -d -m 0755 /var/lib/mailpanel/generated/nginx /var/lib/mailpanel/generated/active/nginx
install -d -m 0755 /var/lib/mailpanel/generated/exim /var/lib/mailpanel/generated/active/exim
install -d -m 0750 /var/lib/mailpanel/generated/dovecot /var/lib/mailpanel/generated/rspamd /var/lib/mailpanel/generated/fail2ban /var/lib/mailpanel/generated/active /var/lib/mailpanel/generated/active/dovecot /var/lib/mailpanel/generated/active/rspamd /var/lib/mailpanel/generated/active/fail2ban
chown -R "$AGENT_USER":"$AGENT_USER" /var/lib/mailpanel
install -d -m 0755 /var/log/mailpanel
chown "$AGENT_USER":"$AGENT_USER" /var/log/mailpanel
touch /var/log/mailpanel/agent.log
chown "$AGENT_USER":"$AGENT_USER" /var/log/mailpanel/agent.log
chmod 0640 /var/log/mailpanel/agent.log

if [ -d "$APP_ROOT/storage" ]; then
  chown -R "$WEB_USER":"$WEB_USER" "$APP_ROOT/storage"
  chmod -R ug+rwX "$APP_ROOT/storage"
fi

install -o root -g root -m 0755 "$APP_ROOT/agent/mailpanel-system-wrapper" /usr/local/bin/mailpanel-system-wrapper
install -o root -g root -m 0755 "$APP_ROOT/agent/mailpanel-agent-runner" /usr/local/bin/mailpanel-agent
install -o root -g root -m 0755 "$APP_ROOT/agent/mailpanel-web-agent-runner" /usr/local/bin/mailpanel-web-agent

cat >/etc/sudoers.d/mailpanel-agent <<'EOF'
Defaults:mailpanel-agent !requiretty
mailpanel-agent ALL=(root) NOPASSWD: /usr/local/bin/mailpanel-system-wrapper *
EOF
chmod 0440 /etc/sudoers.d/mailpanel-agent

cat >/etc/sudoers.d/mailpanel-web-agent <<EOF
Defaults:${WEB_USER} !requiretty
${WEB_USER} ALL=(root) NOPASSWD: /usr/local/bin/mailpanel-web-agent *
EOF
chmod 0440 /etc/sudoers.d/mailpanel-web-agent
