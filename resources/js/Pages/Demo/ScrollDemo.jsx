import { Head, InfiniteScroll } from '@inertiajs/react';

export default function ScrollDemo({ users }) {
  return (
    <>
      <Head title="Infinite Scroll" />
      <h1>Infinite Scroll Demo</h1>

      <InfiniteScroll
        data="users"
        buffer={0}
        style={{
          height: '400px',
          overflowY: 'auto',
          border: '1px solid #ccc',
          padding: '1rem',
        }}
      >
        {users.data.map((user) => (
          <div
            key={user.id}
            style={{
              padding: '1.5rem 0',       /* taller items */
              borderBottom: '1px solid #eee',
              minHeight: '80px',         /* ensure overflow */
            }}
          >
            #{user.id} — {user.name}
          </div>
        ))}
      </InfiniteScroll>

      <p style={{ marginTop: '1rem', color: '#666', fontSize: '.9em' }}>
        Scroll inside the box to load more. Total: {users.meta?.total ?? '?'}
      </p>
    </>
  );
}