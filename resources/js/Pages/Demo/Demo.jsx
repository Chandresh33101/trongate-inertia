import { useEffect, useState } from 'react';
import { Deferred, Head, router, useForm, usePoll, WhenVisible } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { url } from '../../lib/url';

const Box = ({ title, children }) => (
  <div className="card" style={{ marginBottom: '1rem' }}>
    <div className="card-heading">{title}</div>
    <div className="card-body">{children}</div>
  </div>
);

export default function Demo({ server_time, items, page, has_more, lazy_total, visible_info, stats, slow_stats }) {
  // ---- Polling (usePoll) ----
  const [polling, setPolling] = useState(false);
  const { start, stop } = usePoll(2000, { only: ['server_time'] }, { autoStart: false });
  const togglePoll = () => { polling ? stop() : start(); setPolling(!polling); };

  // ---- Error-bag form ----
  const newsletter = useForm({ email: '' });
  const subscribe = (e) => {
    e.preventDefault();
    newsletter.post(url('demo/subscribe'), {
      errorBag: 'newsletter',
      preserveScroll: true,
      onSuccess: () => newsletter.reset(),
    });
  };

  // ---- Router events log ----
  const [log, setLog] = useState([]);
  useEffect(() => {
    const push = (msg) => setLog((l) => [`${new Date().toLocaleTimeString()} ${msg}`, ...l].slice(0, 8));
    const offs = [
      router.on('start',  (e) => push(`start  ${e.detail.visit.method.toUpperCase()} ${e.detail.visit.url.pathname}`)),
      router.on('finish', (e) => push(`finish ${e.detail.visit.url.pathname}`)),
    ];
    return () => offs.forEach((off) => off());
  }, []);

  // ---- Merge props (load more / reset) ----
  const loadMore = () =>
    router.reload({ data: { page: page + 1 }, only: ['items', 'page', 'has_more'], preserveScroll: true });
  const resetList = () =>
    router.reload({ data: { page: 1 }, only: ['items', 'page', 'has_more'], reset: ['items'], preserveScroll: true });

  return (
    <>
      <Head title="Playground" />
      <h1>Playground</h1>

      <Box title="1. Polling (usePoll) + always prop">
        <p>Server time: <b>{server_time}</b></p>
        <button onClick={togglePoll}>{polling ? 'Stop polling' : 'Start polling (2s)'}</button>
      </Box>

      <Box title="2. Optional prop (partial reload)">
        <p>lazy_total: <b>{lazy_total ?? 'not loaded'}</b></p>
        <button onClick={() => router.reload({ only: ['lazy_total'] })}>Load lazy_total</button>
      </Box>

      <Box title="3. Deferred props">
        <Deferred data="stats" fallback={<p>Loading stats…</p>}>
          <p>Rows: <b>{stats?.rows}</b> (generated {stats?.generated})</p>
        </Deferred>
        <Deferred data="slow_stats" fallback={<p>Loading slow stats…</p>}>
          <p>{slow_stats?.message} ({slow_stats?.generated})</p>
        </Deferred>
      </Box>

      <Box title="4. Merge props (load more)">
        <ul>{items.map((i) => <li key={i.id}>#{i.id} {i.name}</li>)}</ul>
        <p>Page {page}</p>
        {has_more && <button onClick={loadMore}>Load more</button>}{' '}
        <button className="alt" onClick={resetList}>Reset list</button>
      </Box>

      <Box title="5. Error bag (second form on the page)">
        <form onSubmit={subscribe}>
          <input
            type="text"
            value={newsletter.data.email}
            onChange={(e) => newsletter.setData('email', e.target.value)}
            placeholder="email@example.com"
          />
          {newsletter.errors.email && <p style={{ color: 'red' }}>{newsletter.errors.email}</p>}
          <button type="submit" disabled={newsletter.processing}>Subscribe</button>
        </form>
      </Box>

      <Box title="6. Programmatic visits + events">
        <button onClick={() => router.visit(url('demo/about'))}>router.visit → About</button>{' '}
        <button onClick={() => router.post(url('demo/ping'), {}, { preserveScroll: true })}>router.post → ping</button>
        <pre style={{ fontSize: '.8em', marginTop: '.8rem' }}>{log.join('\n') || 'events appear here…'}</pre>
      </Box>

      <div style={{ height: '60vh' }}>↓ scroll down: the next block loads when visible ↓</div>

      <Box title="7. WhenVisible (lazy on scroll)">
        <WhenVisible data="visible_info" fallback={<p>Loading when visible…</p>}>
          <p>{visible_info?.note} (loaded at {visible_info?.loaded_at})</p>
        </WhenVisible>
      </Box>
    </>
  );
}

// Demo.layout = (page) => <AppLayout>{page}</AppLayout>;