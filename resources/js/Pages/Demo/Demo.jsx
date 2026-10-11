import { Link } from '@inertiajs/react';
// import { url } from '@/lib/url'; /** An Error intentionally */
export default function Demo() {

  return (
    <div className="container text-left">
      <h1>Demo Page</h1>
      <p><Link href={url('demo')}>Demo Main (separate chunk)</Link></p>
    </div>
  );
}