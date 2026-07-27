#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/opt/mailpanel}"
AGENT_USER="${2:-mailpanel-agent}"
WEB_USER="${3:-www-data}"
SOURCE_ROOT="${4:-$APP_ROOT}"

id -u "$AGENT_USER" >/dev/null 2>&1 || useradd --system --home "$APP_ROOT" --shell /usr/sbin/nologin "$AGENT_USER"

if [ ! -d "$APP_ROOT" ]; then
  install -d -m 0755 "$APP_ROOT"
fi
install -d -m 0750 /var/lib/mailpanel /var/lib/mailpanel/generated
install -d -m 0755 /var/lib/mailpanel/generated/nginx /var/lib/mailpanel/generated/active/nginx
install -d -m 0755 /var/lib/mailpanel/generated/exim /var/lib/mailpanel/generated/active/exim
install -d -m 0750 /var/lib/mailpanel/generated/dovecot /var/lib/mailpanel/generated/rspamd /var/lib/mailpanel/generated/fail2ban /var/lib/mailpanel/generated/active /var/lib/mailpanel/generated/active/dovecot /var/lib/mailpanel/generated/active/rspamd /var/lib/mailpanel/generated/active/fail2ban
chown -R "$AGENT_USER":"$AGENT_USER" /var/lib/mailpanel

# The web process renders config drafts into the generated root; the agent then
# validates and activates them. Chowning the tree to the agent alone left it
# mode 0750 and unwritable by the web user, so draft generation failed with a
# permission error.
#
# Adding the web user to the agent group and marking the directories setgid
# (2770) lets both write, and files created by either inherit the shared group.
# The agent group owns nothing else, so this grants no other access.
if ! id -nG "$WEB_USER" | tr ' ' '\n' | grep -qx "$AGENT_USER"; then
  usermod -aG "$AGENT_USER" "$WEB_USER"
fi
find /var/lib/mailpanel -type d -exec chmod 2770 {} +
find /var/lib/mailpanel -type f -exec chmod 0660 {} + 2>/dev/null || true

install -d -m 0755 /var/log/mailpanel
chown "$AGENT_USER":"$AGENT_USER" /var/log/mailpanel
touch /var/log/mailpanel/agent.log
chown "$AGENT_USER":"$AGENT_USER" /var/log/mailpanel/agent.log
chmod 0640 /var/log/mailpanel/agent.log

if [ -d "$APP_ROOT/storage" ]; then
  chown -R "$WEB_USER":"$WEB_USER" "$APP_ROOT/storage"
  chmod -R ug+rwX "$APP_ROOT/storage"
fi

# The agent Python script is copied OUT of the application tree.
#
# $APP_ROOT is owned root:$WEB_USER mode 0750 so the web user can read it and
# nothing else can. The agent runs as $AGENT_USER, which is deliberately not in
# that group, so executing the script in place failed with:
#
#   /usr/bin/python3: can't open file '$APP_ROOT/agent/mailpanel_agent.py':
#   [Errno 13] Permission denied
#
# It lives in /usr/local/lib/mailpanel instead: root-owned, world-readable
# (it holds no secrets), and unaffected by application tree permissions or by a
# deploy rsync running underneath it.
install -d -o root -g root -m 0755 /usr/local/lib/mailpanel
install -o root -g root -m 0644 "$SOURCE_ROOT/agent/mailpanel_agent.py" /usr/local/lib/mailpanel/mailpanel_agent.py

install -o root -g root -m 0755 "$SOURCE_ROOT/agent/mailpanel-system-wrapper" /usr/local/bin/mailpanel-system-wrapper
install -o root -g root -m 0755 "$SOURCE_ROOT/agent/mailpanel-agent-runner" /usr/local/bin/mailpanel-agent
install -o root -g root -m 0755 "$SOURCE_ROOT/agent/mailpanel-web-agent-runner" /usr/local/bin/mailpanel-web-agent

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
