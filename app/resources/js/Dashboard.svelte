<script>
    import { onMount } from 'svelte';

    const initial = new URLSearchParams(window.location.search);
    let filters = { date: initial.get('date') || '', pml: initial.get('pml') || '', ppl: initial.get('ppl') || '', status: initial.get('status') || '', jenis_mitra: initial.get('jenis_mitra') || '', search: initial.get('search') || '' };
    let options = { dates: [], pml: [], ppl: [], productivity_statuses: [], jenis_mitra: [] };
    let summary;
    let series = [];
    let ppl = { data: [], meta: {} };
    let pml = { data: [], meta: {} };
    let loading = true;
    let error = '';
    let tableErrors = { ppl: '', pml: '' };
    let breakdowns = { ppl: {}, pml: {} };
    let pplPage = 1;
    let pmlPage = 1;
    let pplSort = { sort: 'ppl', direction: 'desc' };
    let pmlSort = { sort: 'ppl', direction: 'desc' };

    const number = new Intl.NumberFormat('id-ID');
    const compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
    const date = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    const timestamp = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    const apiParams = (extra = {}) => {
        const params = new URLSearchParams();
        Object.entries({ ...filters, ...extra }).forEach(([key, value]) => value !== '' && value != null && params.set(key, value));
        return params.toString();
    };

    async function get(path) {
        const response = await fetch(path, { headers: { Accept: 'application/json' } });
        const raw = await response.text();
        let body = {};
        try { body = raw ? JSON.parse(raw) : {}; } catch { body = {}; }
        if (!response.ok) throw new Error(body.message || `Permintaan gagal (${response.status}).`);
        return body;
    }

    async function load() {
        loading = true;
        error = '';
        try {
            const params = apiParams();
            const result = await Promise.allSettled([
                get(`/api/dashboard/summary?${params}`),
                get(`/api/dashboard/timeseries?${params}`),
                get(`/api/dashboard/ppl?${apiParams({ ...pplSort, page: pplPage })}`),
                get(`/api/dashboard/pml?${apiParams({ ...pmlSort, page: pmlPage })}`),
                get(`/api/dashboard/filters?${params}`),
            ]);
            if (result[0].status === 'rejected') throw result[0].reason;
            if (result[1].status === 'rejected') throw result[1].reason;
            if (result[4].status === 'rejected') throw result[4].reason;
            summary = result[0].value;
            ({ data: series } = result[1].value);
            options = result[4].value;
            tableErrors = {
                ppl: result[2].status === 'rejected' ? result[2].reason.message : '',
                pml: result[3].status === 'rejected' ? result[3].reason.message : '',
            };
            if (result[2].status === 'fulfilled') ppl = result[2].value;
            if (result[3].status === 'fulfilled') pml = result[3].value;
            if (!filters.date) {
                filters = { ...filters, date: summary.snapshot.date };
                writeUrl();
            }
        } catch (exception) {
            error = exception.message || 'Data belum dapat dimuat.';
        } finally {
            loading = false;
        }
    }

    async function retryTable(table) {
        const state = table === 'ppl' ? pplSort : pmlSort;
        const pageNumber = table === 'ppl' ? pplPage : pmlPage;
        tableErrors = { ...tableErrors, [table]: '' };
        try {
            const data = await get(`/api/dashboard/${table}?${apiParams({ ...state, page: pageNumber })}`);
            if (table === 'ppl') ppl = data;
            else pml = data;
        } catch (exception) {
            tableErrors = { ...tableErrors, [table]: exception.message };
        }
    }

    function writeUrl() {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => value && params.set(key, value));
        history.replaceState({}, '', `${window.location.pathname}${params.size ? `?${params}` : ''}`);
    }

    function refreshFilters() {
        pplPage = 1;
        pmlPage = 1;
        breakdowns = { ppl: {}, pml: {} };
        writeUrl();
        load();
    }

    function changeFilter(event) {
        filters = { ...filters, [event.currentTarget.name]: event.currentTarget.value };
        refreshFilters();
    }

    function clearFilters() {
        filters = { date: filters.date, pml: '', ppl: '', status: '', jenis_mitra: '', search: '' };
        refreshFilters();
    }

    function sort(table, key) {
        const state = table === 'ppl' ? pplSort : pmlSort;
        const next = { sort: key, direction: state.sort === key && state.direction === 'desc' ? 'asc' : 'desc' };
        if (table === 'ppl') { pplSort = next; pplPage = 1; }
        else { pmlSort = next; pmlPage = 1; }
        breakdowns = { ppl: {}, pml: {} };
        load();
    }

    function page(table, pageNumber) {
        if (table === 'ppl') pplPage = Math.max(1, pageNumber);
        else pmlPage = Math.max(1, pageNumber);
        breakdowns = { ppl: {}, pml: {} };
        load();
    }

    function value(metric) { return metric == null ? '-' : number.format(metric); }
    function percent(metric) { return metric == null ? '-' : `${metric.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`; }
    function signed(metric) { return metric == null ? 'Baseline' : `${metric > 0 ? '+' : ''}${number.format(metric)}`; }
    function displayDate(value) { return date.format(new Date(`${value}T00:00:00`)); }
    function displayTimestamp(value) { return value ? timestamp.format(new Date(value)) : 'Belum diimpor'; }
    function chartPath(key) {
        if (!series.length) return '';
        const ceiling = Math.max(...series.map(point => point[key] ?? 0), 1);
        return series.map((point, index) => `${index ? 'L' : 'M'} ${(index / Math.max(series.length - 1, 1)) * 100} ${40 - ((point[key] ?? 0) / ceiling) * 36}`).join(' ');
    }
    function chartMax(key) { return Math.max(...series.map(point => Math.abs(point[key] ?? 0)), 1); }
    function sortIndicator(table, key) {
        const state = table === 'ppl' ? pplSort : pmlSort;
        return state.sort === key ? (state.direction === 'asc' ? '↑' : '↓') : '';
    }
    function identity(row, type) { return type === 'pml' && row.id === '__unassigned' ? 'Email PML tidak tersedia' : row.email || 'Email tidak tersedia'; }
    function breakdownState(type, worker) { return breakdowns[type][worker] || { open: false, loading: false, data: [], meta: {}, error: '' }; }
    function updateBreakdown(type, worker, changes) {
        breakdowns = { ...breakdowns, [type]: { ...breakdowns[type], [worker]: { ...breakdownState(type, worker), ...changes } } };
    }
    async function loadBreakdown(type, row) {
        updateBreakdown(type, row.id, { open: true, loading: true, error: '' });
        try {
            const result = await get('/api/dashboard/breakdown?' + apiParams({ type, worker: row.id }));
            updateBreakdown(type, row.id, { loading: false, data: result.data || [], meta: result.meta || {} });
        } catch (exception) {
            updateBreakdown(type, row.id, { loading: false, error: exception.message || 'Breakdown belum dapat dimuat.' });
        }
    }
    function toggleBreakdown(type, row) {
        const state = breakdownState(type, row.id);
        if (state.open) return updateBreakdown(type, row.id, { open: false });
        if (state.data.length) return updateBreakdown(type, row.id, { open: true });
        loadBreakdown(type, row);
    }

    onMount(load);
</script>

<svelte:head><title>Monitoring Harian SE2026 Kupang</title></svelte:head>

<main class="dashboard-shell">
    <header class="public-header">
        <a class="brand" href="/" aria-label="Monitoring Harian SE2026 Kupang"><span class="brand-mark">SE</span><span class="brand-copy"><strong>SE2026</strong><small>Monitoring harian Kupang</small></span></a>
        <div class="header-title"><p>PEMANTAUAN LAPANGAN</p><h1>Capaian Sensus Ekonomi 2026</h1><span class="public-badge">Data publik</span></div>
        {#if summary}<div class="snapshot-meta"><span>Snapshot aktif</span><strong>{displayDate(summary.snapshot.date)} · v{summary.snapshot.version}</strong><small>Diperbarui {displayTimestamp(summary.snapshot.imported_at)}</small></div>{/if}
    </header>

    <section class="filter-panel" aria-label="Filter dashboard">
        <div class="filter-caption"><strong>Jelajahi data</strong><span>Filter berlaku untuk ringkasan dan tabel.</span></div>
        <form class="filters" onsubmit={(event) => { event.preventDefault(); refreshFilters(); }}>
            <label>Tanggal<select name="date" value={filters.date} onchange={changeFilter}><option value="">Snapshot terbaru</option>{#each options.dates as item}<option value={item}>{displayDate(item)}</option>{/each}</select></label>
            <label>Email PML<select name="pml" value={filters.pml} onchange={changeFilter}><option value="">Semua email PML</option>{#each options.pml as item}<option value={item.value}>{item.value}</option>{/each}</select></label>
            <label>Email PPL<select name="ppl" value={filters.ppl} onchange={changeFilter}><option value="">Semua email PPL</option>{#each options.ppl as item}<option value={item.value}>{item.value}</option>{/each}</select></label>
            <label>Produktivitas<select name="status" value={filters.status} onchange={changeFilter}><option value="">Semua status</option>{#each options.productivity_statuses as item}<option value={item}>{item}</option>{/each}</select></label>
            <label>Jenis mitra<select name="jenis_mitra" value={filters.jenis_mitra} onchange={changeFilter}><option value="">Semua jenis</option>{#each options.jenis_mitra as item}<option value={item}>{item}</option>{/each}</select></label>
            <label class="search-field">Cari email, kode, atau SLS<input name="search" value={filters.search} oninput={(event) => filters = { ...filters, search: event.currentTarget.value }} placeholder="Email, kode, atau SLS"></label>
            <div class="filter-actions"><button type="submit" class="accent-button">Terapkan</button><button type="button" class="quiet-button" onclick={clearFilters}>Reset</button></div>
        </form>
    </section>

    {#if loading}
        <section class="loading-grid" aria-label="Memuat dashboard"><div></div><div></div><div></div><div></div><div></div><div></div></section>
    {:else if error}
        <section class="state-panel" role="alert"><p>DATA BELUM TERSEDIA</p><h2>{error.includes('Not Found') ? 'Belum ada snapshot aktif.' : 'Data belum dapat dimuat.'}</h2><span>{error.includes('Not Found') ? 'Dashboard akan menampilkan data setelah snapshot pertama diimpor.' : error}</span><button class="accent-button" type="button" onclick={load}>Coba lagi</button></section>
    {:else if summary}
        <section class="metric-grid" aria-label="Ringkasan snapshot">
            <article class="metric-card lead"><span>Target penugasan</span><strong>{value(summary.metrics.target)}</strong><small>Target pada snapshot terpilih</small></article>
            <article class="metric-card lead"><span>Capaian PPL</span><strong>{value(summary.metrics.cumulative_ppl)}</strong><small>{percent(summary.metrics.progress_percent)} dari target</small></article>
            <article class="metric-card"><span>Perubahan PPL</span><strong class:negative={summary.metrics.net_change_ppl < 0}>{signed(summary.metrics.net_change_ppl)}</strong><small>{summary.comparison_snapshot ? `Dibandingkan ${displayDate(summary.comparison_snapshot.date)}` : 'Snapshot awal'}</small></article>
            <article class="metric-card"><span>Sisa target</span><strong>{value(summary.metrics.remaining)}</strong><small>Belum tercapai oleh PPL</small></article>
            <article class="metric-card"><span>Review PML</span><strong>{percent(summary.metrics.pml_vs_ppl_percent)}</strong><small>{value(summary.metrics.pending_review)} menunggu review</small></article>
            <article class="metric-card"><span>PML vs target</span><strong>{percent(summary.metrics.pml_vs_target_percent)}</strong><small>{value(summary.metrics.cumulative_pml)} capaian PML</small></article>
        </section>

        <section class="chart-grid">
            <article class="data-panel"><div class="panel-heading"><div><span>RIWAYAT</span><h2>Capaian kumulatif</h2></div><small>{series.length} snapshot</small></div><svg class="line-chart" viewBox="0 0 100 42" role="img" aria-label="Grafik kumulatif PPL dan PML"><path class="axis" d="M0 40 H100"/><path class="line ppl-line" d={chartPath('ppl')}/><path class="line pml-line" d={chartPath('pml')}/></svg><div class="legend"><span><i class="ppl-dot"></i>PPL {compact.format(summary.metrics.cumulative_ppl)}</span><span><i class="pml-dot"></i>PML {compact.format(summary.metrics.cumulative_pml)}</span></div></article>
            <article class="data-panel"><div class="panel-heading"><div><span>PERUBAHAN</span><h2>Per snapshot</h2></div><small>Baseline tidak dihitung</small></div><div class="bars" aria-label="Grafik perubahan PPL per snapshot">{#each series as point}<div class="bar-wrap" title={`${point.date}: ${signed(point.daily_ppl)}`}><div class:negative-bar={point.daily_ppl < 0} class="bar" style={`height:${Math.max(4, Math.abs(point.daily_ppl ?? 0) / chartMax('daily_ppl') * 100)}%`}></div><small>{point.date.slice(5)}</small></div>{/each}</div></article>
        </section>

        <section class="table-panel"><div class="panel-heading"><div><span>PETUGAS LAPANGAN</span><h2>Performa PPL</h2><p>Email PPL digunakan sebagai identitas publik. Buka baris untuk melihat Kode SubSLS dan Nama SLS.</p></div><small>{ppl.meta.total || 0} petugas</small></div>{#if tableErrors.ppl}<div class="table-state" role="alert"><strong>Tabel PPL belum dapat dimuat.</strong><span>{tableErrors.ppl}</span><button class="quiet-button" type="button" onclick={() => retryTable('ppl')}>Coba lagi</button></div>{:else}<div class="table-scroll"><table><caption class="sr-only">Performa PPL berdasarkan email</caption><thead><tr><th><button type="button" aria-label="Urutkan berdasarkan email PPL" onclick={() => sort('ppl', 'email')}>Email PPL <span class="sort-arrow">{sortIndicator('ppl', 'email')}</span></button></th><th><button type="button" onclick={() => sort('ppl', 'target')}>Target <span class="sort-arrow">{sortIndicator('ppl', 'target')}</span></button></th><th><button type="button" onclick={() => sort('ppl', 'ppl')}>Kumulatif <span class="sort-arrow">{sortIndicator('ppl', 'ppl')}</span></button></th><th><button type="button" onclick={() => sort('ppl', 'daily_ppl')}>Perubahan <span class="sort-arrow">{sortIndicator('ppl', 'daily_ppl')}</span></button></th><th><button type="button" onclick={() => sort('ppl', 'progress_percent')}>Progres <span class="sort-arrow">{sortIndicator('ppl', 'progress_percent')}</span></button></th><th><button type="button" onclick={() => sort('ppl', 'remaining')}>Sisa <span class="sort-arrow">{sortIndicator('ppl', 'remaining')}</span></button></th></tr></thead><tbody>{#each ppl.data as row}<tr><td class="identity-cell"><div class="identity-line"><button class="row-toggle" type="button" aria-expanded={breakdownState('ppl', row.id).open} aria-label="Tampilkan breakdown SubSLS" onclick={() => toggleBreakdown('ppl', row)}>{breakdownState('ppl', row.id).open ? '-' : '+'}</button><strong>{identity(row, 'ppl')}</strong></div><small>{row.assignments} SubSLS</small></td><td>{value(row.target)}</td><td>{value(row.ppl)}</td><td class:negative={row.daily_ppl < 0}>{signed(row.daily_ppl)}</td><td>{percent(row.progress_percent)}</td><td>{value(row.remaining)}</td></tr>{#if breakdownState('ppl', row.id).open}<tr class="breakdown-row"><td colspan="6"><div class="breakdown-inner"><div class="breakdown-heading"><strong>Breakdown SubSLS</strong><span>{breakdownState('ppl', row.id).meta.total || 0} kode</span></div>{#if breakdownState('ppl', row.id).loading}<p class="breakdown-loading">Memuat daftar SubSLS...</p>{:else if breakdownState('ppl', row.id).error}<div class="breakdown-error" role="alert"><span>{breakdownState('ppl', row.id).error}</span><button class="quiet-button" type="button" onclick={() => loadBreakdown('ppl', row)}>Coba lagi</button></div>{:else if !breakdownState('ppl', row.id).data.length}<p class="breakdown-empty">Tidak ada SubSLS pada filter aktif.</p>{:else}<div class="breakdown-scroll"><table class="breakdown-table"><caption class="sr-only">Breakdown SubSLS PPL</caption><thead><tr><th>Kode SubSLS</th><th>Nama SLS</th><th>Target</th><th>PPL</th><th>PML</th><th>Status</th></tr></thead><tbody>{#each breakdownState('ppl', row.id).data as detail}<tr><td><strong>{detail.kode_subsls}</strong></td><td>{detail.nama_sls}</td><td>{value(detail.target)}</td><td>{value(detail.ppl)}</td><td>{value(detail.pml)}</td><td>{detail.status_produktivitas || '-'}</td></tr>{/each}</tbody></table></div>{/if}</div></td></tr>{/if}{/each}</tbody></table></div>{#if ppl.meta.last_page > 1}<div class="pagination"><button type="button" disabled={ppl.meta.page <= 1} onclick={() => page('ppl', ppl.meta.page - 1)}>Sebelumnya</button><span>Halaman {ppl.meta.page} dari {ppl.meta.last_page}</span><button type="button" disabled={ppl.meta.page >= ppl.meta.last_page} onclick={() => page('ppl', ppl.meta.page + 1)}>Berikutnya</button></div>{/if}{/if}</section>

        <section class="table-panel"><div class="panel-heading"><div><span>SUPERVISI</span><h2>Performa PML</h2><p>Email PML digunakan sebagai identitas publik. Buka baris untuk melihat Kode SubSLS dan Nama SLS.</p></div><small>{pml.meta.total || 0} PML</small></div>{#if tableErrors.pml}<div class="table-state" role="alert"><strong>Tabel PML belum dapat dimuat.</strong><span>{tableErrors.pml}</span><button class="quiet-button" type="button" onclick={() => retryTable('pml')}>Coba lagi</button></div>{:else}<div class="table-scroll"><table><caption class="sr-only">Performa PML berdasarkan email</caption><thead><tr><th><button type="button" aria-label="Urutkan berdasarkan email PML" onclick={() => sort('pml', 'email')}>Email PML <span class="sort-arrow">{sortIndicator('pml', 'email')}</span></button></th><th>SubSLS</th><th><button type="button" onclick={() => sort('pml', 'target')}>Target <span class="sort-arrow">{sortIndicator('pml', 'target')}</span></button></th><th><button type="button" onclick={() => sort('pml', 'ppl')}>PPL <span class="sort-arrow">{sortIndicator('pml', 'ppl')}</span></button></th><th><button type="button" onclick={() => sort('pml', 'pml')}>PML <span class="sort-arrow">{sortIndicator('pml', 'pml')}</span></button></th><th><button type="button" onclick={() => sort('pml', 'pending_review')}>Tertunda <span class="sort-arrow">{sortIndicator('pml', 'pending_review')}</span></button></th><th><button type="button" onclick={() => sort('pml', 'daily_pml')}>Perubahan review <span class="sort-arrow">{sortIndicator('pml', 'daily_pml')}</span></button></th></tr></thead><tbody>{#each pml.data as row}<tr><td class="identity-cell"><div class="identity-line"><button class="row-toggle" type="button" aria-expanded={breakdownState('pml', row.id).open} aria-label="Tampilkan breakdown SubSLS" onclick={() => toggleBreakdown('pml', row)}>{breakdownState('pml', row.id).open ? '-' : '+'}</button><strong>{identity(row, 'pml')}</strong></div></td><td>{row.assignments}</td><td>{value(row.target)}</td><td>{value(row.ppl)}</td><td>{value(row.pml)}</td><td>{value(row.pending_review)}</td><td class:negative={row.daily_pml < 0}>{signed(row.daily_pml)}</td></tr>{#if breakdownState('pml', row.id).open}<tr class="breakdown-row"><td colspan="7"><div class="breakdown-inner"><div class="breakdown-heading"><strong>Breakdown SubSLS</strong><span>{breakdownState('pml', row.id).meta.total || 0} kode</span></div>{#if breakdownState('pml', row.id).loading}<p class="breakdown-loading">Memuat daftar SubSLS...</p>{:else if breakdownState('pml', row.id).error}<div class="breakdown-error" role="alert"><span>{breakdownState('pml', row.id).error}</span><button class="quiet-button" type="button" onclick={() => loadBreakdown('pml', row)}>Coba lagi</button></div>{:else if !breakdownState('pml', row.id).data.length}<p class="breakdown-empty">Tidak ada SubSLS pada filter aktif.</p>{:else}<div class="breakdown-scroll"><table class="breakdown-table"><caption class="sr-only">Breakdown SubSLS PML</caption><thead><tr><th>Kode SubSLS</th><th>Nama SLS</th><th>Email PPL</th><th>Target</th><th>PPL</th><th>PML</th><th>Status</th></tr></thead><tbody>{#each breakdownState('pml', row.id).data as detail}<tr><td><strong>{detail.kode_subsls}</strong></td><td>{detail.nama_sls}</td><td>{detail.ppl_email || 'Email PPL tidak tersedia'}</td><td>{value(detail.target)}</td><td>{value(detail.ppl)}</td><td>{value(detail.pml)}</td><td>{detail.status_produktivitas || '-'}</td></tr>{/each}</tbody></table></div>{/if}</div></td></tr>{/if}{/each}</tbody></table></div>{#if pml.meta.last_page > 1}<div class="pagination"><button type="button" disabled={pml.meta.page <= 1} onclick={() => page('pml', pml.meta.page - 1)}>Sebelumnya</button><span>Halaman {pml.meta.page} dari {pml.meta.last_page}</span><button type="button" disabled={pml.meta.page >= pml.meta.last_page} onclick={() => page('pml', pml.meta.page + 1)}>Berikutnya</button></div>{/if}{/if}</section>
    {/if}
</main>
