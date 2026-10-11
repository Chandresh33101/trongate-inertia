import { Link, router, useForm, usePage } from '@inertiajs/react';
import { url } from '../../lib/url';

export default function Index({ records }) {
  const { flash } = usePage().props;
  const form = useForm({ name: '' });

  const submit = (e) => {
    e.preventDefault();
    form.post(url('demo/create'), { onSuccess: () => form.reset() });
  };

  return (
    <div className="container text-left">
      <h1>Demo Index Page</h1>
      {flash.message && <p style={{ color: 'green' }}>{flash.message}</p>}

      <ul>
        {records.map((r) => (
          <li key={r.id}>
            {r.name}{' '}
            <button
              className="danger"
              onClick={() => router.delete(url(`demo/delete/${r.id}`), { preserveScroll: true })}
            >
              Delete
            </button>
          </li>
        ))}
      </ul>

      <form onSubmit={submit}>
        <input
          value={form.data.name}
          onChange={(e) => form.setData('name', e.target.value)}
          placeholder="Name"
          autoComplete="off"
        />
        {form.errors.name && <p style={{ color: 'red' }}>{form.errors.name}</p>}
        <button type="submit" disabled={form.processing}>Add</button>
      </form>

      <p><Link href={url('demo/about')}>About (separate chunk)</Link></p>
    </div>
  );
}