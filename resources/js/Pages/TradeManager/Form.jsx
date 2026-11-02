import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { url } from '../../lib/url';
import { money, pnlColor } from '../../lib/format';

const ACCEPT = 'image/png,image/jpeg,image/gif,image/webp';

const Field = ({ label, error, children, style }) => (
  <label style={{ display: 'block', marginBottom: '.8rem', ...style }}>
    <span style={{ display: 'block', fontSize: '.85em', marginBottom: 2 }}>{label}</span>
    {children}
    {error && <span style={{ color: 'red', fontSize: '.85em' }}>{error}</span>}
  </label>
);

export default function Form({ trade, tags: knownTags, limits, defaults }) {
  const editing = trade !== null;

  const form = useForm({
    symbol: trade?.symbol ?? '',
    direction: trade?.direction ?? 'long',
    status: trade?.status ?? 'open',
    entry_price: trade?.entry_price ?? '',
    exit_price: trade?.exit_price ?? '',
    quantity: trade?.quantity ?? '',
    stop_loss: trade?.stop_loss ?? '',
    take_profit: trade?.take_profit ?? '',
    fees: trade?.fees ?? 0,
    entry_date: trade?.entry_date ?? defaults?.entry_date ?? '',
    exit_date: trade?.exit_date ?? '',
    notes: trade?.notes ?? '',
    tags: trade?.tags ?? [],
    screenshots: [],
  });
  const { data, setData, errors } = form;

  const [tagInput, setTagInput] = useState('');
  const existing = trade?.attachments ?? [];
  const slotsLeft = limits.max_files - existing.length - data.screenshots.length;

  // ── Tags ──────────────────────────────────────────────────────────────────
  const addTag = (raw) => {
    const name = raw.trim().replace(/\s+/g, ' ').slice(0, 30);
    if (!name || data.tags.length >= limits.max_tags) return;
    if (data.tags.some((t) => t.toLowerCase() === name.toLowerCase())) return;
    setData('tags', [...data.tags, name]);
  };
  const onTagKey = (e) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      addTag(tagInput);
      setTagInput('');
    } else if (e.key === 'Backspace' && tagInput === '' && data.tags.length) {
      setData('tags', data.tags.slice(0, -1));
    }
  };

  // ── Screenshots (file picker, drag-drop and Ctrl+V paste) ──────────────────
  const addFiles = (list) => {
    const images = Array.from(list).filter((f) => f.type.startsWith('image/'));
    if (images.length) setData('screenshots', [...data.screenshots, ...images].slice(0, limits.max_files - existing.length));
  };
  useEffect(() => {
    const onPaste = (e) => {
      const files = Array.from(e.clipboardData?.files ?? []);
      if (files.length) { e.preventDefault(); addFiles(files); }
    };
    window.addEventListener('paste', onPaste);
    return () => window.removeEventListener('paste', onPaste);
  });

  const previews = useMemo(() => data.screenshots.map((f) => URL.createObjectURL(f)), [data.screenshots]);
  useEffect(() => () => previews.forEach((u) => URL.revokeObjectURL(u)), [previews]);

  const removeExisting = (a) =>
    router.delete(url(`trade_manager/delete_attachment/${a.id}`), {
      preserveScroll: true,
      onBefore: () => confirm('Remove this screenshot?'),
    });

  // ── Live P&L preview (the server recalculates on save) ──────────────────────
  const livePnl = useMemo(() => {
    if (data.status !== 'closed') return null;
    const [e, x, q, f] = [data.entry_price, data.exit_price, data.quantity, data.fees || 0].map(Number);
    if (!data.entry_price || !data.exit_price || !data.quantity) return null;
    return (data.direction === 'short' ? e - x : x - e) * q - f;
  }, [data.status, data.direction, data.entry_price, data.exit_price, data.quantity, data.fees]);

  const submit = (e) => {
    e.preventDefault();
    form.post(url(editing ? `trade_manager/update/${trade.id}` : 'trade_manager/store'), {
      forceFormData: true,   // screenshots travel as multipart; update uses POST for that reason
      preserveScroll: true,
    });
  };

  const input = (name, props = {}) => (
    <input value={data[name]} onChange={(e) => setData(name, e.target.value)} autoComplete="off" {...props} />
  );
  const row = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '.8rem' };

  return (
    <>
      <Head title={editing ? `Edit ${trade.symbol}` : 'New trade'} />
      <h1>{editing ? `Edit ${trade.symbol} #${trade.id}` : 'New trade'}</h1>

      <form onSubmit={submit}>
        <div style={row}>
          <Field label="Symbol *" error={errors.symbol}>{input('symbol', { placeholder: 'AAPL, EURUSD…', maxLength: 20 })}</Field>
          <Field label="Direction" error={errors.direction}>
            <select value={data.direction} onChange={(e) => setData('direction', e.target.value)}>
              <option value="long">Long</option>
              <option value="short">Short</option>
            </select>
          </Field>
          <Field label="Status" error={errors.status}>
            <select value={data.status} onChange={(e) => setData('status', e.target.value)}>
              <option value="open">Open</option>
              <option value="closed">Closed</option>
            </select>
          </Field>
        </div>

        <div style={row}>
          <Field label="Entry price *" error={errors.entry_price}>{input('entry_price', { type: 'number', step: 'any', min: 0 })}</Field>
          <Field label="Quantity *" error={errors.quantity}>{input('quantity', { type: 'number', step: 'any', min: 0 })}</Field>
          <Field label="Stop loss" error={errors.stop_loss}>{input('stop_loss', { type: 'number', step: 'any', min: 0 })}</Field>
          <Field label="Take profit" error={errors.take_profit}>{input('take_profit', { type: 'number', step: 'any', min: 0 })}</Field>
          <Field label="Fees" error={errors.fees}>{input('fees', { type: 'number', step: 'any' })}</Field>
        </div>

        <div style={row}>
          <Field label="Entry date *" error={errors.entry_date}>{input('entry_date', { type: 'datetime-local' })}</Field>
          {data.status === 'closed' && (
            <>
              <Field label="Exit price *" error={errors.exit_price}>{input('exit_price', { type: 'number', step: 'any', min: 0 })}</Field>
              <Field label="Exit date *" error={errors.exit_date}>{input('exit_date', { type: 'datetime-local' })}</Field>
            </>
          )}
        </div>

        {livePnl !== null && (
          <p>Net P&L: <strong style={{ color: pnlColor(livePnl) }}>{money(Math.round(livePnl * 100) / 100)}</strong></p>
        )}

        <Field label={`Tags (Enter or comma to add, up to ${limits.max_tags})`} error={errors.tags}>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
            {data.tags.map((name) => (
              <span key={name} style={{ background: '#eef2ff', borderRadius: 12, padding: '2px 10px', fontSize: '.85em' }}>
                {name}{' '}
                <button type="button" aria-label={`Remove ${name}`} onClick={() => setData('tags', data.tags.filter((t) => t !== name))}
                  style={{ all: 'unset', cursor: 'pointer' }}>×</button>
              </span>
            ))}
            <input
              list="known-tags"
              value={tagInput}
              onChange={(e) => setTagInput(e.target.value)}
              onKeyDown={onTagKey}
              onBlur={() => { addTag(tagInput); setTagInput(''); }}
              placeholder="breakout, FOMO, A+ setup…"
              maxLength={30}
              style={{ flex: '1 1 160px' }}
            />
            <datalist id="known-tags">{knownTags.map((t) => <option key={t.id} value={t.name} />)}</datalist>
          </div>
        </Field>

        <Field label="Notes" error={errors.notes}>
          <textarea rows={6} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Why did you take it? What did you learn?" />
        </Field>

        <Field label={`Screenshots (${existing.length + data.screenshots.length}/${limits.max_files}, max ${limits.max_file_mb} MB each)`} error={errors.screenshots}>
          <div
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => { e.preventDefault(); addFiles(e.dataTransfer.files); }}
            style={{ border: '2px dashed #cbd5e1', borderRadius: 8, padding: '1rem', textAlign: 'center' }}
          >
            <input type="file" accept={ACCEPT} multiple disabled={slotsLeft <= 0}
              onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }} />
            <div style={{ fontSize: '.85em', opacity: 0.7, marginTop: 6 }}>…or drop images here, or press Ctrl/⌘+V to paste a screenshot</div>
          </div>
        </Field>

        {(existing.length > 0 || data.screenshots.length > 0) && (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginBottom: '1rem' }}>
            {existing.map((a) => (
              <figure key={a.id} style={{ margin: 0 }}>
                <img src={url(`trade_manager/thumb/${a.id}`)} alt={a.original_name} width={140} style={{ borderRadius: 6, display: 'block' }} />
                <button type="button" className="danger" style={{ marginTop: 4 }} onClick={() => removeExisting(a)}>Remove</button>
              </figure>
            ))}
            {data.screenshots.map((f, i) => (
              <figure key={previews[i]} style={{ margin: 0 }}>
                <img src={previews[i]} alt={f.name} width={140} style={{ borderRadius: 6, display: 'block', outline: '2px solid #6366f1' }} />
                <button type="button" className="alt" style={{ marginTop: 4 }}
                  onClick={() => setData('screenshots', data.screenshots.filter((_, j) => j !== i))}>Discard</button>
              </figure>
            ))}
          </div>
        )}

        {form.progress && <progress value={form.progress.percentage} max="100" style={{ width: '100%' }} />}

        <button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : editing ? 'Save changes' : 'Add trade'}</button>{' '}
        <Link href={url(editing ? `trade_manager/show/${trade.id}` : 'trade_manager')} className="button alt">Cancel</Link>
      </form>
    </>
  );
}
