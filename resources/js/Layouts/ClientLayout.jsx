import { Link, usePage } from '@inertiajs/react';
import { url } from '../lib/url';

export default function ClientLayout({ children }) {
  const { flash, app, year } = usePage().props;

  return (
    <div className="container text-left">
      <nav style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'center', marginBottom: '1rem' }}>
        <strong>{app.name}</strong>
        <Link href={url('trade_manager')} prefetch>Journal</Link>
        <Link href={url('trade_manager/create')}>+ New trade</Link>
        {/* Plain link on purpose: logout is a full page load that clears the session */}
        <a href={url('login/logout')} style={{ marginLeft: 'auto' }}>Log out</a>
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
