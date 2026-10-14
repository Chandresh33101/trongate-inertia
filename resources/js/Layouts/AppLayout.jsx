import { Link, usePage } from '@inertiajs/react';
import { url } from '../lib/url';

export default function AppLayout({ children }) {
  const { flash, app, year, auth } = usePage().props;

  return (
    <div className="container text-left">
      <nav style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'center', marginBottom: '1rem' }}>
        <strong>{app.name}</strong>
        <Link href={url('demo')} prefetch>Records</Link>
        <Link href={url('demo/demo')}>Playground</Link>
        {/* <Link href={url('demo/scroll_demo')}>Scroll Demo</Link> */}
        <Link href={url('demo/about')} prefetch>About (prefetch)</Link>
        <Link href={url('demo/secret')}>Secret (admin)</Link>
        <Link href={url('demo/ping')} method="post" as="button" preserveScroll>POST link</Link>
        <Link href={url('demo/go_external')}>External redirect</Link>
        <span style={{ marginLeft: 'auto', fontSize: '.85em' }}>
          {auth.logged_in ? 'admin: logged in' : 'admin: guest'}
        </span>
      </nav>

      {flash?.message && (
        <p style={{ color: 'green', background: '#e9f9ee', padding: '.5em .8em' }}>{flash.message}</p>
      )}

      {children}

      <footer style={{ marginTop: '2rem', fontSize: '.8em', opacity: 0.7 }}>
        &copy; {year} {app.name}
      </footer>
    </div>
  );
}