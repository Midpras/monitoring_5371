<script>
    import { onMount } from 'svelte';

    export let csrf;

    let users = [];
    let currentUserId;
    let loading = true;
    let saving = false;
    let deletingId;
    let error = '';
    let formError = '';
    let editingId = null;
    let form = emptyForm();
    const date = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });

    function emptyForm() {
        return { name: '', email: '', password: '' };
    }

    function errorMessage(body, fallback) {
        return body.message || Object.values(body.errors || {}).flat().join(' ') || fallback;
    }

    async function responseBody(response) {
        const text = await response.text();
        try { return text ? JSON.parse(text) : {}; } catch { return { message: `Server mengembalikan HTTP ${response.status}.` }; }
    }

    async function request(path, options = {}) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            return await fetch(path, { ...options, signal: controller.signal });
        } catch (exception) {
            if (exception.name === 'AbortError') throw new Error('Permintaan data melewati batas waktu.');
            throw exception;
        } finally {
            clearTimeout(timeout);
        }
    }

    async function loadUsers() {
        loading = true;
        error = '';
        try {
            const response = await request('/api/admin/users', { headers: { Accept: 'application/json' } });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Daftar pengguna tidak dapat dimuat.'));
            users = body.data || [];
            currentUserId = body.current_user_id;
        } catch (exception) {
            error = exception.message;
        } finally {
            loading = false;
        }
    }

    function startCreate() {
        editingId = null;
        form = emptyForm();
        formError = '';
    }

    function startEdit(user) {
        editingId = user.id;
        form = { name: user.name, email: user.email, password: '' };
        formError = '';
    }

    async function saveUser() {
        saving = true;
        formError = '';
        const editing = editingId !== null;
        const payload = { name: form.name.trim(), email: form.email.trim() };
        if (form.password) payload.password = form.password;
        try {
            const response = await request(editing ? `/api/admin/users/${editingId}` : '/api/admin/users', {
                method: editing ? 'PATCH' : 'POST',
                body: JSON.stringify(payload),
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Perubahan pengguna gagal disimpan.'));
            startCreate();
            await loadUsers();
        } catch (exception) {
            formError = exception.message;
        } finally {
            saving = false;
        }
    }

    async function deleteUser(user) {
        if (user.id === currentUserId) return;
        if (!window.confirm(`Hapus akun admin ${user.email}? Akun ini tidak dapat digunakan untuk masuk lagi.`)) return;
        deletingId = user.id;
        error = '';
        try {
            const response = await request(`/api/admin/users/${user.id}`, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            const body = await responseBody(response);
            if (!response.ok) throw new Error(errorMessage(body, 'Akun admin gagal dihapus.'));
            if (editingId === user.id) startCreate();
            await loadUsers();
        } catch (exception) {
            error = exception.message;
        } finally {
            deletingId = undefined;
        }
    }

    onMount(loadUsers);
</script>

<svelte:head><title>Kelola Pengguna - SE2026 Monitoring</title></svelte:head>

<main class="admin-shell">
    <header class="admin-header">
        <a class="brand admin-brand" href="/" aria-label="Kembali ke dashboard publik"><img class="brand-logo" src="/logo-se2026.png" alt=""><span class="brand-copy"><strong>SE2026</strong><small>Monitoring harian Kupang</small></span></a>
        <div class="admin-title"><p>ADMINISTRASI AKUN</p><h1>Kelola pengguna</h1><span class="public-badge">Area admin</span></div>
        <nav class="admin-links" aria-label="Navigasi admin"><a class="admin-nav-link" href="/admin" title="Upload snapshot"><span class="admin-nav-icon" aria-hidden="true">↑</span><span>Upload</span></a><a class="admin-nav-link" href="/" title="Lihat dashboard publik"><span class="admin-nav-icon" aria-hidden="true">↗</span><span>Dashboard</span></a><form method="post" action="/admin/logout"><input type="hidden" name="_token" value={csrf}><button class="quiet-button admin-logout" type="submit"><span aria-hidden="true">↪</span><span>Keluar</span></button></form></nav>
    </header>

    <section class="user-layout">
        <section class="upload-panel user-form-panel" aria-labelledby="user-form-title">
            <div class="panel-heading"><div><span>AKUN ADMIN</span><h2 id="user-form-title">{editingId === null ? 'Tambah pengguna' : 'Edit pengguna'}</h2><p>Semua akun di halaman ini memiliki akses admin.</p></div></div>
            <form class="admin-user-form" onsubmit={(event) => { event.preventDefault(); saveUser(); }}>
                <label>Nama<input type="text" bind:value={form.name} autocomplete="name" required maxlength="120"></label>
                <label>Email<input type="email" bind:value={form.email} autocomplete="email" required maxlength="255"></label>
                <label>Password<input type="password" bind:value={form.password} autocomplete="new-password" minlength="8" maxlength="255" required={editingId === null}><small>{editingId === null ? 'Minimal 8 karakter.' : 'Kosongkan jika tidak ingin mengganti password.'}</small></label>
                {#if formError}<p class="form-error" role="alert">{formError}</p>{/if}
                <div class="user-form-actions"><button class="accent-button" type="submit" disabled={saving}>{saving ? 'Menyimpan...' : editingId === null ? 'Tambah pengguna' : 'Simpan perubahan'}</button>{#if editingId !== null}<button class="quiet-button" type="button" disabled={saving} onclick={startCreate}>Batal</button>{/if}</div>
            </form>
        </section>

        <section class="upload-history user-list-panel" aria-labelledby="user-list-title">
            <div class="panel-heading"><div><span>DAFTAR AKUN</span><h2 id="user-list-title">Pengguna admin</h2></div><small>{users.length} akun</small></div>
            {#if error}<div class="table-state" role="alert"><strong>Daftar pengguna belum dapat dimuat.</strong><span>{error}</span><button class="quiet-button" type="button" onclick={loadUsers}>Coba lagi</button></div>{:else if loading}<p class="history-empty">Memuat pengguna...</p>{:else if users.length}<div class="table-scroll"><table class="user-table"><caption class="sr-only">Daftar pengguna admin</caption><thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>{#each users as user}<tr><td><strong>{user.name}</strong>{#if user.id === currentUserId}<small class="current-user-label">Akun Anda</small>{/if}</td><td>{user.email}</td><td><span class="status-value user-role">Admin</span></td><td>{date.format(new Date(user.created_at))}</td><td class="user-actions"><button class="quiet-button" type="button" disabled={saving || deletingId !== undefined} onclick={() => startEdit(user)}>Edit</button><button class="danger-button" type="button" disabled={user.id === currentUserId || saving || deletingId !== undefined} title={user.id === currentUserId ? 'Akun sendiri tidak dapat dihapus' : 'Hapus akun'} onclick={() => deleteUser(user)}>{deletingId === user.id ? 'Menghapus...' : 'Hapus'}</button></td></tr>{/each}</tbody></table></div>{:else}<div class="user-empty"><strong>Belum ada akun admin.</strong><span>Gunakan formulir untuk menambahkan akun pertama.</span></div>{/if}
        </section>
    </section>
</main>
