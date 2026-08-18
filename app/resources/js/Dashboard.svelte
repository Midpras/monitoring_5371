<script>
    import { onMount } from 'svelte';
    import SubSlsBreakdown from './SubSlsBreakdown.svelte';

    const initial = new URLSearchParams(window.location.search);
    const initialMulti = (key) => [...new Set([...initial.getAll(`${key}[]`), ...initial.getAll(key)].filter(Boolean))];
    let filters = {
        date: initial.get('date') || '',
        pml: initialMulti('pml'),
        ppl: initialMulti('ppl'),
        status: initial.get('status') || '',
        search: initial.get('search') || '',
    };
    let options = { dates: [], pml: [], ppl: [], productivity_statuses: [] };
    let summary;
    let series = [];
    let ppl = { data: [], meta: {} };
    let pml = { data: [], meta: {} };
    let loading = true;
    let error = '';
    let tableErrors = { ppl: '', pml: '' };
    let activeTab = 'pml';
    let loadSequence = 0;
    let activeLoadController;
    let pplPage = 1;
    let pmlPage = 1;
    let expandedPml = '';
    let childPpl = { worker: '', loading: false, data: [], error: '' };
    let breakdown = { type: '', worker: '', loading: false, data: [], error: '' };
    let breakdownController;
    let childController;

    const number = new Intl.NumberFormat('id-ID');
    const compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
    const date = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });

    const apiParams = (extra = {}) => {
        const params = new URLSearchParams();
        Object.entries({ ...filters, ...extra }).forEach(([key, value]) => {
            if (Array.isArray(value)) value.filter(Boolean).forEach((item) => params.append(`${key}[]`, item));
            else if (value !== '' && value != null) params.set(key, value);
        });
        return params.toString();
    };

    async function get(path, signal) {
        const timeoutController = signal ? undefined : new AbortController();
        const requestSignal = signal || timeoutController.signal;
        const timeout = timeoutController ? setTimeout(() => timeoutController.abort(), 15000) : undefined;
        try {
            const response = await fetch(path, { headers: { Accept: 'application/json' }, signal: requestSignal });
            const raw = await response.text();
            let body = {};
            try { body = raw ? JSON.parse(raw) : {}; } catch { body = {}; }
            if (!response.ok) throw new Error(body.message || `Permintaan gagal (${response.status}).`);
            return body;
        } catch (exception) {
            if (exception.name === 'AbortError') throw new Error('Permintaan data melewati batas waktu.');
            throw exception;
        } finally {
            if (timeout) clearTimeout(timeout);
        }
    }

    async function load() {
        const requestId = ++loadSequence;
        activeLoadController?.abort();
        const controller = new AbortController();
        activeLoadController = controller;
        const timeout = setTimeout(() => controller.abort(), 30000);
        loading = true;
        error = '';
        tableErrors = { ppl: '', pml: '' };
        closeBranches();
        try {
            const params = apiParams();
            const result = await Promise.allSettled([
                get('/api/dashboard/summary?' + params, controller.signal),
                get('/api/dashboard/timeseries?' + params, controller.signal),
                get('/api/dashboard/ppl?' + apiParams({ sort: 'daily_deficit', direction: 'desc', page: pplPage, per_page: 25 }), controller.signal),
                get('/api/dashboard/pml?' + apiParams({ sort: 'daily_deficit', direction: 'desc', page: pmlPage, per_page: 25 }), controller.signal),
                get('/api/dashboard/filters?' + params, controller.signal),
            ]);
            if (requestId !== loadSequence) return;
            if (result[0].status === 'rejected') throw result[0].reason;
            if (result[1].status === 'rejected') throw result[1].reason;
            if (result[4].status === 'rejected') throw result[4].reason;

            summary = result[0].value;
            ({ data: series } = result[1].value);
            options = result[4].value;
            if (result[2].status === 'fulfilled') ppl = result[2].value;
            else tableErrors = { ...tableErrors, ppl: result[2].reason.message };
            if (result[3].status === 'fulfilled') pml = result[3].value;
            else tableErrors = { ...tableErrors, pml: result[3].reason.message };
            if (!filters.date && summary.snapshot) {
                filters = { ...filters, date: summary.snapshot.date };
                writeUrl();
            }
        } catch (exception) {
            if (requestId === loadSequence) error = exception.message || 'Data belum dapat dimuat.';
        } finally {
            clearTimeout(timeout);
            if (requestId === loadSequence) {
                activeLoadController = undefined;
                loading = false;
            }
        }
    }

    function writeUrl() {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => {
            if (Array.isArray(value)) value.filter(Boolean).forEach((item) => params.append(`${key}[]`, item));
            else if (value) params.set(key, value);
        });
        history.replaceState({}, '', `${window.location.pathname}${params.size ? `?${params}` : ''}`);
    }

    function refreshFilters() {
        pplPage = 1;
        pmlPage = 1;
        writeUrl();
        load();
    }

    function changeFilter(event) { filters = { ...filters, [event.currentTarget.name]: event.currentTarget.value }; }

    function toggleMulti(type, value, checked) {
        const current = filters[type] || [];
        filters = { ...filters, [type]: checked ? [...new Set([...current, value])] : current.filter((item) => item !== value) };
    }

    function clearFilters() {
        filters = { date: filters.date, pml: [], ppl: [], status: '', search: '' };
        refreshFilters();
    }

    function multiLabel(type, label) { return filters[type].length ? `${filters[type].length} ${label} dipilih` : `Semua ${label}`; }

    function selectTab(type) {
        activeTab = type;
        closeBranches();
    }

    function page(type, value) {
        if (type === 'ppl') pplPage = Math.max(1, value);
        else pmlPage = Math.max(1, value);
        load();
    }

    async function retryTable(type) {
        try {
            const state = { sort: 'daily_deficit', direction: 'desc', page: type === 'ppl' ? pplPage : pmlPage, per_page: 25 };
            const body = await get(`/api/dashboard/${type}?${apiParams(state)}`);
            if (type === 'ppl') ppl = body;
            else pml = body;
            tableErrors = { ...tableErrors, [type]: '' };
        } catch (exception) {
            tableErrors = { ...tableErrors, [type]: exception.message };
        }
    }

    function closeBranches() {
        childController?.abort();
        breakdownController?.abort();
        expandedPml = '';
        childPpl = { worker: '', loading: false, data: [], error: '' };
        breakdown = { type: '', worker: '', loading: false, data: [], error: '' };
    }

    async function togglePml(row) {
        if (expandedPml === String(row.id) && !childPpl.error) {
            closeBranches();
            return;
        }
        breakdownController?.abort();
        expandedPml = String(row.id);
        breakdown = { type: '', worker: '', loading: false, data: [], error: '' };
        childController?.abort();
        childController = new AbortController();
        childPpl = { worker: String(row.id), loading: true, data: [], error: '' };
        try {
            const body = await get('/api/dashboard/ppl?' + apiParams({ pml: [row.id], sort: 'daily_deficit', direction: 'desc', page: 1, per_page: 100 }), childController.signal);
            if (expandedPml !== String(row.id)) return;
            childPpl = { worker: String(row.id), loading: false, data: body.data || [], error: '' };
        } catch (exception) {
            if (childController.signal.aborted) return;
            childPpl = { worker: String(row.id), loading: false, data: [], error: exception.message };
        }
    }

    async function toggleBreakdown(type, row) {
        const worker = String(row.id);
        if (breakdown.type === type && breakdown.worker === worker && !breakdown.error) {
            breakdownController?.abort();
            breakdown = { type: '', worker: '', loading: false, data: [], error: '' };
            return;
        }
        breakdownController?.abort();
        breakdownController = new AbortController();
        breakdown = { type, worker, loading: true, data: [], error: '' };
        try {
            const body = await get('/api/dashboard/daily-breakdown?' + apiParams({ type, worker }), breakdownController.signal);
            if (breakdown.type !== type || breakdown.worker !== worker) return;
            breakdown = { type, worker, loading: false, data: mergeBreakdown(body.data || [], worker), error: '' };
        } catch (exception) {
            if (breakdownController.signal.aborted) return;
            breakdown = { type, worker, loading: false, data: [], error: exception.message };
        }
    }

    function mergeBreakdown(snapshots, worker) {
        const rows = new Map();
        snapshots.forEach((snapshot) => {
            const workerData = (snapshot.workers || []).find((item) => item.id === worker);
            (workerData?.rows || []).forEach((row) => {
                if (!rows.has(row.kode_subsls)) rows.set(row.kode_subsls, { ...row, recent: [] });
                rows.get(row.kode_subsls).recent.push({ date: snapshot.date, daily: row.daily, cumulative: row.cumulative, target: row.target });
            });
        });
        return [...rows.values()];
    }

    function value(metric) { return metric == null ? '-' : number.format(metric); }
    function compactValue(metric) { return metric == null ? '-' : compact.format(metric); }
    function percent(metric) { return metric == null ? '-' : `${metric.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`; }
    function signed(metric) { return metric == null ? '-' : `${metric > 0 ? '+' : ''}${number.format(metric)}`; }
    function progressValue(row, snapshotDate) {
        const entry = recentFor(row, snapshotDate);
        return !entry ? '-' : entry.daily == null ? value(entry.cumulative) : signed(entry.daily);
    }
    function progressNote(row, snapshotDate) {
        const entry = recentFor(row, snapshotDate);
        return !entry ? '' : entry.daily == null ? 'Kumulatif' : `${value(entry.cumulative)} kum.`;
    }
    function chartLabel(point, type) {
        const daily = point[`daily_${type}`];
        return daily == null ? `${value(point[type])} kum.` : signed(daily);
    }
    function displayDate(value) { return date.format(new Date(`${value}T00:00:00`)); }
    function identity(row, type) { return row.email || `Email ${type.toUpperCase()} tidak tersedia`; }
    function recentDates() { return series.slice(-3).map((point) => point.date); }
    function recentFor(row, snapshotDate) { return row.recent?.find((item) => item.date === snapshotDate); }
    function dailyFor(row, snapshotDate) { return recentFor(row, snapshotDate)?.daily ?? null; }
    function cumulativeFor(row, snapshotDate) { return recentFor(row, snapshotDate)?.cumulative ?? null; }
    function chartMax() { return Math.max(...series.slice(-3).flatMap((point) => [Math.abs(point.daily_ppl || 0), Math.abs(point.daily_pml || 0)]), 1); }
    function barHeight(metric) { return metric == null ? 3 : Math.max(4, Math.abs(metric) / chartMax() * 100); }
    function deadlineNote() {
        if (!summary?.deadline?.date) return 'Tenggat belum diatur';
        if (summary.deadline.status === 'overdue') return `Tenggat lewat ${displayDate(summary.deadline.date)}`;
        if (summary.deadline.status === 'due') return `Tenggat ${displayDate(summary.deadline.date)}`;
        return `${summary.deadline.days_remaining} hari sampai ${displayDate(summary.deadline.date)}`;
    }
    function requiredLabel() {
        if (summary?.deadline?.status === 'overdue' || summary?.deadline?.status === 'due') return 'Harus selesai';
        return 'Kebutuhan per hari';
    }
    function deficit(row) { return row.daily_deficit == null ? null : Math.max(row.daily_deficit, 0); }
    function isNegative(metric) { return metric != null && metric < 0; }

    onMount(() => {
        const retryBreakdown = (event) => {
            const { type, worker } = event.detail || {};
            if (type && worker) toggleBreakdown(type, { id: worker });
        };
        window.addEventListener('sub-sls-retry', retryBreakdown);
        load();
        return () => window.removeEventListener('sub-sls-retry', retryBreakdown);
    });
</script>

<svelte:head><title>Monitoring Harian SE2026 Kupang</title></svelte:head>

<main class="dashboard-shell dashboard-v2">
    <header class="public-header dashboard-hero">
        <a class="brand" href="/" aria-label="Monitoring Harian SE2026 Kupang"><img class="brand-logo" src="/logo-se2026.png" alt=""><span class="brand-copy"><strong>SE2026</strong><small>Monitoring harian Kupang</small></span></a>
        <div class="header-title"><p>PEMANTAUAN LAPANGAN</p><h1>Progres harian Kota Kupang</h1><span class="public-badge">Data publik</span></div>
        <div class="public-header-actions">
            {#if summary?.snapshot}<div class="snapshot-meta"><span>Snapshot aktif</span><strong>{displayDate(summary.snapshot.date)} · v{summary.snapshot.version}</strong><small>Diperbarui {summary.snapshot.imported_at ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(summary.snapshot.imported_at)) : 'Belum diimpor'}</small></div>{/if}
            <a class="quiet-button admin-access-button" href="/admin">Masuk admin</a>
        </div>
    </header>

    <section class="filter-panel dashboard-filters" aria-label="Filter dashboard">
        <div class="filter-caption"><strong>Jelajahi data</strong><span>Filter berlaku untuk ringkasan dan tabel.</span></div>
        <form class="filters" onsubmit={(event) => { event.preventDefault(); refreshFilters(); }}>
            <label>Tanggal<select name="date" value={filters.date} onchange={changeFilter}><option value="">Snapshot terbaru</option>{#each options.dates as item}<option value={item}>{displayDate(item)}</option>{/each}</select></label>
            <div class="filter-control"><span>Email PML</span><details class="multi-filter"><summary>{multiLabel('pml', 'PML')}</summary><div class="multi-menu">{#each options.pml as item}<label class="check-option"><input type="checkbox" checked={filters.pml.includes(item.value)} onchange={(event) => toggleMulti('pml', item.value, event.currentTarget.checked)}><span>{item.label || item.value}</span></label>{:else}<span class="multi-empty">Belum ada email PML</span>{/each}</div></details></div>
            <div class="filter-control"><span>Email PPL</span><details class="multi-filter"><summary>{multiLabel('ppl', 'PPL')}</summary><div class="multi-menu">{#each options.ppl as item}<label class="check-option"><input type="checkbox" checked={filters.ppl.includes(item.value)} onchange={(event) => toggleMulti('ppl', item.value, event.currentTarget.checked)}><span>{item.label || item.value}</span></label>{:else}<span class="multi-empty">Belum ada email PPL</span>{/each}</div></details></div>
            <label>Produktivitas<select name="status" value={filters.status} onchange={changeFilter}><option value="">Semua status</option>{#each options.productivity_statuses as item}<option value={item}>{item}</option>{/each}</select></label>
            <label class="search-field">Cari email, kode, atau SLS<input name="search" value={filters.search} oninput={(event) => filters = { ...filters, search: event.currentTarget.value }} placeholder="Email, kode, atau SLS"></label>
            <div class="filter-actions"><button type="submit" class="accent-button">Terapkan</button><button type="button" class="quiet-button" onclick={clearFilters}>Reset</button></div>
        </form>
    </section>

    {#if loading}
        <section class="loading-grid dashboard-loading" aria-label="Memuat dashboard"><div></div><div></div><div></div><div></div><div></div><div></div></section>
    {:else if error}
        <section class="state-panel" role="alert"><p>DATA BELUM TERSEDIA</p><h2>Data belum dapat dimuat.</h2><span>{error}</span><button class="accent-button" type="button" onclick={load}>Coba lagi</button></section>
    {:else if summary?.snapshot}
        <section class="metric-grid dashboard-metrics" aria-label="Ringkasan Kota Kupang">
            <article class="metric-card lead"><span>Total target</span><strong>{value(summary.metrics.target)}</strong><small>Snapshot {displayDate(summary.snapshot.date)}</small></article>
            <article class="metric-card lead"><span>Capaian PPL</span><strong>{value(summary.metrics.cumulative_ppl)}</strong><small>{percent(summary.metrics.progress_percent)} dari target</small></article>
            <article class="metric-card"><span>Capaian PML</span><strong>{value(summary.metrics.cumulative_pml)}</strong><small>{percent(summary.metrics.pml_vs_target_percent)} dari target</small></article>
            <article class="metric-card"><span>Antrean review</span><strong>{value(summary.metrics.pending_review)}</strong><small>PPL belum direview PML</small></article>
            <article class="metric-card daily-card"><span>Target harian PPL</span><strong>{value(summary.metrics.required_daily_ppl)}</strong><small>{requiredLabel()}</small></article>
            <article class="metric-card daily-card"><span>Target harian PML</span><strong>{value(summary.metrics.required_daily_pml)}</strong><small>{requiredLabel()}</small></article>
        </section>

        <section class="chart-grid dashboard-chart-grid">
            <article class="data-panel daily-chart-panel"><div class="panel-heading"><div><span>PROGRES HARIAN</span><h2>Perubahan tiga snapshot terakhir</h2></div><small>{series.length ? `${recentDates().length} tanggal` : 'Belum ada data'}</small></div>
                {#if recentDates().length}<div class="daily-bars" aria-label="Grafik progres harian PPL dan PML">{#each series.slice(-3) as point}<div class="daily-bar-group"><div class="daily-bar-pair"><div class:negative-bar={isNegative(point.daily_ppl)} class="daily-bar" style={`height:${barHeight(point.daily_ppl)}%`} title={`PPL ${chartLabel(point, 'ppl')}`}></div><div class:negative-bar={isNegative(point.daily_pml)} class="daily-bar pml-bar" style={`height:${barHeight(point.daily_pml)}%`} title={`PML ${chartLabel(point, 'pml')}`}></div></div><strong>{point.date.slice(5)}</strong><small>PPL {chartLabel(point, 'ppl')}</small><small>PML {chartLabel(point, 'pml')}</small></div>{/each}</div><div class="legend"><span><i class="ppl-dot"></i>PPL</span><span><i class="pml-dot"></i>PML</span></div>{:else}<p class="chart-empty">Belum ada riwayat snapshot.</p>{/if}
            </article>
            <article class="data-panel chart-summary"><div class="panel-heading"><div><span>TENGGAT</span><h2>Kebutuhan sampai selesai</h2></div></div><div class="chart-summary-value"><strong>{deadlineNote()}</strong><span>Target harian dihitung dari sisa target pada snapshot aktif.</span></div><dl><div><dt>Perubahan PPL</dt><dd class:negative={isNegative(summary.metrics.net_change_ppl)}>{signed(summary.metrics.net_change_ppl)}</dd></div><div><dt>Perubahan PML</dt><dd class:negative={isNegative(summary.metrics.net_change_pml)}>{signed(summary.metrics.net_change_pml)}</dd></div><div><dt>Review</dt><dd>{percent(summary.metrics.pml_vs_ppl_percent)}</dd></div></dl></article>
        </section>

        <section class="table-panel current-workers-panel" aria-busy={childPpl.loading || breakdown.loading}>
            <div class="section-heading"><div><span>AKUMULASI PROGRES WILAYAH</span><h2>Pantau petugas</h2><p>Tab PML menunjukkan PPL di bawahnya. Gunakan tombol + untuk melihat PPL dan rincian SubSLS.</p></div><small>{activeTab === 'pml' ? pml.meta.total || 0 : ppl.meta.total || 0} petugas</small></div>
            <div class="tab-list" role="tablist" aria-label="Jenis petugas"><button type="button" role="tab" aria-selected={activeTab === 'pml'} class:active={activeTab === 'pml'} onclick={() => selectTab('pml')}>PML</button><button type="button" role="tab" aria-selected={activeTab === 'ppl'} class:active={activeTab === 'ppl'} onclick={() => selectTab('ppl')}>PPL</button></div>

            {#if activeTab === 'pml'}
                {#if tableErrors.pml}<div class="table-state" role="alert"><strong>Tabel PML belum dapat dimuat.</strong><span>{tableErrors.pml}</span><button class="quiet-button" type="button" onclick={() => retryTable('pml')}>Coba lagi</button></div>{:else}<div class="table-scroll"><table class="current-table"><caption class="sr-only">Progres PML dan PPL</caption><thead><tr><th>Petugas PML</th><th>SubSLS</th><th>Target</th><th>Capaian PML</th>{#each recentDates() as snapshot}<th>{displayDate(snapshot)}<small>Harian / kum.</small></th>{/each}<th>Butuh/hari</th><th>Progres</th><th>Antrean</th></tr></thead><tbody>{#each pml.data as row}<tr><td class="identity-cell"><div class="identity-line"><button class="row-toggle" type="button" aria-label={expandedPml === String(row.id) ? 'Tutup PPL' : 'Buka PPL'} aria-expanded={expandedPml === String(row.id)} onclick={() => togglePml(row)}>{expandedPml === String(row.id) ? '−' : '+'}</button><strong>{identity(row, 'pml')}</strong></div><small>{row.assignments} SubSLS</small></td><td>{row.assignments}</td><td>{value(row.target)}</td><td>{value(row.pml)}</td>{#each recentDates() as snapshot}<td class:negative={isNegative(dailyFor(row, snapshot))} class="recent-cell"><strong>{progressValue(row, snapshot)}</strong><small>{progressNote(row, snapshot)}</small></td>{/each}<td>{value(row.required_daily)}</td><td>{percent(row.progress_percent)}</td><td>{value(row.pending_review)}</td></tr>{#if expandedPml === String(row.id)}<tr class="tree-row"><td colspan={7 + recentDates().length}><div class="tree-inner"><div class="tree-heading"><strong>PPL di bawah {identity(row, 'pml')}</strong><span>{childPpl.loading ? 'Memuat...' : `${childPpl.data.length} PPL`}</span></div>{#if childPpl.loading}<div class="daily-skeleton"><span></span><span></span></div>{:else if childPpl.error}<div class="breakdown-error" role="alert"><span>{childPpl.error}</span><button class="quiet-button" type="button" onclick={() => togglePml(row)}>Coba lagi</button></div>{:else if !childPpl.data.length}<p class="breakdown-empty">Tidak ada PPL pada filter aktif.</p>{:else}<div class="table-scroll child-scroll"><table class="child-table"><thead><tr><th>PPL</th><th>Target</th><th>Kumulatif</th>{#each recentDates() as snapshot}<th>{displayDate(snapshot)}<small>Harian / kum.</small></th>{/each}<th>Butuh/hari</th><th>Progres</th><th>Sisa</th></tr></thead><tbody>{#each childPpl.data as child}<tr><td class="identity-cell"><div class="identity-line"><button class="row-toggle" type="button" aria-label={breakdown.type === 'ppl' && breakdown.worker === String(child.id) ? 'Tutup SubSLS' : 'Buka SubSLS'} aria-expanded={breakdown.type === 'ppl' && breakdown.worker === String(child.id)} onclick={() => toggleBreakdown('ppl', child)}>{breakdown.type === 'ppl' && breakdown.worker === String(child.id) ? '−' : '+'}</button><strong>{identity(child, 'ppl')}</strong></div><small>{child.assignments} SubSLS</small></td><td>{value(child.target)}</td><td>{value(child.ppl)}</td>{#each recentDates() as snapshot}<td class:negative={isNegative(dailyFor(child, snapshot))} class="recent-cell"><strong>{progressValue(child, snapshot)}</strong><small>{progressNote(child, snapshot)}</small></td>{/each}<td>{value(child.required_daily)}</td><td>{percent(child.progress_percent)}</td><td>{value(child.remaining)}</td></tr>{#if breakdown.type === 'ppl' && breakdown.worker === String(child.id)}<tr class="breakdown-row"><td colspan={6 + recentDates().length}><SubSlsBreakdown {breakdown} dates={recentDates()} /></td></tr>{/if}{/each}</tbody></table></div>{/if}</div></td></tr>{/if}{/each}</tbody></table></div>{#if pml.meta.last_page > 1}<div class="pagination"><button type="button" disabled={pml.meta.page <= 1} onclick={() => page('pml', pml.meta.page - 1)}>Sebelumnya</button><span>Halaman {pml.meta.page} dari {pml.meta.last_page}</span><button type="button" disabled={pml.meta.page >= pml.meta.last_page} onclick={() => page('pml', pml.meta.page + 1)}>Berikutnya</button></div>{/if}{/if}
            {:else}
                {#if tableErrors.ppl}<div class="table-state" role="alert"><strong>Tabel PPL belum dapat dimuat.</strong><span>{tableErrors.ppl}</span><button class="quiet-button" type="button" onclick={() => retryTable('ppl')}>Coba lagi</button></div>{:else}<div class="table-scroll"><table class="current-table"><caption class="sr-only">Progres PPL</caption><thead><tr><th>PPL</th><th>Target</th><th>Kumulatif</th>{#each recentDates() as snapshot}<th>{displayDate(snapshot)}<small>Harian / kum.</small></th>{/each}<th>Butuh/hari</th><th>Progres</th><th>Sisa</th></tr></thead><tbody>{#each ppl.data as row}<tr><td class="identity-cell"><div class="identity-line"><button class="row-toggle" type="button" aria-label={breakdown.type === 'ppl' && breakdown.worker === String(row.id) ? 'Tutup SubSLS' : 'Buka SubSLS'} aria-expanded={breakdown.type === 'ppl' && breakdown.worker === String(row.id)} onclick={() => toggleBreakdown('ppl', row)}>{breakdown.type === 'ppl' && breakdown.worker === String(row.id) ? '−' : '+'}</button><strong>{identity(row, 'ppl')}</strong></div><small>Di bawah {row.pml_email || 'PML tidak tersedia'} · {row.assignments} SubSLS</small></td><td>{value(row.target)}</td><td>{value(row.ppl)}</td>{#each recentDates() as snapshot}<td class:negative={isNegative(dailyFor(row, snapshot))} class="recent-cell"><strong>{progressValue(row, snapshot)}</strong><small>{progressNote(row, snapshot)}</small></td>{/each}<td>{value(row.required_daily)}</td><td>{percent(row.progress_percent)}</td><td>{value(row.remaining)}</td></tr>{#if breakdown.type === 'ppl' && breakdown.worker === String(row.id)}<tr class="breakdown-row"><td colspan={6 + recentDates().length}><SubSlsBreakdown {breakdown} dates={recentDates()} /></td></tr>{/if}{/each}</tbody></table></div>{#if ppl.meta.last_page > 1}<div class="pagination"><button type="button" disabled={ppl.meta.page <= 1} onclick={() => page('ppl', ppl.meta.page - 1)}>Sebelumnya</button><span>Halaman {ppl.meta.page} dari {ppl.meta.last_page}</span><button type="button" disabled={ppl.meta.page >= ppl.meta.last_page} onclick={() => page('ppl', ppl.meta.page + 1)}>Berikutnya</button></div>{/if}{/if}
            {/if}
        </section>
    {:else}
        <section class="state-panel" role="status"><p>DATA BELUM TERSEDIA</p><h2>Belum ada snapshot aktif.</h2><span>Dashboard akan menampilkan data setelah snapshot pertama diimpor.</span><button class="accent-button" type="button" onclick={load}>Coba lagi</button></section>
    {/if}
</main>
