import { useEffect, useRef, useState } from 'react';
import { Deferred, Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { url } from '../../lib/url';

export default function Index({ records, total, page, per_page, filters }) {
  const [q, setQ] = useState(filters.q ?? '');
  const first = useRef(true);

  // useForm with a key => form state survives back/forward navigation
  const form = useForm('Demo/Create', { name: '' });

  // Debounced search: GET + preserveState + replace history entry
  useEffect(() => {
    if (first.current) { first.current = false; return; }
    const t = setTimeout(() => {
      router.get(url('demo'), q ? { q } : {}, {
        preserveState: true,
        replace: true,
        only: ['records', 'total', 'page', 'filters'], // partial reload
      });
    }, 300);
    return () => clearTimeout(t);
  }, [q]);

  const submit = (e) => {
    e.preventDefault();
    form.transform((d) => ({ ...d, name: d.name.trim() }));
    form.post(url('demo/create'), {
      preserveScroll: true,
      onSuccess: () => form.reset(),
    });
  };

  const remove = (id) =>
    router.delete(url(`demo/delete/${id}`), {
      preserveScroll: true,
      onBefore: () => confirm('Delete this record?'), // return false cancels
    });

  const pages = Math.max(1, Math.ceil(total / per_page));
  const params = (p) => ({ ...(filters.q ? { q: filters.q } : {}), ...(p > 1 ? { page: p } : {}) });

  return (
    <>
      <Head title="Records" />
      <h1>Records</h1>

      <input
        type="search"
        value={q}
        onChange={(e) => setQ(e.target.value)}
        placeholder="Search (debounced GET, partial reload)"
        autoComplete="off"
      />
    <Deferred data="records" fallback={<p>Loading Records..</p>}>
      <ul>
        {records?.map((r) => (
          <li key={r.id}>
            {r.name}{' '}
            <Link href={url(`demo/edit/${r.id}`)} prefetch cacheFor={['0s', '1m']}>Edit</Link>{' '}
            <button className="danger" onClick={() => remove(r.id)}>Delete</button>
          </li>
        ))}
        {records?.length === 0 && <li>No records found.</li>}
      </ul>

      <p>
        {page > 1 && <Link href={url('demo')} data={params(page - 1)} preserveState>« Prev</Link>}{' '}
        Page {page} / {pages} ({total} total){' '}
        {page < pages && <Link href={url('demo')} data={params(page + 1)} preserveState>Next »</Link>}
      </p>
    </Deferred>
      <form onSubmit={submit}>
        <input
          value={form.data.name}
          onChange={(e) => form.setData('name', e.target.value)}
          placeholder="Name"
          autocomplete="off"
        />
        {form.errors.name && <p style={{ color: 'red' }}>{form.errors.name}</p>}
        <button type="submit" disabled={form.processing}>
          {form.processing ? 'Saving…' : 'Add'}
        </button>
      </form>
    </>
  );
}

// Persistent layout: stays mounted between page visits
// Index.layout = (page) => <AppLayout>{page}</AppLayout>;