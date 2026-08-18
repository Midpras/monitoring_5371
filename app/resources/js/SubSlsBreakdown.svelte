<script>
    export let breakdown;
    export let dates = [];

    const number = new Intl.NumberFormat('id-ID');
    const date = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });

    function value(metric) { return metric == null ? '-' : number.format(metric); }
    function signed(metric) { return metric == null ? '-' : `${metric > 0 ? '+' : ''}${number.format(metric)}`; }
    function percent(metric) { return metric == null ? '-' : `${metric.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`; }
    function displayDate(value) { return date.format(new Date(`${value}T00:00:00`)); }
    function recentFor(row, snapshotDate) { return row.recent?.find((item) => item.date === snapshotDate); }
    function progressEntry(row, snapshotDate) { return recentFor(row, snapshotDate); }
    function progressValue(row, snapshotDate) {
        const entry = progressEntry(row, snapshotDate);
        return !entry ? '-' : entry.daily == null ? value(entry.cumulative) : signed(entry.daily);
    }
    function progressNote(row, snapshotDate) {
        const entry = progressEntry(row, snapshotDate);
        return !entry ? '' : entry.daily == null ? 'Kumulatif' : `${value(entry.cumulative)} kum.`;
    }
    function retryRequest() { window.dispatchEvent(new CustomEvent('sub-sls-retry', { detail: { type: breakdown.type, worker: breakdown.worker } })); }
</script>

<div class="breakdown-inner">
    <div class="breakdown-heading"><strong>Breakdown SubSLS</strong><span>{breakdown.loading ? 'Memuat...' : `${breakdown.data.length} kode`}</span></div>
    {#if breakdown.loading}
        <div class="daily-skeleton"><span></span><span></span><span></span></div>
    {:else if breakdown.error}
        <div class="breakdown-error" role="alert"><span>{breakdown.error}</span><button class="quiet-button" type="button" onclick={retryRequest}>Coba lagi</button></div>
    {:else if !breakdown.data.length}
        <p class="breakdown-empty">Tidak ada SubSLS pada filter aktif.</p>
    {:else}
        <div class="breakdown-scroll"><table class="breakdown-table"><caption class="sr-only">Breakdown SubSLS</caption><thead><tr><th>Kode SubSLS</th><th>Nama SLS</th><th>Target</th>{#each dates as snapshot}<th>{displayDate(snapshot)}<small>Harian / kum.</small></th>{/each}<th>Progres</th><th>Status</th></tr></thead><tbody>{#each breakdown.data as detail}<tr><td><strong>{detail.kode_subsls}</strong></td><td>{detail.nama_sls}</td><td>{value(detail.target)}</td>{#each dates as snapshot}{#if recentFor(detail, snapshot)}<td class:negative={progressEntry(detail, snapshot).daily != null && progressEntry(detail, snapshot).daily < 0} class="recent-cell"><strong>{progressValue(detail, snapshot)}</strong><small>{progressNote(detail, snapshot)}</small></td>{:else}<td>-</td>{/if}{/each}<td>{percent(detail.progress_percent)}</td><td>{detail.status_produktivitas || '-'}</td></tr>{/each}</tbody></table></div>
    {/if}
</div>
