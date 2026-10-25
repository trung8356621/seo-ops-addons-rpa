import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function resolveClientPublic() {
  const candidates = [
    'D:/work/omnichannel-client/public',
    path.resolve(__dirname, '../../public'),
    path.resolve(__dirname, '../../omnichannel-client/public'),
    path.resolve(__dirname, '../../../omnichannel-client/public'),
  ];

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }

  return candidates[0];
}

const clientPublic = resolveClientPublic();
const buildDirectory = 'build-social-automation';
const hotFile = path.join(clientPublic, 'hot-social-automation');
const entrypoints = [
  'resources/js/action-tags/index.tsx',
  'resources/css/action-tags.css',
];

/**
 * Windows junction builds can emit long relative manifest keys.
 * Rewrite them back to short entrypoint names SocialAutomationVite expects.
 */
function normalizeManifestKeys() {
  return {
    name: 'normalize-social-automation-manifest-keys',
    closeBundle() {
      const manifestPath = path.join(clientPublic, buildDirectory, 'manifest.json');
      if (!fs.existsSync(manifestPath)) {
        return;
      }

      const raw = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
      const next = { ...raw };

      for (const entry of entrypoints) {
        if (next[entry]) {
          continue;
        }

        const matchKey = Object.keys(next).find((key) => {
          const src = String(next[key]?.src ?? key).replace(/\\/g, '/');
          return src === entry || src.endsWith(`/${entry}`);
        });

        if (!matchKey) {
          continue;
        }

        next[entry] = { ...next[matchKey], src: entry };
        delete next[matchKey];
      }

      fs.writeFileSync(manifestPath, `${JSON.stringify(next, null, 2)}\n`);

      // Also copy build assets into resources/dist inside the addon repo for standalone portability
      const localDist = path.resolve(__dirname, 'resources/dist');
      if (!fs.existsSync(localDist)) {
        fs.mkdirSync(localDist, { recursive: true });
      }
      const outFiles = fs.readdirSync(path.join(clientPublic, buildDirectory));
      for (const file of outFiles) {
        const srcPath = path.join(clientPublic, buildDirectory, file);
        const destPath = path.join(localDist, file);
        if (fs.lstatSync(srcPath).isDirectory()) {
          fs.cpSync(srcPath, destPath, { recursive: true, force: true });
        } else {
          fs.copyFileSync(srcPath, destPath);
        }
      }
    },
  };
}

export default defineConfig({
  root: __dirname,
  server: {
    port: 5175,
    strictPort: true,
    origin: 'http://127.0.0.1:5175',
    fs: {
      allow: [
        __dirname,
        path.resolve(__dirname, '..'),
        path.resolve(__dirname, '../..'),
        'D:/work/omnichannel-client',
      ],
    },
  },
  plugins: [
    tailwindcss(),
    laravel({
      input: entrypoints,
      publicDirectory: clientPublic,
      buildDirectory,
      hotFile,
      refresh: false,
    }),
    react(),
    normalizeManifestKeys(),
  ],
  build: {
    manifest: 'manifest.json',
    outDir: path.join(clientPublic, buildDirectory),
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
  resolve: {
    dedupe: ['react', 'react-dom'],
    alias: {
      react: 'D:/work/omnichannel-client/node_modules/react',
      'react-dom': 'D:/work/omnichannel-client/node_modules/react-dom',
    },
  },
});
