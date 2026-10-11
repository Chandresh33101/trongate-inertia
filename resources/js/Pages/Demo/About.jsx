import { Link, router, useForm, usePage } from '@inertiajs/react';
import { url } from '@/lib/url';

export default function About() {

  return (
    <div className="container text-left">
      <h1>Demo About</h1>

      <p><Link href={url('demo')}>Demo Main (separate chunk)</Link></p>
      <p><Link href={url('demo/demo')}>Demo Page (separate chunk)</Link></p>
    </div>
  );
}