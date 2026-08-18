<script>
    import { onMount } from 'svelte';

    export let csrf;

    let uploadHistory = [];
    let historyTotal = 0;
    let uploadError = '';
    let historyError = '';
    let maintenanceError = '';
    let settingsError = '';
    let settingsBusy = false;
    let uploadBusy = false;
    let historyBusy = true;
    let settings = { target_date: '' };
    let selectedFile;
    let validation;
    const today = new Date();
    let uploadDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    const date = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });

    function errorMessage(body, fallback) {
        return body.message || Object.values(body.errors || {}).flat().join(' ') || fallback;
    }

    async function responseBody(response) {
        const text = await response.text();
        try { return text ? JSON.parse(text) : {}; } catch { return { message: `Server mengembalikan HTTP ${response.status}.` }; }
    }

    async function request(path, options = {}, timeoutMs = 15000) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), timeoutMs);
        try {
            return await fetch(path, { ...options, signal: controller.signal });
        } catch (exception) {
            if (exception.name === 'AbortError') throw new Error('Permintaan data melewati batas waktu.');
            throw exception;
        } finally {
            clearTimeout(timeout);
        }
    }

    async function loadHistory() {
        historyBusy = true;
        historyError = '';
        try {
            const response = await request('/api/admin/progress-uploads', { headers: { Accept: 'application/json' } });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Riwayat upload tidak dapat dimuat.'));
            uploadHistory = body.data || [];
            historyTotal = body.total ?? uploadHistory.length;
        } catch (exception) {
            historyError = exception.message;
        } finally {
            historyBusy = false;
        }
    }

    async function loadSettings() {
        settingsError = '';
        try {
            const response = await request('/api/admin/dashboard-settings', { headers: { Accept: 'application/json' } });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Pengaturan dashboard tidak dapat dimuat.'));
            settings = { target_date: body.target_date || '' };
        } catch (exception) {
            settingsError = exception.message;
        }
    }

    async function saveSettings() {
        settingsBusy = true;
        settingsError = '';
        try {
            const response = await request('/api/admin/dashboard-settings', { method: 'PATCH', body: JSON.stringify(settings), headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Pengaturan dashboard gagal disimpan.'));
            settings = { target_date: body.target_date || '' };
        } catch (exception) {
            settingsError = exception.message;
        } finally {
            settingsBusy = false;
        }
    }

    async function validateUpload() {
        if (!selectedFile) return;
        uploadBusy = true;
        uploadError = '';
        validation = undefined;
        const form = new FormData();
        form.set('snapshot_date', uploadDate);
        form.set('file', selectedFile);
        try {
            const response = await request('/api/admin/progress-uploads/validate', { method: 'POST', body: form, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } }, 150000);
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, `Validasi gagal (${response.status}).`));
            validation = body;
        } catch (exception) {
            uploadError = exception.message;
        } finally {
            uploadBusy = false;
        }
    }

    async function confirmUpload() {
        if (!validation || validation.status !== 'validated') return;
        uploadBusy = true;
        uploadError = '';
        try {
            const response = await request('/api/admin/progress-uploads/' + validation.id + '/confirm', { method: 'POST', body: JSON.stringify({ confirm_warnings: Boolean(validation.warnings?.length) }), headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } }, 150000);
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Impor gagal.'));
            validation = body;
            selectedFile = undefined;
            await loadHistory();
        } catch (exception) {
            uploadError = exception.message;
        } finally {
            uploadBusy = false;
        }
    }

    async function purgeHistory() {
        maintenanceError = '';
        try {
            const response = await request('/api/admin/progress-uploads/purge', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Pembersihan riwayat gagal.'));
            if (body.deleted) await loadHistory();
        } catch (exception) {
            maintenanceError = exception.message;
        }
    }

    async function deleteUpload(item) {
        if (!window.confirm('Hapus snapshot ' + item.snapshot_date + ' versi ' + (item.version || '-') + '? Data SLS di dalamnya juga akan dihapus.')) return;
        uploadBusy = true;
        uploadError = '';
        try {
            const response = await request('/api/admin/progress-uploads/' + item.id, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Snapshot gagal dihapus.'));
            if (validation?.id === item.id) {
                validation = undefined;
                selectedFile = undefined;
            }
            await loadHistory();
        } catch (exception) {
            uploadError = exception.message;
        } finally {
            uploadBusy = false;
        }
    }

    function selectFile(event) {
        selectedFile = event.currentTarget.files?.[0];
        uploadError = '';
        validation = undefined;
    }

    function validationStatus(item) {
        if (item.already_imported) return 'Sudah diimpor';
        return { validated: 'Siap diimpor', imported: 'Berhasil diimpor', invalid: 'Ditolak', importing: 'Sedang diimpor' }[item.status] || item.status || '-';
    }

    onMount(async () => {
        await Promise.all([loadHistory(), loadSettings()]);
        setTimeout(purgeHistory, 0);
    });
</script>

<svelte:head><title>Admin Upload - SE2026 Monitoring</title></svelte:head>

<main class="admin-shell">
    <header class="admin-header">
        <a class="brand admin-brand" href="/" aria-label="Kembali ke dashboard publik"><img class="brand-logo" src="/logo-se2026.png" alt=""><span class="brand-copy"><strong>SE2026</strong><small>Monitoring harian Kupang</small></span></a>
        <div class="admin-title"><p>ADMINISTRASI DATA</p><h1>Upload snapshot</h1><span class="public-badge">Area admin</span></div>
        <nav class="admin-links" aria-label="Navigasi admin"><a class="admin-nav-link" href="/admin/users" title="Kelola pengguna"><span class="admin-nav-icon" aria-hidden="true">☷</span><span>Pengguna</span></a><a class="admin-nav-link" href="/" title="Lihat dashboard publik"><span class="admin-nav-icon" aria-hidden="true">↗</span><span>Dashboard</span></a><form method="post" action="/admin/logout"><input type="hidden" name="_token" value={csrf}><button class="quiet-button admin-logout" type="submit"><span aria-hidden="true">↪</span><span>Keluar</span></button></form></nav>
    </header>

    <section class="upload-panel" aria-labelledby="upload-title">
        <div class="panel-heading"><div><span>UPLOAD DATA</span><h2 id="upload-title">Validasi sebelum impor</h2><p>Periksa workbook terlebih dahulu. Data baru tampil setelah impor dikonfirmasi.</p></div></div>
        <div class="dashboard-setting">
            <div><strong>Tenggat capaian</strong><p>Dipakai untuk menghitung kebutuhan harian PPL dan PML.</p></div>
            <form class="settings-form" onsubmit={(event) => { event.preventDefault(); saveSettings(); }}>
                <label for="target-date">Tanggal tenggat<input id="target-date" type="date" bind:value={settings.target_date}></label>
                <button class="quiet-button" type="submit" disabled={settingsBusy}>{settingsBusy ? 'Menyimpan...' : 'Simpan tenggat'}</button>
            </form>
        </div>
        {#if settingsError}<p class="form-error" role="alert">{settingsError}</p>{/if}
        <form class="upload-form" onsubmit={(event) => { event.preventDefault(); validateUpload(); }}>
            <label class="snapshot-field" for="snapshot-date">Tanggal snapshot<input id="snapshot-date" type="date" bind:value={uploadDate} required></label>
            <label class="file-field" for="snapshot-file">File Excel<input id="snapshot-file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onchange={selectFile} required><span class:selected={selectedFile}>{selectedFile?.name || 'Pilih workbook .xlsx'}</span><small>Maksimal 10 MB</small></label>
            <button class="accent-button upload-submit" type="submit" disabled={!selectedFile || uploadBusy}>{uploadBusy ? 'Memeriksa...' : 'Validasi file'}</button>
        </form>
        {#if uploadError}<p class="form-error" role="alert">{uploadError}</p>{/if}
        {#if validation}
            <div class="validation" class:validated={validation.status === 'validated'} class:already-imported={validation.already_imported}>
                <div class="validation-overview">
                    <div class="validation-heading">
                        <div>
                            <span class="validation-kicker">HASIL VALIDASI</span>
                            <strong>{validation.already_imported ? 'File ini sudah diimpor' : validation.status === 'validated' ? `${validation.row_count} baris siap diimpor` : validation.status === 'imported' ? 'Snapshot berhasil diimpor' : `${validation.validation_error_count} masalah ditemukan`}</strong>
                        </div>
                        {#if validation.status === 'validated'}<button class="accent-button" type="button" disabled={uploadBusy} onclick={confirmUpload}>{uploadBusy ? 'Mengimpor...' : validation.warnings?.length ? 'Konfirmasi dengan peringatan' : 'Konfirmasi impor'}</button>{/if}
                    </div>
                    <div class="table-scroll validation-meta-scroll">
                        <table class="validation-meta-table">
                            <caption class="sr-only">Ringkasan hasil validasi</caption>
                            <tbody>
                                <tr><th scope="row">File</th><td>{validation.filename || '-'}</td><th scope="row">Tanggal snapshot</th><td>{validation.snapshot_date || '-'}</td></tr>
                                <tr><th scope="row">Status</th><td><span class="status-value status-{validation.status}">{validationStatus(validation)}</span></td><th scope="row">Baris terbaca</th><td>{validation.row_count ?? 0}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                {#if validation.warnings?.length || validation.errors?.length}
                    <div class="validation-log">
                        <div class="validation-log-heading"><div><span class="validation-kicker">LOG DETAIL</span><strong>{(validation.warnings?.length || 0) + (validation.errors?.length || 0)} temuan perlu diperhatikan</strong></div><small>Pahami detail sebelum melanjutkan impor.</small></div>
                        <div class="table-scroll validation-detail-scroll">
                            <table class="validation-detail-table">
                                <caption class="sr-only">Detail peringatan dan kesalahan validasi</caption>
                                <thead><tr><th scope="col">Jenis</th><th scope="col">Baris</th><th scope="col">Detail</th></tr></thead>
                                <tbody>
                                    {#each validation.warnings || [] as warning}<tr><td><span class="log-badge log-warning">Peringatan</span></td><td>{warning.row ?? '-'}</td><td>{warning.message}</td></tr>{/each}
                                    {#each validation.errors || [] as item}<tr><td><span class="log-badge log-error">Ditolak</span></td><td>{item.row ?? '-'}</td><td>{item.message}</td></tr>{/each}
                                </tbody>
                            </table>
                        </div>
                    </div>
                {/if}
            </div>
        {/if}
    </section>

    <section class="upload-history" aria-labelledby="history-title"><div class="panel-heading"><div><span>RIWAYAT</span><h2 id="history-title">Snapshot terbaru</h2></div><small>{uploadHistory.length} terbaru dari {historyTotal} entri</small></div>{#if maintenanceError}<p class="form-error" role="alert">{maintenanceError}</p>{/if}{#if historyBusy}<p class="history-empty">Memuat riwayat...</p>{:else if historyError}<div class="table-state" role="alert"><strong>Riwayat belum dapat dimuat.</strong><span>{historyError}</span><button class="quiet-button" type="button" onclick={loadHistory}>Coba lagi</button></div>{:else if uploadHistory.length}<div class="table-scroll"><table><caption class="sr-only">Riwayat upload snapshot</caption><thead><tr><th>Tanggal</th><th>Versi</th><th>File</th><th>Status</th><th>Baris</th><th>Pengunggah</th><th>Aksi</th></tr></thead><tbody>{#each uploadHistory as item}<tr><td>{date.format(new Date(`${item.snapshot_date}T00:00:00`))}</td><td>v{item.version || '-'}</td><td>{item.filename}</td><td><span class="status-value status-{item.status}">{item.status}</span></td><td>{item.row_count}</td><td>{item.uploaded_by || '-'}</td><td><button class="danger-button" type="button" disabled={uploadBusy} onclick={() => deleteUpload(item)}>Hapus</button></td></tr>{/each}</tbody></table></div>{:else}<p class="history-empty">Belum ada riwayat upload.</p>{/if}</section>
</main>
