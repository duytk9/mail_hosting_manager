<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Support\SafePath;

final class WebmailPluginDeploymentService
{
    public const PLUGIN_ID = 'mailpanel-change-password';

    private readonly string $webmailRoot;
    private readonly string $passwordChangeEndpoint;

    public function __construct(
        private readonly bool $enabled,
        string $webmailRoot,
        string $passwordChangeEndpoint = '/api/webmail/password-change',
    ) {
        $this->webmailRoot = SafePath::absolute($webmailRoot, 'webmail root');
        $this->passwordChangeEndpoint = $this->safeEndpoint($passwordChangeEndpoint);
    }

    /**
     * @return array{enabled:bool,plugin_id:string,plugin_root:string,updated:bool,files:array<int,string>}
     */
    public function sync(): array
    {
        $pluginRoot = $this->pluginRoot();
        if (!$this->enabled || $this->isRoundcubePublicRoot()) {
            return [
                'enabled' => false,
                'plugin_id' => self::PLUGIN_ID,
                'plugin_root' => $pluginRoot,
                'updated' => false,
                'files' => [],
            ];
        }

        if (!is_dir($pluginRoot)) {
            mkdir($pluginRoot, 0775, true);
        }

        $files = [
            $pluginRoot . '/index.php' => $this->indexPhp(),
            $pluginRoot . '/mailpanel-change-password.js' => $this->javascript(),
            $pluginRoot . '/README' => $this->readme(),
        ];

        $updated = false;
        foreach ($files as $path => $content) {
            $current = is_file($path) ? (string) file_get_contents($path) : null;
            if ($current === $content) {
                continue;
            }

            $this->writeAtomic($path, $content);
            $updated = true;
        }

        return [
            'enabled' => true,
            'plugin_id' => self::PLUGIN_ID,
            'plugin_root' => $pluginRoot,
            'updated' => $updated,
            'files' => array_keys($files),
        ];
    }

    public function pluginRoot(): string
    {
        $root = realpath($this->webmailRoot) ?: $this->webmailRoot;

        return $root . '/data/_data_/_default_/plugins/' . self::PLUGIN_ID;
    }

    private function isRoundcubePublicRoot(): bool
    {
        $root = realpath($this->webmailRoot) ?: $this->webmailRoot;

        return is_file($root . '/index.php') && is_file($root . '/static.php');
    }

    private function safeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if (
            $endpoint === ''
            || !str_starts_with($endpoint, '/')
            || str_starts_with($endpoint, '//')
            || str_contains($endpoint, '..')
            || preg_match('/[\x00-\x1F\x7F]/', $endpoint)
        ) {
            throw new InvalidArgumentException('Invalid webmail password-change endpoint.');
        }

        return $endpoint;
    }

    private function indexPhp(): string
    {
        return <<<'PHP'
<?php

class MailpanelChangePasswordPlugin extends \RainLoop\Plugins\AbstractPlugin
{
    const
        NAME = 'MailPanel Change Password',
        AUTHOR = 'OpenAI',
        URL = 'https://openai.com',
        VERSION = '1.0.0',
        RELEASE = '2026-07-02',
        REQUIRED = '2.38.0',
        CATEGORY = 'Security',
        LICENSE = 'AGPL v3',
        DESCRIPTION = 'Adds a safe mailbox password-change form backed by MailPanel.';

    public function Init() : void
    {
        $this->addJs('mailpanel-change-password.js');
    }
}
PHP;
    }

    private function javascript(): string
    {
        $endpoint = json_encode($this->passwordChangeEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $template = <<<'JS'
(function () {
  'use strict';

  const endpoint = __ENDPOINT__;
  const sectionId = 'mailpanel-change-password';
  const styleId = 'mailpanel-change-password-style';

  const translations = {
    vi: {
      title: 'Đổi mật khẩu MailPanel',
      description: 'Mật khẩu mailbox sẽ đổi qua API bảo mật của panel để giữ audit log và password policy.',
      current: 'Mật khẩu hiện tại',
      next: 'Mật khẩu mới',
      confirm: 'Nhập lại mật khẩu mới',
      submit: 'Đổi mật khẩu',
      pending: 'Đang cập nhật...',
      success: 'Đổi mật khẩu thành công. Hãy dùng mật khẩu mới cho IMAP/SMTP/Webmail.',
      mismatch: 'Mật khẩu xác nhận chưa khớp.',
      missingEmail: 'Không xác định được mailbox hiện tại.',
      genericError: 'Đổi mật khẩu thất bại. Vui lòng thử lại.',
      required: 'Vui lòng nhập đầy đủ các trường.'
    },
    en: {
      title: 'MailPanel Password Change',
      description: 'Mailbox passwords are changed through the panel security API so audit logs and password policy stay enforced.',
      current: 'Current password',
      next: 'New password',
      confirm: 'Confirm new password',
      submit: 'Change password',
      pending: 'Updating...',
      success: 'Password changed successfully. Use the new password for IMAP/SMTP/Webmail.',
      mismatch: 'Confirmation password does not match.',
      missingEmail: 'Unable to determine the current mailbox.',
      genericError: 'Password change failed. Please try again.',
      required: 'Please fill in all fields.'
    }
  };

  function currentLang() {
    const language = String(globalThis.rl?.settings?.get?.('language') || document.documentElement.lang || 'en').toLowerCase();
    return language.startsWith('vi') ? 'vi' : 'en';
  }

  function t(key) {
    const lang = currentLang();
    return (translations[lang] && translations[lang][key]) || translations.en[key] || key;
  }

  function currentEmail() {
    return String(globalThis.rl?.settings?.get?.('Email') || '').trim();
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) {
      return;
    }

    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
      #${sectionId} {
        margin: 0 0 1.25rem 0;
        padding: 1rem;
        border: 1px solid rgba(120, 136, 158, 0.24);
        border-radius: 12px;
        background: rgba(18, 26, 39, 0.035);
      }
      #${sectionId} .mailpanel-change-password__title {
        font-size: 1.05rem;
        font-weight: 600;
        margin-bottom: 0.35rem;
      }
      #${sectionId} .mailpanel-change-password__desc {
        margin-bottom: 1rem;
        opacity: 0.82;
      }
      #${sectionId} .mailpanel-change-password__grid {
        display: grid;
        gap: 0.75rem;
      }
      #${sectionId} .mailpanel-change-password__field {
        display: grid;
        gap: 0.35rem;
      }
      #${sectionId} .mailpanel-change-password__field input {
        width: 100%;
        max-width: 440px;
        box-sizing: border-box;
      }
      #${sectionId} .mailpanel-change-password__actions {
        margin-top: 0.2rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
      }
      #${sectionId} .mailpanel-change-password__status {
        font-size: 0.95rem;
      }
      #${sectionId} .mailpanel-change-password__status.is-error {
        color: #c0392b;
      }
      #${sectionId} .mailpanel-change-password__status.is-success {
        color: #1e8449;
      }
    `;
    document.head.appendChild(style);
  }

  function setStatus(node, message, type) {
    node.textContent = message || '';
    node.className = 'mailpanel-change-password__status' + (type ? ' is-' + type : '');
  }

  async function handleSubmit(event) {
    event.preventDefault();

    const form = event.currentTarget;
    const email = currentEmail();
    const currentPassword = form.querySelector('[name="current_password"]').value;
    const newPassword = form.querySelector('[name="new_password"]').value;
    const confirmPassword = form.querySelector('[name="confirm_password"]').value;
    const button = form.querySelector('button[type="submit"]');
    const status = form.querySelector('.mailpanel-change-password__status');
    const buttonLabel = button.dataset.label || button.textContent;

    if (!email) {
      setStatus(status, t('missingEmail'), 'error');
      return;
    }

    if (!currentPassword || !newPassword || !confirmPassword) {
      setStatus(status, t('required'), 'error');
      return;
    }

    if (newPassword !== confirmPassword) {
      setStatus(status, t('mismatch'), 'error');
      return;
    }

    button.disabled = true;
    button.textContent = t('pending');
    setStatus(status, '');

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-MailPanel-Webmail-Bridge': '1'
        },
        body: JSON.stringify({
          email: email,
          current_password: currentPassword,
          new_password: newPassword
        })
      });

      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.success === false) {
        const message = payload?.error?.message || t('genericError');
        setStatus(status, message, 'error');
        return;
      }

      form.reset();
      setStatus(status, t('success'), 'success');
    } catch (error) {
      setStatus(status, t('genericError'), 'error');
    } finally {
      button.disabled = false;
      button.textContent = buttonLabel;
    }
  }

  function injectSection(viewModel) {
    const container = viewModel?.viewModelDom;
    if (!container || container.querySelector('#' + sectionId)) {
      return;
    }

    ensureStyle();

    const wrapper = document.createElement('section');
    wrapper.id = sectionId;
    wrapper.innerHTML = `
      <div class="mailpanel-change-password__title">\${t('title')}</div>
      <div class="mailpanel-change-password__desc">\${t('description')}</div>
      <form class="mailpanel-change-password__grid" autocomplete="off">
        <label class="mailpanel-change-password__field">
          <span>\${t('current')}</span>
          <input name="current_password" type="password" autocomplete="current-password" required>
        </label>
        <label class="mailpanel-change-password__field">
          <span>\${t('next')}</span>
          <input name="new_password" type="password" autocomplete="new-password" required>
        </label>
        <label class="mailpanel-change-password__field">
          <span>\${t('confirm')}</span>
          <input name="confirm_password" type="password" autocomplete="new-password" required>
        </label>
        <div class="mailpanel-change-password__actions">
          <button type="submit" class="btn" data-label="\${t('submit')}">\${t('submit')}</button>
          <span class="mailpanel-change-password__status" aria-live="polite"></span>
        </div>
      </form>
    `;

    const anchor = container.querySelector('.legend');
    container.insertBefore(wrapper, anchor || container.firstChild);
    wrapper.querySelector('form').addEventListener('submit', handleSubmit);
  }

  function shouldAttach(detail) {
    return detail && detail.constructor && detail.constructor.name === 'UserSettingsSecurity';
  }

  addEventListener('rl-view-model', function (event) {
    if (shouldAttach(event.detail)) {
      injectSection(event.detail);
    }
  });

  addEventListener('rl-view-model.create', function (event) {
    if (shouldAttach(event.detail)) {
      setTimeout(function () {
        injectSection(event.detail);
      }, 0);
    }
  });
})();
JS;

        return str_replace('__ENDPOINT__', (string) $endpoint, $template);
    }

    private function readme(): string
    {
        return <<<TXT
MailPanel managed plugin.

Purpose:
- Adds a safe password change form to legacy webmail Settings > Security
- Sends mailbox password changes to MailPanel via {$this->passwordChangeEndpoint}
- Keeps password policy, password history, and audit logging in MailPanel
TXT;
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write webmail plugin file.');
        }

        @chmod($temporaryPath, 0640);

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to publish webmail plugin file.');
        }
    }
}
