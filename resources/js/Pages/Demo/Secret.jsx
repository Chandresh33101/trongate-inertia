import { Head } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function Secret({ message }) {
  return (
    <>
      <Head title="Secret" />
      <h1>Secret</h1>
      <p>{message}</p>
    </>
  );
}

// Secret.layout = (page) => <AppLayout>{page}</AppLayout>;