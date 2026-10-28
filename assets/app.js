/**
 * MONWEB By AP - ALBIANDRA PROJECT
 * Frontend Controller v2.0 (Auto-Discovery, Mass Git Pull, Remote Terminal Runner, Cache Cleaner, PIN Lock, 08:00 AM Scheduler)
 */

(function () {
    'use strict';

    const banner = document.getElementById('global-status-banner');
    const bannerText = document.getElementById('global-status-text');
    const btnCloseBanner = document.getElementById('btn-close-banner');
    const gitConsole = document.getElementById('git-console-box');
    const autoConsole = document.getElementById('automation-console-box');

    function showBanner(msg, type) {
        if (!banner || !bannerText) return;
        banner.hidden = false;
        banner.className = 'status-banner' + (type ? ' is-' + type : '');
        bannerText.textContent = msg;
    }

    if (btnCloseBanner) {
        btnCloseBanner.addEventListener('click', function () {
            banner.hidden = true;
        });
    }

    const htmlEl = document.documentElement;
    const btnTheme = document.getElementById('btn-theme-toggle');
    const themeLabel = document.getElementById('theme-label');

    function applyTheme(theme) {
        htmlEl.setAttribute('data-theme', theme);
        localStorage.setItem('monweb_theme', theme);
        if (themeLabel) {
            themeLabel.textContent = theme === 'dark' ? 'Mode Terang' : 'Mode Gelap';
        }
    }

    const savedTheme = localStorage.getItem('monweb_theme') || 'dark';
    applyTheme(savedTheme);

    if (btnTheme) {
        btnTheme.addEventListener('click', function () {
            const current = htmlEl.getAttribute('data-theme') || 'dark';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    }

    const tabButtons = Array.from(document.querySelectorAll('.nav-tab[role="tab"]'));
    const tabPanels = {
        overview: document.getElementById('panel-overview'),
        automation: document.getElementById('panel-automation'),
        errors: document.getElementById('panel-errors'),
        traffic: document.getElementById('panel-traffic'),
        schedule: document.getElementById('panel-schedule')
    };

    function activateTab(tabName, pushUrl) {
        tabButtons.forEach(function (btn) {
            const isTarget = btn.getAttribute('data-tab') === tabName;
            btn.setAttribute('aria-selected', isTarget ? 'true' : 'false');
        });
        Object.keys(tabPanels).forEach(function (key) {
            if (tabPanels[key]) {
                tabPanels[key].hidden = (key !== tabName);
            }
        });
        if (pushUrl && window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url.toString());
        }
    }

    tabButtons.forEach(function (btn, idx) {
        btn.addEventListener('click', function () {
            activateTab(btn.getAttribute('data-tab'), true);
        });
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                const dir = e.key === 'ArrowRight' ? 1 : -1;
                const nextIdx = (idx + dir + tabButtons.length) % tabButtons.length;
                tabButtons[nextIdx].focus();
                activateTab(tabButtons[nextIdx].getAttribute('data-tab'), true);
            }
        });
    });

    async function postApi(payload) {
        const resp = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (!resp.ok || !data.ok) {
            throw new Error(data.error || 'Terjadi kesalahan komunikasi dengan server.');
        }
        return data;
    }

    // PIN Unlock & Lock
    const formUnlockPin = document.getElementById('form-unlock-pin');
    if (formUnlockPin) {
        formUnlockPin.addEventListener('submit', async function (e) {
            e.preventDefault();
            const pinInput = document.getElementById('unlock_pin_input');
            try {
                await postApi({ action: 'verify_pin', pin_code: pinInput ? pinInput.value : '' });
                window.location.reload();
            } catch (err) {
                showBanner(err.message, 'error');
            }
        });
    }

    const btnLockConsole = document.getElementById('btn-lock-console');
    if (btnLockConsole) {
        btnLockConsole.addEventListener('click', async function () {
            await postApi({ action: 'lock_session' });
            window.location.reload();
        });
    }

    // Modal Add Website
    const modalAdd = document.getElementById('modal-add-site');
    const btnOpenModal = document.getElementById('btn-open-add-modal');
    const btnEmptyAdd = document.getElementById('btn-empty-add-site');
    const btnCloseModal = document.getElementById('btn-close-modal');
    const btnCancelModal = document.getElementById('btn-cancel-modal');
    const connModeSelect = document.getElementById('conn_mode');
    const fieldsAgent = document.getElementById('conn-fields-agent');
    const fieldsSsh = document.getElementById('conn-fields-ssh');

    function openAddModal() {
        if (!modalAdd) return;
        modalAdd.hidden = false;
        const firstInput = document.getElementById('site_name');
        if (firstInput) firstInput.focus();
    }

    function closeAddModal() {
        if (!modalAdd) return;
        modalAdd.hidden = true;
        if (btnOpenModal) btnOpenModal.focus();
    }

    if (btnOpenModal) btnOpenModal.addEventListener('click', openAddModal);
    if (btnEmptyAdd) btnEmptyAdd.addEventListener('click', openAddModal);
    if (btnCloseModal) btnCloseModal.addEventListener('click', closeAddModal);
    if (btnCancelModal) btnCancelModal.addEventListener('click', closeAddModal);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalAdd && !modalAdd.hidden) {
            closeAddModal();
        }
    });

    if (connModeSelect) {
        connModeSelect.addEventListener('change', function () {
            const val = connModeSelect.value;
            if (fieldsAgent) fieldsAgent.hidden = (val !== 'agent');
            if (fieldsSsh) fieldsSsh.hidden = (val !== 'ssh');
        });
    }

    const formAddSite = document.getElementById('form-add-site');
    if (formAddSite) {
        formAddSite.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitBtn = document.getElementById('btn-submit-site');
            if (submitBtn) submitBtn.disabled = true;
            showBanner('Menyimpan dan memeriksa website...', '');

            const fd = new FormData(formAddSite);
            const payload = {};
            fd.forEach(function (v, k) { payload[k] = v; });

            try {
                await postApi(payload);
                showBanner('Website berhasil ditambahkan. Memuat ulang data...', 'success');
                window.location.reload();
            } catch (err) {
                showBanner(err.message, 'error');
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // Auto-Discover Local Hosting Directories
    async function triggerAutoDiscover(btn) {
        if (btn) btn.disabled = true;
        showBanner('Memindai seluruh folder proyek dan subdomain di dalam public_html hosting Anda...', '');
        try {
            const res = await postApi({ action: 'auto_discover' });
            const msg = res.count > 0
                ? 'Berhasil menemukan dan mendaftarkan ' + res.count + ' website: ' + res.added.join(', ')
                : 'Pemindaian selesai di ' + res.scanned_in + '. Semua website sudah terdaftar.';
            showBanner(msg, 'success');
            setTimeout(function () { window.location.reload(); }, 900);
        } catch (err) {
            showBanner(err.message, 'error');
            if (btn) btn.disabled = false;
        }
    }

    const btnAutoDiscover = document.getElementById('btn-auto-discover');
    const btnEmptyDiscover = document.getElementById('btn-empty-discover');
    if (btnAutoDiscover) {
        btnAutoDiscover.addEventListener('click', function () { triggerAutoDiscover(btnAutoDiscover); });
    }
    if (btnEmptyDiscover) {
        btnEmptyDiscover.addEventListener('click', function () { triggerAutoDiscover(btnEmptyDiscover); });
    }

    // Mass Git Pull All Sites
    const btnMassGitPull = document.getElementById('btn-mass-git-pull');
    if (btnMassGitPull) {
        btnMassGitPull.addEventListener('click', async function () {
            btnMassGitPull.disabled = true;
            showBanner('Menjalankan Git Pull secara serentak pada seluruh website yang terdaftar...', '');
            if (gitConsole) {
                gitConsole.textContent = 'Menjalankan Mass Git Pull pada seluruh website...';
            }
            try {
                const res = await postApi({ action: 'mass_git_pull' });
                if (gitConsole) {
                    gitConsole.textContent = '[' + res.timestamp + '] MASS GIT PULL (' + res.succeeded + '/' + res.total + ' Sukses)\n\n' + res.output;
                }
                showBanner('Git Pull massal selesai (' + res.succeeded + ' dari ' + res.total + ' website sukses).', 'success');
            } catch (err) {
                showBanner(err.message, 'error');
            } finally {
                btnMassGitPull.disabled = false;
            }
        });
    }

    // Sync All Sites
    const btnSyncAll = document.getElementById('btn-sync-all');
    if (btnSyncAll) {
        btnSyncAll.addEventListener('click', async function () {
            btnSyncAll.disabled = true;
            showBanner('Menyinkronkan status HTTP, kapasitas disk, Git commit, dan error_log semua website...', '');
            try {
                await postApi({ action: 'sync_all' });
                showBanner('Sinkronisasi selesai. Memperbarui tampilan...', 'success');
                window.location.reload();
            } catch (err) {
                showBanner(err.message, 'error');
                btnSyncAll.disabled = false;
            }
        });
    }

    // Per-Site Git Pull / Status / Clean Cache
    document.querySelectorAll('.js-git-action').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const id = parseInt(btn.getAttribute('data-id'), 10);
            const gitType = btn.getAttribute('data-git');
            const name = btn.getAttribute('data-name');
            btn.disabled = true;

            if (gitConsole) {
                gitConsole.textContent = '[' + new Date().toLocaleTimeString('id-ID') + '] Menjalankan ' + gitType + ' pada ' + name + '...';
            }
            showBanner('Menjalankan ' + gitType + ' pada ' + name + '...', '');

            try {
                const res = await postApi({
                    action: 'git_action',
                    id: id,
                    git_type: gitType
                });
                if (gitConsole) {
                    gitConsole.textContent = '[' + res.timestamp + '] ' + name + '\n$ ' + res.command + '\n\n' + res.output;
                }
                showBanner('Perintah ' + res.command + ' selesai dijalankan pada ' + name + '.', 'success');
            } catch (err) {
                if (gitConsole) {
                    gitConsole.textContent = '[ERROR] ' + err.message;
                }
                showBanner(err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        });
    });

    // Remote Command Runner Tab (Presets & Custom Shell)
    const runnerSiteSelect = document.getElementById('runner_site_id');
    const formRemoteRunner = document.getElementById('form-remote-runner');
    const runnerCustomInput = document.getElementById('runner_custom_cmd');

    async function runAutomationAction(gitType, customCmd) {
        if (!runnerSiteSelect) return;
        const siteId = parseInt(runnerSiteSelect.value, 10);
        if (!siteId) return;

        if (autoConsole) {
            autoConsole.textContent = '[' + new Date().toLocaleTimeString('id-ID') + '] Mengeksekusi perintah pada website ID #' + siteId + '...';
        }
        showBanner('Mengeksekusi perintah otomasi...', '');

        try {
            const res = await postApi({
                action: 'git_action',
                id: siteId,
                git_type: gitType,
                custom_cmd: customCmd || ''
            });
            if (autoConsole) {
                autoConsole.textContent = '[' + res.timestamp + '] $ ' + res.command + '\n\n' + res.output;
            }
            showBanner('Eksekusi selesai: ' + res.command, 'success');
        } catch (err) {
            if (autoConsole) {
                autoConsole.textContent = '[ERROR] ' + err.message;
            }
            showBanner(err.message, 'error');
        }
    }

    document.querySelectorAll('.js-preset-action').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const gitType = btn.getAttribute('data-git');
            if (gitType === 'git_reset' && !window.confirm('Git Force Reset akan menimpa perubahan file lokal yang belum di-commit agar sama persis dengan GitHub. Lanjutkan?')) {
                return;
            }
            runAutomationAction(gitType, '');
        });
    });

    if (formRemoteRunner) {
        formRemoteRunner.addEventListener('submit', function (e) {
            e.preventDefault();
            const cmd = runnerCustomInput ? runnerCustomInput.value.trim() : '';
            if (!cmd) {
                showBanner('Masukkan perintah terminal yang ingin dijalankan.', 'error');
                return;
            }
            runAutomationAction('custom_cmd', cmd);
        });
    }

    // Per-Site Sync
    document.querySelectorAll('.js-sync-site').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const id = parseInt(btn.getAttribute('data-id'), 10);
            const name = btn.getAttribute('data-name');
            btn.disabled = true;
            showBanner('Memeriksa ulang status, disk, dan log untuk ' + name + '...', '');
            try {
                await postApi({ action: 'sync_site', id: id });
                window.location.reload();
            } catch (err) {
                showBanner(err.message, 'error');
                btn.disabled = false;
            }
        });
    });

    // Per-Site Delete
    document.querySelectorAll('.js-delete-site').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const id = parseInt(btn.getAttribute('data-id'), 10);
            const name = btn.getAttribute('data-name');
            if (!window.confirm('Hapus "' + name + '" dari daftar pemantauan MONWEB By AP?')) {
                return;
            }
            btn.disabled = true;
            try {
                await postApi({ action: 'delete_site', id: id });
                window.location.reload();
            } catch (err) {
                showBanner(err.message, 'error');
                btn.disabled = false;
            }
        });
    });

    // Clear Filtered Error Logs
    const btnClearLogs = document.getElementById('btn-clear-filtered-logs');
    if (btnClearLogs) {
        btnClearLogs.addEventListener('click', async function () {
            const id = parseInt(btnClearLogs.getAttribute('data-id'), 10);
            btnClearLogs.disabled = true;
            showBanner('Membersihkan file error_log...', '');
            try {
                const res = await postApi({ action: 'clear_logs', id: id });
                showBanner(res.message || 'Log berhasil dibersihkan.', 'success');
                window.location.reload();
            } catch (err) {
                showBanner(err.message, 'error');
                btnClearLogs.disabled = false;
            }
        });
    }

    // Save Settings
    const formWa = document.getElementById('form-wa-settings');
    if (formWa) {
        formWa.addEventListener('submit', async function (e) {
            e.preventDefault();
            const fd = new FormData(formWa);
            const payload = {};
            fd.forEach(function (v, k) { payload[k] = v; });

            showBanner('Menyimpan pengaturan jadwal WhatsApp 08:00 & Keamanan...', '');
            try {
                const res = await postApi(payload);
                showBanner(res.message, 'success');
            } catch (err) {
                showBanner(err.message, 'error');
            }
        });
    }

    // Trigger WhatsApp Report Now
    const btnTriggerWa = document.getElementById('btn-trigger-wa-now');
    const waPreviewBox = document.getElementById('wa-report-preview');
    const linkWaDirect = document.getElementById('link-wa-direct');

    async function executeDailyWhatsAppReport(openWindow) {
        showBanner('Menyinkronkan seluruh website dan menyiapkan laporan WhatsApp ke 081936955747...', '');
        try {
            const res = await postApi({ action: 'trigger_wa_report' });
            if (waPreviewBox && res.report_text) {
                waPreviewBox.textContent = res.report_text;
            }
            if (linkWaDirect && res.wa_direct_url) {
                linkWaDirect.href = res.wa_direct_url;
            }
            const msg = res.webhook_sent
                ? 'Laporan harian berhasil dikirim otomatis via Webhook ke ' + res.recipient
                : 'Laporan harian siap dikirim ke WhatsApp ' + res.recipient + '. Membuka WhatsApp...';
            showBanner(msg, 'success');

            if (openWindow && !res.webhook_sent && res.wa_direct_url) {
                window.open(res.wa_direct_url, '_blank', 'noopener');
            }
        } catch (err) {
            showBanner(err.message, 'error');
        }
    }

    if (btnTriggerWa) {
        btnTriggerWa.addEventListener('click', function () {
            executeDailyWhatsAppReport(true);
        });
    }

    setInterval(function () {
        if (!window.MONWEB_CONFIG || !window.MONWEB_CONFIG.autoTrigger) return;
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        const currentHm = hh + ':' + mm;
        const todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');

        const lastSentKey = 'monweb_wa_sent_' + todayStr;
        if (currentHm === window.MONWEB_CONFIG.scheduleTime && !localStorage.getItem(lastSentKey)) {
            localStorage.setItem(lastSentKey, '1');
            executeDailyWhatsAppReport(true);
        }
    }, 25000);
})();
