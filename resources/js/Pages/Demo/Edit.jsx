import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { url } from '../../lib/url';

export default function Edit({ record }) {
  const form = useForm({ name: record.name });

  const submit = (e) => {
    e.preventDefault();
    form.put(url(`demo/update/${record.id}`)); // real PUT, handled by the interceptor
  };
  /*const submit = (e) => {
    e.preventDefault();
    form.post(url(`demo/update/${record.id}`), {
      preserveState: false,
      onSuccess: () => form.reset(),
    });
  };*/

  return (
    <>
      <Head title={`Edit #${record.id}`} />
      <h1>Edit #{record.id}</h1>

      <form onSubmit={submit}>
        <input
          value={form.data.name}
          onChange={(e) => form.setData('name', e.target.value)}
          autoComplete="off"
        />
        {form.errors.name && <p style={{ color: 'red' }}>{form.errors.name}</p>}

        <button type="submit" disabled={!form.isDirty || form.processing}>Save</button>{' '}
        <button type="button" className="alt" onClick={() => form.reset()} disabled={!form.isDirty}>
          Reset
        </button>{' '}
        <Link href={url('demo')} className="button alt">Cancel</Link>
      </form>
    </>
  );
}

// Edit.layout = (page) => <AppLayout>{page}</AppLayout>;