import { useEffect, useState } from 'react';
import {
  Deferred,
  Head,
  router,
  useForm,
  usePage,
  usePoll,
  WhenVisible,
  InfiniteScroll,
  Link,
} from '@inertiajs/react';
import { url } from '../../lib/url';

const Box = ({ title, children }) => (
  <div className="card" style={{ marginBottom: '1rem' }}>
    <div className="card-heading">{title}</div>
    <div className="card-body">{children}</div>
  </div>
);

export default function Demo({
  server_time,
  items,
  page,
  has_more,
  lazy_total,
  visible_info,
  stats,
  slow_stats,
  recent_activity,
  user_prefs,
  notifications,
  paginated,
}) {
  const { props } = usePage();
  useEffect(() => {
    console.log(paginated);
  }, [paginated]);

  // ── Polling ──────────────────────────────────────────────────────────
  const [polling, setPolling] = useState(false);
  const { start, stop } = usePoll(2000, { only: ['server_time'] }, { autoStart: false });
  const togglePoll = () => {
    polling ? stop() : start();
    setPolling(!polling);
  };

  // ── Error-bag form ───────────────────────────────────────────────────
  const newsletter = useForm({ email: '' });
  const subscribe = (e) => {
    e.preventDefault();
    newsletter.post(url('demo/subscribe'), {
      errorBag: 'newsletter',
      preserveScroll: true,
      onSuccess: () => newsletter.reset(),
    });
  };

  // ── Router event log ─────────────────────────────────────────────────
  const [log, setLog] = useState([]);
  useEffect(() => {
    const push = (msg) =>
      setLog((l) => [`${new Date().toLocaleTimeString()} ${msg}`, ...l].slice(0, 8));
    const offs = [
      router.on('start', (e) =>
        push(`start  ${e.detail.visit.method.toUpperCase()} ${e.detail.visit.url.pathname}`)
      ),
      router.on('finish', (e) => push(`finish ${e.detail.visit.url.pathname}`)),
    ];
    return () => offs.forEach((off) => off());
  }, []);

  // ── Merge props (load more / reset) ──────────────────────────────────
  const loadMore = () =>
    router.reload({
      data: { page: page + 1 },
      only: ['items', 'page', 'has_more'],
      preserveScroll: true,
    });

  const resetList = () =>
    router.reload({
      data: { page: 1 },
      only: ['items', 'page', 'has_more'],
      reset: ['items'],
      preserveScroll: true,
    });

  // ── Once prop (从 shared 中读取) ─────────────────────────────────────
  const siteConfig = props.site_config;

  return (
    <>
      <Head title="Playground">
        <meta name="description" content="Inertia adapter full feature showcase" />
        <meta head-key="og:title" property="og:title" content="Playground" />
      </Head>
      <h1>Playground — All Features</h1>

      {/* ── 0. ONCE PROP ──────────────────────────────────────────────── */}
      <Box title="0. Once Prop (cached across visits)">
        <p>
          Site config generated at: <b>{siteConfig?.generated}</b>
        </p>
        <p>
          Timezone: <b>{siteConfig?.timezone}</b>
        </p>
        <button
          onClick={() => router.reload({ only: ['site_config'] })}
          style={{ marginTop: '.5rem' }}
        >
          Force refresh once prop
        </button>
      </Box>

      {/* ── 1. POLLING + ALWAYS ───────────────────────────────────────── */}
      <Box title="1. Polling (usePoll) + always prop">
        <p>
          Server time: <b>{server_time}</b>
        </p>
        <button onClick={togglePoll}>{polling ? 'Stop polling' : 'Start polling (2s)'}</button>
      </Box>

      {/* ── 2. OPTIONAL PROP ──────────────────────────────────────────── */}
      <Box title="2. Optional prop (partial reload)">
        <p>
          lazy_total: <b>{lazy_total ?? 'not loaded'}</b>
        </p>
        <button onClick={() => router.reload({ only: ['lazy_total'] })}>Load lazy_total</button>
      </Box>

      {/* ── 3. DEFERRED PROPS ─────────────────────────────────────────── */}
      <Box title="3. Deferred props">
        <Deferred data="stats" fallback={<p>Loading stats…</p>}>
          <p>
            Rows: <b>{stats?.rows}</b> (generated {stats?.generated})
          </p>
        </Deferred>
        <Deferred data="slow_stats" fallback={<p>Loading slow stats…</p>}>
          <p>
            {slow_stats?.message} ({slow_stats?.generated})
          </p>
        </Deferred>
      </Box>

      {/* ── 4. MERGE PROPS ────────────────────────────────────────────── */}
      <Box title="4. Merge props (load more)">
        <ul>
          {items.map((i) => (
            <li key={i.id}>
              #{i.id} {i.name}
            </li>
          ))}
        </ul>
        <p>Page {page}</p>
        {has_more && <button onClick={loadMore}>Load more</button>}{' '}
        <button className="alt" onClick={resetList}>
          Reset list
        </button>
      </Box>

      {/* ── 5. PREPEND PROPS ──────────────────────────────────────────── */}
      <Box title="5. Prepend props (newest first)">
        <ul>
          {recent_activity?.map((a) => (
            <li key={a.id}>
              {a.time} — {a.action}
            </li>
          ))}
        </ul>
        <button
          onClick={() =>
            router.reload({
              only: ['recent_activity'],
              data: {},
              preserveScroll: true,
            })
          }
        >
          Add activity (prepend)
        </button>
      </Box>

      {/* ── 6. DEEP MERGE PROPS ───────────────────────────────────────── */}
      <Box title="6. Deep merge props">
        <pre>{JSON.stringify(user_prefs, null, 2)}</pre>
        <button
          onClick={() =>
            router.reload({
              only: ['user_prefs'],
              preserveScroll: true,
            })
          }
        >
          Refresh prefs (deep merge)
        </button>
      </Box>

      {/* ── 7. MATCH ON (keyed dedup) ─────────────────────────────────── */}
      <Box title="7. Match on (keyed merge)">
        <ul>
          {notifications?.map((n) => (
            <li key={n.id}>
              [{n.id}] {n.text}
            </li>
          ))}
        </ul>
        <p style={{ fontSize: '.85em', color: '#666' }}>
          Items are deduplicated by <code>id</code> on partial reloads.
        </p>
      </Box>

      {/* ── 8. PAGINATION HELPER ──────────────────────────────────────── */}
      <Box title="8. Pagination helper">
        <p>
          Page {paginated?.meta?.current_page} of {paginated?.meta?.last_page} — Total:{' '}
          {paginated?.meta?.total}
        </p>
        <div style={{ display: 'flex', gap: '.5rem' }}>
          {paginated?.links?.prev && (
            <Link href={paginated.links.prev} preserveScroll preserveState>
              ← Prev
            </Link>
          )}
          {/* {paginated?.links?.prev && (
            <button onClick={() => router.visit(paginated.links.prev)}>← Prev</button>
          )} */}
          {paginated?.links?.next && (
            <Link href={paginated.links.next} preserveScroll preserveState>
              Next →
            </Link>
          )}
        </div>
      </Box>

      {/* ── 9. ERROR BAG ──────────────────────────────────────────────── */}
      <Box title="9. Error bag (second form)">
        <form onSubmit={subscribe}>
          <input
            type="text"
            value={newsletter.data.email}
            onChange={(e) => newsletter.setData('email', e.target.value)}
            placeholder="email@example.com"
          />
          {newsletter.errors.email && (
            <p style={{ color: 'red' }}>{newsletter.errors.email}</p>
          )}
          <button type="submit" disabled={newsletter.processing}>
            Subscribe
          </button>
        </form>
      </Box>

      {/* ── 10. ROUTER + EVENTS ───────────────────────────────────────── */}
      <Box title="10. Programmatic visits + events">
        <button onClick={() => router.visit(url('demo/about'))}>router.visit → About</button>{' '}
        <button onClick={() => router.post(url('demo/ping'), {}, { preserveScroll: true })}>
          router.post → ping
        </button>{' '}
        <button onClick={() => router.visit(url('demo/logout_demo'))}>Clear history</button>{' '}
        <button onClick={() => router.visit(url('demo/redirect_with_fragment') + '#section-2')}>
          Redirect with fragment
        </button>
        <pre style={{ fontSize: '.8em', marginTop: '.8rem' }}>
          {log.join('\n') || 'events appear here…'}
        </pre>
      </Box>

      <div style={{ height: '60vh' }}>
        ↓ scroll down: the next block loads when visible ↓
      </div>

      {/* ── 11. WHEN VISIBLE ──────────────────────────────────────────── */}
      <Box title="11. WhenVisible (lazy on scroll)">
        <WhenVisible data="visible_info" fallback={<p>Loading when visible…</p>}>
          <p>
            {visible_info?.note} (loaded at {visible_info?.loaded_at})
          </p>
        </WhenVisible>
      </Box>
    </>
  );
}