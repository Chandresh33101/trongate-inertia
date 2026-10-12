import { Head, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function About() {
  const { app, year } = usePage().props; // shared props

  return (
    <>
      <Head title="About" />
      <h1>About</h1>
      <p>This page is its own lazy-loaded chunk. Check the Network tab: it loads on first visit, or on hover thanks to <code>prefetch</code>.</p>
      <p>Shared props: <b>{app.name}</b> / {year}</p>
    </>
  );
}

// About.layout = (page) => <AppLayout>{page}</AppLayout>;