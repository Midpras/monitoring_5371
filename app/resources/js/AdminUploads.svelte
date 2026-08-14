<script>
    import { onMount } from 'svelte';

    export let csrf;

    let uploadHistory = [];
    let uploadError = '';
    let historyError = '';
    let uploadBusy = false;
    let historyBusy = true;
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

    async function loadHistory() {
        historyBusy = true;
        historyError = '';
        try {
            const response = await fetch('/api/admin/progress-uploads', { headers: { Accept: 'application/json' } });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Riwayat upload tidak dapat dimuat.'));
            uploadHistory = body.data || [];
        } catch (exception) {
            historyError = exception.message;
        } finally {
            historyBusy = false;
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
            const response = await fetch('/api/admin/progress-uploads/validate', { method: 'POST', body: form, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
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
            const response = await fetch(`/api/admin/progress-uploads/${validation.id}/confirm`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
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

    async function deleteUpload(item) {
        if (!window.confirm('Hapus snapshot ' + item.snapshot_date + ' versi ' + (item.version || '-') + '? Data SLS di dalamnya juga akan dihapus.')) return;
        uploadBusy = true;
        uploadError = '';
        try {
            const response = await fetch('/api/admin/progress-uploads/' + item.id, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
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

    onMount(loadHistory);
</script>

<svelte:head><title>Admin Upload - SE2026 Monitoring</title></svelte:head>

<main class="admin-shell">
    <header class="admin-header">
        <div><p>SE2026 KUPANG</p><h1>Upload snapshot</h1><span class="public-badge">Area admin</span></div>
        <nav class="admin-links" aria-label="Navigasi admin"><a href="/">Lihat dashboard publik</a><form method="post" action="/admin/logout"><input type="hidden" name="_token" value={csrf}><button class="quiet-button" type="submit">Keluar</button></form></nav>
    </header>

    <section class="upload-panel" aria-labelledby="upload-title">
        <div class="panel-heading"><div><span>UPLOAD DATA</span><h2 id="upload-title">Validasi sebelum impor</h2><p>Periksa workbook terlebih dahulu. Data baru tampil setelah impor dikonfirmasi.</p></div></div>
        <form class="upload-form" onsubmit={(event) => { event.preventDefault(); validateUpload(); }}>
            <label for="snapshot-date">Tanggal snapshot<input id="snapshot-date" type="date" bind:value={uploadDate} required></label>
            <label class="file-field" for="snapshot-file">File Excel<input id="snapshot-file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onchange={selectFile} required><span class:selected={selectedFile}>{selectedFile?.name || 'Pilih workbook .xlsx'}</span><small>Maksimal 10 MB</small></label>
            <button class="accent-button" type="submit" disabled={!selectedFile || uploadBusy}>{uploadBusy ? 'Memeriksa...' : 'Validasi file'}</button>
        </form>
        {#if uploadError}<p class="form-error" role="alert">{uploadError}</p>{/if}
        {#if validation}<div class="validation" class:validated={validation.status === 'validated'} class:already-imported={validation.already_imported}><div><strong>{validation.already_imported ? 'File ini sudah diimpor' : validation.status === 'validated' ? `${validation.row_count} baris siap diimpor` : `${validation.validation_error_count} masalah ditemukan`}</strong><p>{validation.filename} · {validation.snapshot_date}</p></div>{#if validation.warnings?.length}<ul class="warnings" aria-label="Peringatan validasi">{#each validation.warnings as warning}<li>{warning.message}</li>{/each}</ul>{/if}{#if validation.errors?.length}<ul class="errors" aria-label="Kesalahan validasi">{#each validation.errors as item}<li>{item.row ? `Baris ${item.row}: ` : ''}{item.message}</li>{/each}</ul>{/if}{#if validation.status === 'validated'}<button class="accent-button" type="button" disabled={uploadBusy} onclick={confirmUpload}>{uploadBusy ? 'Mengimpor...' : 'Konfirmasi impor'}</button>{/if}</div>{/if}
    </section>

    <section class="upload-history" aria-labelledby="history-title"><div class="panel-heading"><div><span>RIWAYAT</span><h2 id="history-title">Snapshot terbaru</h2></div><small>{uploadHistory.length} entri</small></div>{#if historyBusy}<p class="history-empty">Memuat riwayat...</p>{:else if historyError}<div class="table-state" role="alert"><strong>Riwayat belum dapat dimuat.</strong><span>{historyError}</span><button class="quiet-button" type="button" onclick={loadHistory}>Coba lagi</button></div>{:else if uploadHistory.length}<div class="table-scroll"><table><caption class="sr-only">Riwayat upload snapshot</caption><thead><tr><th>Tanggal</th><th>Versi</th><th>File</th><th>Status</th><th>Baris</th><th>Pengunggah</th><th>Aksi</th></tr></thead><tbody>{#each uploadHistory as item}<tr><td>{date.format(new Date(`${item.snapshot_date}T00:00:00`))}</td><td>v{item.version || '-'}</td><td>{item.filename}</td><td><span class="status-value status-{item.status}">{item.status}</span></td><td>{item.row_count}</td><td>{item.uploaded_by || '-'}</td><td><button class="danger-button" type="button" disabled={uploadBusy} onclick={() => deleteUpload(item)}>Hapus</button></td></tr>{/each}</tbody></table></div>{:else}<p class="history-empty">Belum ada riwayat upload.</p>{/if}</section>
</main>
