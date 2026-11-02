import { useEffect, useRef, useState } from 'react';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { url } from '../../lib/url';
import { money, pnlColor, price, when } from '../../lib/format';

function Stat({ label, value, color }) {
  return (
    <div style={{ padding: '.6rem .9rem', border: '1px solid #e5e7eb', borderRadius: 6, minWidth: 110 }}>
      <div style={{ fontSize: '.75em', opacity: 0.7 }}>{label}</div>
      <div style={{ fontSize: '1.3em', fontWeight: 600, color }}>{value}</div>
    </div>
  );
}

function Stats({ stats }) {
  if (!stats) return null;
  return (
    <div style={{ display: 'flex', gap: '.6rem', flexWrap: 'wrap', margin: '1rem 0' }}>
      <Stat label="Net P&L" value={money(stats.net_pnl)} color={pnlColor(stats.net_pnl)} />
      <Stat label="Win rate" value={stats.win_rate === null ? '—' : `${stats.win_rate}%`} />
      <Stat label="Profit factor" value={stats.profit_factor ?? '—'} />
      <Stat label="Avg P&L" value={money(stats.avg_pnl)} color={pnlColor(stats.avg_pnl)} />
      <Stat label="Closed / open" value={`${stats.closed} / ${stats.open}`} />
    </div>
  );
}

export default function Index({ trades, total, page, per_page, filters, tags, stats }) {
  const [q, setQ] = useState(filters.q ?? '');
  const first = useRef(true);

  const visit = (next) => {
    const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v != null));
    router.get(url('trade_manager'), clean, { preserveState: true, preserveScroll: true, replace: true, only: ['trades', 'total', 'page', 'filters'] });
  };
  const setFilter = (key, value) => visit({ ...filters, [key]: value, page: undefined });

  // Debounced text search
  useEffect(() => {
    if (first.current) { first.current = false; return; }
    const t = setTimeout(() => setFilter('q', q), 300);
    return () => clearTimeout(t);
  }, [q]);

  const remove = (t) =>
    router.delete(url(`trade_manager/delete/${t.id}`), {
      preserveScroll: true,
      onBefore: () => confirm(`Delete ${t.symbol} trade and its screenshots?`),
    });

  const pages = Math.max(1, Math.ceil(total / per_page));
  const linkData = (p) => ({ ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), ...(p > 1 ? { page: p } : {}) });

  return (
    <>
      <Head title="Trade Journal" />
      <h1>Trade Journal</h1>

      <Deferred data="stats" fallback={<p style={{ opacity: 0.6 }}>Loading stats…</p>}>
        <Stats stats={stats} />
      </Deferred>

      <div style={{ display: 'flex', gap: '.5rem', flexWrap: 'wrap', alignItems: 'center', margin: '1rem 0' }}>
        <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search symbol or notes" autoComplete="off" style={{ flex: '1 1 200px' }} />
        <select value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
          <option value="">Any status</option>
          <option value="open">Open</option>
          <option value="closed">Closed</option>
        </select>
        <select value={filters.direction} onChange={(e) => setFilter('direction', e.target.value)}>
          <option value="">Long & short</option>
          <option value="long">Long</option>
          <option value="short">Short</option>
        </select>
        <select value={filters.tag} onChange={(e) => setFilter('tag', e.target.value)}>
          <option value="">Any tag</option>
          {tags.map((t) => <option key={t.id} value={t.name}>{t.name} ({t.uses})</option>)}
        </select>
        <input type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} title="From" />
        <input type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} title="To" />
        <Link href={url('trade_manager/create')} className="button">+ New trade</Link>
      </div>

      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr>
              <th>Date</th><th>Symbol</th><th>Side</th><th>Entry</th><th>Exit</th>
              <th>Qty</th><th>P&L</th><th>R</th><th>Tags</th><th></th>
            </tr>
          </thead>
          <tbody>
            {trades.map((t) => (
              <tr key={t.id}>
                <td>{when(t.entry_date)}</td>
                <td>
                  <Link href={url(`trade_manager/show/${t.id}`)}>{t.symbol}</Link>
                  {t.attachment_count > 0 && <span title={`${t.attachment_count} screenshot(s)`}> 📎{t.attachment_count}</span>}
                </td>
                <td>{t.direction}{t.status === 'open' && ' · open'}</td>
                <td>{price(t.entry_price)}</td>
                <td>{price(t.exit_price)}</td>
                <td>{price(t.quantity)}</td>
                <td style={{ color: pnlColor(t.pnl), fontWeight: 600 }}>{money(t.pnl)}</td>
                <td>{t.r_multiple ?? '—'}</td>
                <td>
                  {t.tags.map((name) => (
                    <button key={name} type="button" className="alt" style={{ padding: '0 .5em', marginRight: 4, fontSize: '.8em' }} onClick={() => setFilter('tag', name)}>
                      {name}
                    </button>
                  ))}
                </td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  <Link href={url(`trade_manager/edit/${t.id}`)}>Edit</Link>{' '}
                  <button type="button" className="danger" onClick={() => remove(t)}>Delete</button>
                </td>
              </tr>
            ))}
            {trades.length === 0 && (
              <tr><td colSpan={10}>No trades found. <Link href={url('trade_manager/create')}>Log your first trade</Link>.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      <p>
        {page > 1 && <Link href={url('trade_manager')} data={linkData(page - 1)} preserveState>« Prev</Link>}{' '}
        Page {page} / {pages} ({total} trades){' '}
        {page < pages && <Link href={url('trade_manager')} data={linkData(page + 1)} preserveState>Next »</Link>}
      </p>
    </>
  );
}
