import { resolve } from 'node:path';
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode, command }) => {
  const isAdminBuild = mode === 'admin';
  const isSettingsBuild = mode === 'settings';
  const env = loadEnv(mode, process.cwd(), '');

  return {
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      'process.env': '{}',
    },
    plugins: [react()],
    build: {
      outDir: 'dist',
      emptyOutDir: !isAdminBuild && !isSettingsBuild,
      sourcemap: true,
      lib: {
        entry: resolve(__dirname, isAdminBuild ? 'src/admin.tsx' : isSettingsBuild ? 'src/settings.tsx' : 'src/main.tsx'),
        name: isAdminBuild ? 'WpCalendarAdmin' : isSettingsBuild ? 'WpCalendarSettings' : 'WpCalendarApp',
        formats: ['iife'],
        fileName: () => (isAdminBuild ? 'wp-calendar-admin.js' : isSettingsBuild ? 'wp-calendar-settings.js' : 'wp-calendar.js'),
        cssFileName: isAdminBuild ? 'wp-calendar-admin' : isSettingsBuild ? 'wp-calendar-settings' : 'wp-calendar',
      },
      rollupOptions: {
        output: {
          intro: "var process = globalThis.process || { env: { NODE_ENV: 'production' } };",
        },
      },
    },
    server: command === 'serve' ? {
      proxy: buildApiProxy(env.PC_REMOTE_URL),
    } : undefined,
  };
});

/**
 * Proxy WordPress REST calls to a remote install so the local Vite app can
 * develop against real data without deploying plugin changes.
 */
function buildApiProxy(remoteUrl) {
  if (!remoteUrl) {
    return undefined;
  }

  return {
    '/wp-json/wp-calendar/v1': {
      target: remoteUrl,
      changeOrigin: true,
      secure: false,
    },
  };
}
