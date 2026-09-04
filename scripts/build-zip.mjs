import fs from 'node:fs';
import path from 'node:path';
import archiver from 'archiver';

const rootDir = process.cwd();
const packageJson = JSON.parse(fs.readFileSync(path.join(rootDir, 'package.json'), 'utf8'));
const pluginSlug = packageJson.name;
const releaseDir = path.join(rootDir, '.release');
const zipPath = path.join(releaseDir, `${pluginSlug}-${packageJson.version}.zip`);

fs.mkdirSync(releaseDir, { recursive: true });

if (fs.existsSync(zipPath)) {
  fs.rmSync(zipPath, { force: true });
}

const output = fs.createWriteStream(zipPath);
const archive = archiver('zip', { zlib: { level: 9 } });

const closePromise = new Promise((resolve, reject) => {
  output.on('close', resolve);
  output.on('error', reject);
  archive.on('error', reject);
});

archive.pipe(output);

[
  'wp-calendar.php',
  'README.md',
].forEach((file) => {
  archive.file(path.join(rootDir, file), { name: path.posix.join(pluginSlug, file) });
});

[
  'includes',
  'dist',
  'languages',
].forEach((directory) => {
  archive.directory(path.join(rootDir, directory), path.posix.join(pluginSlug, directory));
});

await archive.finalize();
await closePromise;

process.stdout.write(`Created ${zipPath}\n`);
