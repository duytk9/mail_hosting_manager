(() => {
    const body = document.body;
    const sidebar = document.querySelector('[data-sidebar]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const openButtons = document.querySelectorAll('[data-sidebar-open]');
    const closeButtons = document.querySelectorAll('[data-sidebar-close]');
    const confirmDialog = document.querySelector('[data-confirm-dialog]');
    const confirmTitle = confirmDialog?.querySelector('[data-confirm-title]');
    const confirmDescription = confirmDialog?.querySelector('[data-confirm-description]');
    const confirmAccept = confirmDialog?.querySelector('[data-confirm-accept]');
    const confirmCancel = confirmDialog?.querySelector('[data-confirm-cancel]');
    const modalTargets = [
        { id: 'tenant-create', action: 'Tạo user level', editParams: ['edit_tenant'] },
        { id: 'tenant-admin-edit', action: 'Sửa tài khoản chủ sở hữu', editParams: ['edit_tenant_admin'] },
        { id: 'mailbox-create', action: 'Tạo tài khoản mail' },
        { id: 'domain-create', action: 'Thêm domain' },
        { id: 'package-create', action: 'Tạo gói dịch vụ', editParams: ['edit_package'] },
        { id: 'alias-create', action: 'Tạo bí danh' },
        { id: 'forward-create', action: 'Tạo chuyển tiếp' },
        { id: 'mail-group-create', action: 'Tạo nhóm mail', editParams: ['edit_group'], formOnly: true },
        { id: 'super-admin-create', action: 'Tạo quản trị viên' },
        { id: 'super-admin-ip-allowlist', action: 'Cấu hình IP cho phép' },
        { id: 'dns-check-ssl', action: 'Cấp chứng chỉ SSL', formOnly: true },
    ];

    const openSidebar = () => {
        if (!sidebar || !backdrop) {
            return;
        }

        sidebar.classList.add('is-open');
        backdrop.classList.add('is-active');
        body.classList.add('is-scroll-locked');
    };

    const closeSidebar = () => {
        if (!sidebar || !backdrop) {
            return;
        }

        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-active');
        body.classList.remove('is-scroll-locked');
    };

    openButtons.forEach((button) => button.addEventListener('click', openSidebar));
    closeButtons.forEach((button) => button.addEventListener('click', closeSidebar));

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024) {
            closeSidebar();
        }
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.action-menu[open], .filters-panel[open]').forEach((menu) => {
            if (!menu.contains(event.target)) {
                menu.removeAttribute('open');
            }
        });
    });

    const contentAnchor = () => {
        const content = document.querySelector('.content');
        const flashMessages = content?.querySelectorAll(':scope > .flash') || [];
        return {
            content,
            anchor: flashMessages.length > 0
                ? flashMessages[flashMessages.length - 1].nextSibling
                : content?.firstChild,
        };
    };

    const movePageActionBarToTop = (actionBar) => {
        const { content, anchor } = contentAnchor();
        if (!(content instanceof HTMLElement) || !(actionBar instanceof HTMLElement)) {
            return actionBar;
        }

        if (actionBar.parentElement !== content || actionBar !== anchor) {
            content.insertBefore(actionBar, anchor || null);
        }

        return actionBar;
    };

    const getPageActionBar = () => {
        let actionBar = document.querySelector('[data-page-action-bar]');
        if (actionBar instanceof HTMLElement) {
            return movePageActionBarToTop(actionBar);
        }

        const { content, anchor } = contentAnchor();
        actionBar = document.createElement('section');
        actionBar.className = 'page-action-bar page-action-bar--actions-only';
        actionBar.dataset.pageActionBar = '1';

        const actionItems = document.createElement('div');
        actionItems.className = 'page-action-bar__actions';
        actionItems.dataset.pageActionItems = '1';
        actionBar.appendChild(actionItems);
        content?.insertBefore(actionBar, anchor || null);

        return actionBar;
    };

    const getPageActionItems = () => {
        const actionBar = getPageActionBar();
        let actionItems = actionBar.querySelector('[data-page-action-items]');

        if (!(actionItems instanceof HTMLElement)) {
            actionItems = document.createElement('div');
            actionItems.className = 'page-action-bar__actions';
            actionItems.dataset.pageActionItems = '1';
            actionBar.appendChild(actionItems);
        }

        return actionItems;
    };

    const hydratePageActionBar = () => {
        const actionItems = getPageActionItems();

        document.querySelectorAll('[data-page-action-seed]').forEach((seed) => {
            Array.from(seed.children).forEach((action) => actionItems.appendChild(action));
            seed.remove();
        });

        if (actionItems.children.length === 0 && !document.querySelector('.page-action-bar__filters')) {
            actionItems.closest('[data-page-action-bar]')?.remove();
        }
    };

    hydratePageActionBar();

    const getModalTitle = (source) => {
        const heading = source.querySelector('.panel-header h2, summary, h2, h3');
        return heading?.textContent?.replace(/\s+/g, ' ').trim() || 'Thao tác';
    };

    const appendModalCloseButton = (source, dialog, title) => {
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'icon-btn admin-modal__close';
        closeButton.setAttribute('aria-label', 'Đóng');
        closeButton.dataset.modalClose = '1';
        closeButton.textContent = '×';
        closeButton.addEventListener('click', () => {
            if (dialog.dataset.isDirty === '1') {
                if (!confirm('Dữ liệu đang nhập sẽ bị mất. Bạn có chắc chắn muốn đóng không?')) {
                    return;
                }
            }
            dialog.close();
        });

        if (!source.querySelector('.admin-modal__close')) {
            const summary = source instanceof HTMLDetailsElement ? source.querySelector(':scope > summary') : null;
            if (summary?.nextSibling) {
                source.insertBefore(closeButton, summary.nextSibling);
                return;
            }

            source.insertBefore(closeButton, source.firstChild);
        }

        dialog.setAttribute('aria-label', title);
    };

    const createModalLauncher = (dialog, title, actionLabel, targetId) => {
        const actionBar = getPageActionItems();
        const matchingLaunchers = Array.from(actionBar.querySelectorAll('a[href]')).filter((link) => {
            try {
                const targetUrl = new URL(link.getAttribute('href') || '', window.location.href);
                return targetUrl.pathname === window.location.pathname && targetUrl.hash === `#${targetId}`;
            } catch {
                return false;
            }
        });

        if (matchingLaunchers.length > 0) {
            const launcher = matchingLaunchers.shift();
            matchingLaunchers.forEach((duplicate) => duplicate.remove());
            launcher.dataset.openModal = dialog.id;
            launcher.setAttribute('role', 'button');
            return launcher;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-primary';
        button.textContent = actionLabel || title;
        button.dataset.openModal = dialog.id;
        actionBar.appendChild(button);
        return button;
    };

    const openAdminModal = (dialog) => {
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }

        if (typeof dialog.showModal !== 'function') {
            dialog.setAttribute('open', 'open');
            return;
        }

        if (!dialog.open) {
            dialog.showModal();
        }
    };

    const hydrateAdminModals = () => {
        const params = new URLSearchParams(window.location.search);
        const hashTarget = window.location.hash.replace(/^#/, '');

        modalTargets.forEach((definition) => {
            const target = document.getElementById(definition.id);
            if (!(target instanceof HTMLElement) || target.dataset.modalHydrated === '1') {
                return;
            }

            const title = getModalTitle(target);
            const dialog = document.createElement('dialog');
            dialog.className = 'admin-modal';
            dialog.id = `${definition.id}-modal`;
            dialog.dataset.adminModal = '1';
            target.dataset.modalHydrated = '1';

            if (definition.formOnly) {
                const form = target.querySelector(':scope > form');
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                const modalPanel = document.createElement('section');
                modalPanel.className = 'panel admin-modal__panel';

                const header = target.querySelector('.panel-header')?.cloneNode(true);
                if (header) {
                    modalPanel.appendChild(header);
                }

                modalPanel.appendChild(form);
                appendModalCloseButton(modalPanel, dialog, title);
                dialog.appendChild(modalPanel);
                document.body.appendChild(dialog);
                createModalLauncher(dialog, title, definition.action, definition.id);
            } else {
                if (target instanceof HTMLDetailsElement) {
                    target.open = true;
                }

                createModalLauncher(dialog, title, definition.action, definition.id);
                target.classList.add('admin-modal__panel');
                appendModalCloseButton(target, dialog, title);
                dialog.appendChild(target);
                document.body.appendChild(dialog);
            }

            const form = dialog.querySelector('form');
            if (form) {
                const markDirty = () => { dialog.dataset.isDirty = '1'; };
                form.addEventListener('input', markDirty);
                form.addEventListener('change', markDirty);
                form.addEventListener('submit', () => {
                    delete dialog.dataset.isDirty;
                });
            }

            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    if (dialog.dataset.isDirty === '1') {
                        if (!confirm('Dữ liệu đang nhập sẽ bị mất. Bạn có chắc chắn muốn đóng không?')) {
                            return;
                        }
                    }
                    dialog.close();
                }
            });

            dialog.addEventListener('cancel', (event) => {
                if (dialog.dataset.isDirty === '1') {
                    if (!confirm('Dữ liệu đang nhập sẽ bị mất. Bạn có chắc chắn muốn đóng không?')) {
                        event.preventDefault();
                    }
                }
            });

            dialog.addEventListener('close', () => {
                const url = new URL(window.location.href);
                let changed = false;
                let wasEditModal = false;
                
                if (url.hash === `#${definition.id}`) {
                    url.hash = '';
                    changed = true;
                }
                
                if (definition.editParams) {
                    definition.editParams.forEach(param => {
                        if (url.searchParams.has(param)) {
                            url.searchParams.delete(param);
                            changed = true;
                            wasEditModal = true;
                        }
                    });
                }
                
                if (changed) {
                    let newUrl = url.toString();
                    if (!url.hash && newUrl.endsWith('#')) {
                        newUrl = newUrl.slice(0, -1);
                    }
                    if (wasEditModal) {
                        window.location.href = newUrl;
                    } else {
                        window.history.replaceState(null, '', newUrl);
                    }
                }
            });

            const shouldOpen = hashTarget === definition.id
                || (definition.editParams || []).some((name) => params.has(name));

            if (shouldOpen) {
                window.setTimeout(() => openAdminModal(dialog), 0);
            }
        });
    };

    hydrateAdminModals();

    const hydrateInlineEditModals = () => {
        document.querySelectorAll('.table-wrap form.inline-actions').forEach((form, index) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.modalHydrated === '1') {
                return;
            }

            const editableField = form.querySelector('input:not([type="hidden"]):not([type="submit"]), select, textarea');
            if (!editableField) {
                return;
            }

            const originalCell = form.closest('td');
            const row = form.closest('tr');
            const rowLabel = row?.querySelector('strong')?.textContent?.replace(/\s+/g, ' ').trim() || `row ${index + 1}`;
            const title = `Sửa ${rowLabel}`;
            const dialog = document.createElement('dialog');
            dialog.className = 'admin-modal admin-modal--compact';
            dialog.id = `inline-edit-modal-${index + 1}`;
            dialog.dataset.adminModal = '1';

            const panel = document.createElement('section');
            panel.className = 'panel admin-modal__panel';

            const header = document.createElement('div');
            header.className = 'panel-header';

            const heading = document.createElement('h2');
            heading.textContent = title;
            header.appendChild(heading);
            panel.appendChild(header);

            form.dataset.modalHydrated = '1';
            panel.appendChild(form);
            appendModalCloseButton(panel, dialog, title);
            dialog.appendChild(panel);
            document.body.appendChild(dialog);

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-secondary btn-sm';
            button.textContent = 'Sửa';
            button.dataset.openModal = dialog.id;

            if (originalCell) {
                originalCell.insertBefore(button, originalCell.firstChild);
            }

            if (form) {
                const markDirty = () => { dialog.dataset.isDirty = '1'; };
                form.addEventListener('input', markDirty);
                form.addEventListener('change', markDirty);
                form.addEventListener('submit', () => {
                    delete dialog.dataset.isDirty;
                });
            }

            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    if (dialog.dataset.isDirty === '1') {
                        if (!confirm('Dữ liệu đang nhập sẽ bị mất. Bạn có chắc chắn muốn đóng không?')) {
                            return;
                        }
                    }
                    dialog.close();
                }
            });

            dialog.addEventListener('cancel', (event) => {
                if (dialog.dataset.isDirty === '1') {
                    if (!confirm('Dữ liệu đang nhập sẽ bị mất. Bạn có chắc chắn muốn đóng không?')) {
                        event.preventDefault();
                    }
                }
            });
        });
    };

    hydrateInlineEditModals();

    const hydrateTabs = () => {
        document.querySelectorAll('.tab-container').forEach(container => {
            if (container.dataset.tabsHydrated === '1') return;
            container.dataset.tabsHydrated = '1';
            
            const navs = container.querySelectorAll('.tab-btn');
            const panes = container.querySelectorAll('.tab-pane');
            
            if (panes.length > 0) {
                const wrap = document.createElement('div');
                wrap.className = 'tab-content-wrap';
                panes[0].parentNode.insertBefore(wrap, panes[0]);
                panes.forEach(p => wrap.appendChild(p));
            }
            
            navs.forEach((nav, index) => {
                nav.addEventListener('click', (e) => {
                    e.preventDefault();
                    navs.forEach(n => n.classList.remove('is-active'));
                    panes.forEach(p => p.classList.remove('is-active'));
                    
                    nav.classList.add('is-active');
                    if (panes[index]) {
                        panes[index].classList.add('is-active');
                    }
                });
            });
        });
    };

    const hydrateMailboxQuotaForm = () => {
        const domainSelect = document.getElementById('mailbox_domain_id');
        const quotaInput = document.getElementById('mailbox_quota_mb');
        const quotaNotice = document.getElementById('mailbox_quota_notice');
        const submitButton = document.getElementById('mailbox_create_submit');

        if (!(domainSelect instanceof HTMLSelectElement)
            || !(quotaInput instanceof HTMLInputElement)
            || !(quotaNotice instanceof HTMLElement)
            || !(submitButton instanceof HTMLButtonElement)
            || domainSelect.dataset.quotaHydrated === '1') {
            return;
        }

        domainSelect.dataset.quotaHydrated = '1';
        const formatNumber = (value) => new Intl.NumberFormat('en-US').format(Number(value || 0));
        let lastDomainValue = null;

        const clampQuotaValue = (recommendedQuota, maxMailboxQuota, resetToRecommended = false) => {
            const hasQuotaCeiling = maxMailboxQuota > 0;
            const ceiling = hasQuotaCeiling ? maxMailboxQuota : Math.max(1, recommendedQuota, Number(quotaInput.value || 0));
            const currentValue = resetToRecommended ? recommendedQuota : Number(quotaInput.value || recommendedQuota || 1);
            quotaInput.value = String(Math.min(Math.max(1, currentValue), ceiling));
            if (hasQuotaCeiling) {
                quotaInput.max = String(ceiling);
            } else {
                quotaInput.removeAttribute('max');
            }
        };

        const refreshQuotaNotice = () => {
            const option = domainSelect.options[domainSelect.selectedIndex];
            if (!option) {
                quotaNotice.textContent = '';
                quotaInput.disabled = true;
                submitButton.disabled = true;
                return;
            }

            const tenantName = option.dataset.tenantName || 'tenant này';
            const allocatedQuota = Number(option.dataset.allocatedQuotaMb || 0);
            const assignedQuota = Number(option.dataset.assignedQuotaMb || 0);
            const usedQuota = Number(option.dataset.usedQuotaMb || 0);
            const remainingQuota = Number(option.dataset.remainingQuotaMb || 0);
            const maxMailboxQuota = Number(option.dataset.maxMailboxQuotaMb || 0);
            const recommendedQuota = Number(option.dataset.recommendedQuotaMb || option.dataset.defaultQuotaMb || 1);
            const remainingMailboxSlots = Number(option.dataset.remainingMailboxSlots || 0);
            const canCreate = remainingMailboxSlots > 0;
            const domainChanged = lastDomainValue !== option.value;
            lastDomainValue = option.value;

            clampQuotaValue(recommendedQuota, maxMailboxQuota, domainChanged);
            quotaInput.disabled = !canCreate;
            submitButton.disabled = !canCreate;

            if (remainingMailboxSlots <= 0) {
                quotaNotice.textContent = `${tenantName} đã hết lượt tạo hộp thư. Vui lòng tăng giới hạn hộp thư trước khi tạo thêm tài khoản.`;
                return;
            }

            if (remainingQuota <= 0) {
                quotaNotice.textContent = `${tenantName}: dung lượng thực tế sử dụng ${formatNumber(usedQuota)}/${formatNumber(allocatedQuota)} MB. Bạn vẫn có thể cấp tối đa ${formatNumber(maxMailboxQuota)} MB/account, nhưng thư gửi đến sẽ bị từ chối do vượt quá dung lượng cho đến khi dung lượng giảm xuống.`;
                return;
            }

            quotaNotice.textContent = `${tenantName}: dung lượng thực tế sử dụng ${formatNumber(usedQuota)}/${formatNumber(allocatedQuota)} MB, đã gán ${formatNumber(assignedQuota)} MB, per-account max ${formatNumber(maxMailboxQuota)} MB, ${formatNumber(remainingMailboxSlots)} hộp thư còn lại có thể tạo.`;
        };

        domainSelect.addEventListener('change', refreshQuotaNotice);
        quotaInput.addEventListener('input', () => {
            const option = domainSelect.options[domainSelect.selectedIndex];
            const maxMailboxQuota = Number(option?.dataset.maxMailboxQuotaMb || 1);
            const recommendedQuota = Number(option?.dataset.recommendedQuotaMb || option?.dataset.defaultQuotaMb || 1);
            clampQuotaValue(recommendedQuota, maxMailboxQuota);
        });

        refreshQuotaNotice();
    };

    const hydrateTenantFormHelpers = () => {
        const toggle = document.getElementById('toggle-custom-limits');
        const fields = document.getElementById('custom-limits-fields');
        if (toggle instanceof HTMLInputElement && fields instanceof HTMLElement && toggle.dataset.customLimitsHydrated !== '1') {
            toggle.dataset.customLimitsHydrated = '1';
            toggle.addEventListener('change', () => {
                fields.hidden = !toggle.checked;
            });
        }

        const nameInput = document.getElementById('tenant_name');
        const slugInput = document.getElementById('tenant_slug');
        if (nameInput instanceof HTMLInputElement
            && slugInput instanceof HTMLInputElement
            && !slugInput.value
            && nameInput.dataset.slugHydrated !== '1') {
            nameInput.dataset.slugHydrated = '1';
            nameInput.addEventListener('input', () => {
                slugInput.value = nameInput.value
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/(^-|-$)/g, '');
            });
        }

        const expiresInput = document.getElementById('tenant_expires_at');
        const graceInput = document.getElementById('tenant_grace_until');
        const syncGraceMinimum = () => {
            if (!(expiresInput instanceof HTMLInputElement) || !(graceInput instanceof HTMLInputElement)) {
                return;
            }

            if (!expiresInput.value) {
                graceInput.removeAttribute('min');
                return;
            }

            const expiry = new Date(`${expiresInput.value}T00:00:00`);
            if (Number.isNaN(expiry.getTime())) {
                return;
            }

            expiry.setDate(expiry.getDate() + 1);
            const minimum = expiry.toISOString().slice(0, 10);
            graceInput.min = minimum;
            if (graceInput.value && graceInput.value < minimum) {
                graceInput.value = minimum;
            }
        };

        if (expiresInput instanceof HTMLInputElement && expiresInput.dataset.graceHydrated !== '1') {
            expiresInput.dataset.graceHydrated = '1';
            expiresInput.addEventListener('change', syncGraceMinimum);
            syncGraceMinimum();
        }

        const passwordInput = document.getElementById('admin_password');
        if (passwordInput instanceof HTMLInputElement && !passwordInput.value && passwordInput.dataset.generated !== '1') {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+';
            const values = new Uint32Array(16);
            window.crypto?.getRandomValues?.(values);
            let password = '';
            for (let index = 0; index < 16; index += 1) {
                const value = values[index] || Math.floor(Math.random() * chars.length);
                password += chars.charAt(value % chars.length);
            }
            passwordInput.value = `${password.slice(0, -4)}A1a!`;
            passwordInput.dataset.generated = '1';
        }
    };

    hydratePageActionBar();
    hydrateAdminModals();
    hydrateInlineEditModals();
    hydrateTabs();
    hydrateMailboxQuotaForm();
    hydrateTenantFormHelpers();

    document.addEventListener('click', (event) => {
        const closeTrigger = event.target instanceof Element
            ? event.target.closest('[data-modal-close]')
            : null;
        if (closeTrigger instanceof HTMLElement) {
            const dialog = closeTrigger.closest('dialog');
            if (dialog instanceof HTMLDialogElement) {
                event.preventDefault();
                dialog.close();
            }
            return;
        }

        const trigger = event.target instanceof Element
            ? event.target.closest('[data-open-modal]')
            : null;

        if (!(trigger instanceof HTMLElement)) {
            return;
        }

        const dialog = document.getElementById(trigger.dataset.openModal || '');
        if (dialog instanceof HTMLDialogElement) {
            event.preventDefault();
            openAdminModal(dialog);
        }
    });

    document.querySelectorAll('.table-wrap').forEach((wrap) => {
        const table = wrap.querySelector('table');
        if (!table) {
            return;
        }

        const headers = Array.from(table.querySelectorAll('thead th')).map((header) =>
            header.textContent.replace(/\s+/g, ' ').trim()
        );

        if (headers.length > 0) {
            table.querySelectorAll('tbody tr').forEach((row) => {
                Array.from(row.children).forEach((cell, index) => {
                    if (!cell.getAttribute('data-label')) {
                        cell.setAttribute('data-label', headers[index] ?? '');
                    }
                });
            });
        }

        wrap.classList.add('is-responsive');
    });

    document.querySelectorAll('.js-admin-filters[data-live-submit="1"]').forEach((form) => {
        const debounceMs = Number.parseInt(form.dataset.debounce || '250', 10);
        let timer = null;

        const queueSubmit = () => {
            if (timer !== null) {
                window.clearTimeout(timer);
            }

            timer = window.setTimeout(() => form.requestSubmit(), debounceMs);
        };

        form.querySelectorAll('input[type="search"], input[type="text"], input[type="email"], input:not([type])').forEach((input) => {
            input.addEventListener('input', queueSubmit);
        });

        form.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', () => form.requestSubmit());
        });

        form.querySelectorAll('input[type="number"], input[type="date"]').forEach((input) => {
            input.addEventListener('change', () => form.requestSubmit());
        });
    });

    const setSubmitterBusy = (submitter) => {
        if (!(submitter instanceof HTMLElement) || submitter.dataset.busy === '1') {
            return;
        }

        submitter.dataset.busy = '1';
        submitter.dataset.originalLabel = submitter.textContent ?? '';
        submitter.classList.add('is-loading');
        submitter.setAttribute('aria-busy', 'true');

        if ('disabled' in submitter) {
            submitter.disabled = true;
        }

        submitter.textContent = submitter.dataset.loadingLabel || 'Đang xử lý...';
    };

    const extractConfirmMessage = (value) => {
        if (typeof value !== 'string' || !value.includes('confirm(')) {
            return '';
        }

        const match = value.match(/confirm\((['"`])([\s\S]*?)\1\)/);
        return match?.[2]?.trim() || '';
    };

    const upgradeInlineConfirm = (element, attributeName, targetAttribute) => {
        const inlineValue = element.getAttribute(attributeName);
        const message = extractConfirmMessage(inlineValue);

        if (!message) {
            return;
        }

        element.setAttribute(targetAttribute, message);
        element.removeAttribute(attributeName);
    };

    const askConfirmation = (message) => {
        if (!message) {
            return Promise.resolve(true);
        }

        if (!(confirmDialog instanceof HTMLDialogElement) || typeof confirmDialog.showModal !== 'function') {
            return Promise.resolve(window.confirm(message));
        }

        if (confirmTitle) {
            confirmTitle.textContent = 'Xác nhận';
        }

        if (confirmDescription) {
            confirmDescription.textContent = message;
        }

        return new Promise((resolve) => {
            const cleanup = () => {
                confirmAccept?.removeEventListener('click', onAccept);
                confirmCancel?.removeEventListener('click', onCancel);
                confirmDialog.removeEventListener('cancel', onCancel);
                confirmDialog.removeEventListener('close', onClose);
            };

            const settle = (accepted) => {
                cleanup();
                if (confirmDialog.open) {
                    confirmDialog.close(accepted ? 'confirm' : 'cancel');
                }
                resolve(accepted);
            };

            const onAccept = () => settle(true);
            const onCancel = (event) => {
                event?.preventDefault?.();
                settle(false);
            };
            const onClose = () => settle(confirmDialog.returnValue === 'confirm');

            confirmAccept?.addEventListener('click', onAccept, { once: true });
            confirmCancel?.addEventListener('click', onCancel, { once: true });
            confirmDialog.addEventListener('cancel', onCancel, { once: true });
            confirmDialog.addEventListener('close', onClose, { once: true });
            confirmDialog.showModal();
        });
    };

    document.querySelectorAll('form[onsubmit*="confirm("]').forEach((form) => {
        upgradeInlineConfirm(form, 'onsubmit', 'data-confirm');
    });

    document.querySelectorAll('[onclick*="confirm("]').forEach((element) => {
        const targetAttribute = element.tagName === 'A' ? 'data-confirm-link' : 'data-confirm-click';
        upgradeInlineConfirm(element, 'onclick', targetAttribute);
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const message = form.dataset.confirm;

        if (form.dataset.domainRename === '1' && form.dataset.renameArmed !== '1') {
            event.preventDefault();
            const currentDomain = form.dataset.currentDomain || '';
            const newName = window.prompt('Nhập tên miền mới (các email liên quan cũng sẽ được đổi):', currentDomain);
            if (!newName || newName === currentDomain) {
                return;
            }

            const targetInput = form.elements.namedItem('new_domain');
            if (targetInput instanceof HTMLInputElement) {
                targetInput.value = newName;
            }

            form.dataset.renameArmed = '1';
            if (submitter instanceof HTMLElement && typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter);
            } else if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
            return;
        }

        if (form.dataset.renameArmed === '1') {
            delete form.dataset.renameArmed;
        }

        if (message && form.dataset.confirmArmed !== '1') {
            event.preventDefault();
            const confirmed = await askConfirmation(message);

            if (!confirmed) {
                return;
            }

            form.dataset.confirmArmed = '1';
            if (submitter instanceof HTMLElement && typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter);
            } else if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
            return;
        }

        if (form.dataset.confirmArmed === '1') {
            delete form.dataset.confirmArmed;
        }

        if (!form.noValidate && !form.checkValidity()) {
            return;
        }

        if (submitter) {
            setSubmitterBusy(submitter);
        }
    }, true);

    document.addEventListener('click', async (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest('[data-confirm-click], [data-confirm-link]')
            : null;

        if (!(trigger instanceof HTMLElement)) {
            return;
        }

        const message = trigger.dataset.confirmClick || trigger.dataset.confirmLink || '';
        if (!message) {
            return;
        }

        event.preventDefault();
        const confirmed = await askConfirmation(message);

        if (!confirmed) {
            return;
        }

        if (trigger instanceof HTMLAnchorElement && trigger.href) {
            window.location.assign(trigger.href);
            return;
        }

        if ('form' in trigger && trigger.form instanceof HTMLFormElement) {
            trigger.form.requestSubmit(trigger);
        }
    }, true);
})();
