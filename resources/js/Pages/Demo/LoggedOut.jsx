import { Head, Link } from '@inertiajs/react';
import { url } from '../../lib/url';

export default function LoggedOut({ message }) {
  return (
    <>
      <Head title="Logged Out" />
      <h1>Logged Out</h1>
      <p>{message}</p>
      <p>
        Try clicking the browser's back button—you will not be able to see the previous page content,
        because the history encryption key has been rotated.
      </p>
      <Link href={url('demo')}>← Back to Demo</Link>
    </>
  );
}