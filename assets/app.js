/**
 * MONWEB By AP - ALBIANDRA PROJECT
 * Frontend Controller (Interactive Tabs, Git Execution, Modal Keyboard Trap, 08:00 AM Auto-Scheduler)
 */

(function () {
    'use strict';

    const banner = document.getElementById('global-status-banner');
    const bannerText = document.getElementById('global-status-text');
    const btnCloseBanner = document.getElementById('btn-close-banner');
    const gitConsole = document.getElementById('git-console-box');

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

    const btnRegisterSelf = document.getElementById('btn-register-self');
    if (btnRegisterSelf) {
        btnRegisterSelf.addEventListener('click', async function () {
            btnRegisterSelf.disabled = true;
            showBanner('Mendaftarkan folder workspace MONWEB By AP untuk pengujian langsung...', '');
            try {
                await postApi({
                    action: 'add_site',
                    name: 'MONWEB By AP (baznassumbawa.id)',
                    url: window.location.origin,
                    stack_type: 'PHP Native',
                    hosting_panel: 'hPanel Niagahoster',
                    conn_mode: 'local',
                    repo_path: (window.MONWEB_CONFIG && window.MONWEB_CONFIG.selfPath) ? window.MONWEB_CONFIG.selfPath : '',
                    git_branch: 'main'
                });
                window.location.reload();
            } catch (err) {
                showBanner(err.message, 'error');
                btnRegisterSelf.disabled = false;
            }
        });
    }

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

    const formWa = document.getElementById('form-wa-settings');
    if (formWa) {
        formWa.addEventListener('submit', async function (e) {
            e.preventDefault();
            const fd = new FormData(formWa);
            const payload = {};
            fd.forEach(function (v, k) { payload[k] = v; });

            showBanner('Menyimpan pengaturan jadwal WhatsApp 08:00...', '');
            try {
                const res = await postApi(payload);
                showBanner(res.message, 'success');
            } catch (err) {
                showBanner(err.message, 'error');
            }
        });
    }

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

