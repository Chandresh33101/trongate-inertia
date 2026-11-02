import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { url } from '../../lib/url';
import { money, pnlColor, price, when } from '../../lib/format';

const Item = ({ label, children }) => (
  <div>
    <div style={{ fontSize: '.75em', opacity: 0.65 }}>{label}</div>
    <div>{children ?? '—'}</div>
  </div>
);

export default function Show({ trade: t }) {
  const [open, setOpen] = useState(null);   // attachment being viewed full-size

  useEffect(() => {
    if (!open) return;
    const onKey = (e) => e.key === 'Escape' && setOpen(null);
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  const remove = () =>
    router.delete(url(`trade_manager/delete/${t.id}`), {
      onBefore: () => confirm(`Delete ${t.symbol} trade and its screenshots?`),
    });

  return (
    <>
      <Head title={`${t.symbol} #${t.id}`} />
      <h1>
        {t.symbol}{' '}
        <small style={{ fontWeight: 400, opacity: 0.7 }}>{t.direction} · {t.status}</small>
      </h1>

      <p>
        <Link href={url(`trade_manager/edit/${t.id}`)} className="button">Edit</Link>{' '}
        <button type="button" className="danger" onClick={remove}>Delete</button>{' '}
        <Link href={url('trade_manager')} className="button alt">Back to journal</Link>
      </p>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '1rem', margin: '1rem 0' }}>
        <Item label="Net P&L"><strong style={{ color: pnlColor(t.pnl) }}>{money(t.pnl)}</strong></Item>
        <Item label="R multiple">{t.r_multiple}</Item>
        <Item label="Entry">{price(t.entry_price)} · {when(t.entry_date)}</Item>
        <Item label="Exit">{t.status === 'closed' ? `${price(t.exit_price)} · ${when(t.exit_date)}` : 'Still open'}</Item>
        <Item label="Quantity">{price(t.quantity)}</Item>
        <Item label="Stop loss">{t.stop_loss !== null ? price(t.stop_loss) : null}</Item>
        <Item label="Take profit">{t.take_profit !== null ? price(t.take_profit) : null}</Item>
        <Item label="Fees">{price(t.fees)}</Item>
      </div>

      {t.tags.length > 0 && (
        <p>
          {t.tags.map((name) => (
            <Link key={name} href={url('trade_manager')} data={{ tag: name }}
              style={{ background: '#eef2ff', borderRadius: 12, padding: '2px 10px', marginRight: 6, fontSize: '.85em' }}>
              {name}
            </Link>
          ))}
        </p>
      )}

      <h3>Notes</h3>
      {t.notes ? <p style={{ whiteSpace: 'pre-wrap' }}>{t.notes}</p> : <p style={{ opacity: 0.6 }}>No notes.</p>}

      <h3>Screenshots</h3>
      {t.attachments.length === 0 && <p style={{ opacity: 0.6 }}>No screenshots attached.</p>}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
        {t.attachments.map((a) => (
          <button key={a.id} type="button" onClick={() => setOpen(a)} style={{ all: 'unset', cursor: 'zoom-in' }}>
            <img src={url(`trade_manager/thumb/${a.id}`)} alt={a.original_name} loading="lazy" width={220} style={{ borderRadius: 6, display: 'block' }} />
          </button>
        ))}
      </div>

      {open && (
        <div role="dialog" aria-label={open.original_name} onClick={() => setOpen(null)}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.85)', display: 'grid', placeItems: 'center', zIndex: 50, cursor: 'zoom-out', padding: 16 }}>
          <img src={url(`trade_manager/screenshot/${open.id}`)} alt={open.original_name} style={{ maxWidth: '100%', maxHeight: '100%' }} />
        </div>
      )}
    </>
  );
}
