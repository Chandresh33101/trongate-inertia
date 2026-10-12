import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import fs from 'node:fs';
import path from 'node:path';

// Writes public/hot while the dev server runs so PHP knows to use it.
function hotFile() {
  const file = path.resolve('public/hot');
  const remove = () => { try { fs.unlinkSync(file); } catch {} };
  return {
    name: 'trongate-hot-file',
    apply: 'serve',
    configureServer(server) {
      server.httpServer?.once('listening', () => {
        const { port } = server.httpServer.address();
        const proto = server.config.server.https ? 'https' : 'http';
        fs.writeFileSync(file, `${proto}://localhost:${port}`);
      });
      server.httpServer?.once('close', remove);
      process.on('exit', remove);
      for (const sig of ['SIGINT', 'SIGTERM']) {
        process.on(sig, () => { remove(); process.exit(); });
      }
    },
  };
}

export default defineConfig(({ command }) => ({
  base: command === 'build' ? './' : '/',
  plugins: [
    react(),
    hotFile(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './resources/js'),
    },
  },
  server: { host: 'localhost', port: 5173, strictPort: true, cors: true },
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: 'resources/js/app.jsx',
      output: {
        manualChunks(id) {
          if (id.includes('node_modules')) {
            return 'trongate-' + id.split('/').slice(-2).join('-');
          }
        }
      }
    }
  },
  // build: {
  //   outDir: 'public/build',
  //   emptyOutDir: true,
  //   manifest: true,
  //   rollupOptions: { 
  //     input: 'resources/js/app.jsx',
  //     output: {
  //       // Splits third-party libraries out of your app chunk
  //       manualChunks(id) {
  //         if (id.includes('node_modules')) {
  //           // Group major dependencies together
  //           if (id.includes('react')) return 'trongate-react';
  //           if (id.includes('react-dom')) return 'trongate-react-dom';
  //           if (id.includes('@inertiajs')) return 'trongate-inertia';
  //           return 'trongate'; // Everything else in node_modules
  //         }
  //       }
  //     }
  //   },
  // },
}));