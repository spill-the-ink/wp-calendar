import fs from 'node:fs';
import path from 'node:path';

const rootDir = process.cwd();
const pluginSlug = 'wp-calendar';
const textDomain = 'wp-calendar';
const projectName = 'WordPress Calendar';
const languagesDir = path.join(rootDir, 'languages');
const outputPath = path.join(languagesDir, `${pluginSlug}.pot`);

const phpFunctions = new Set(['__', 'esc_html__', 'esc_attr__']);

function escapePoString(value) {
  return value
    .replace(/\\/g, '\\\\')
    .replace(/"/g, '\\"')
    .replace(/\r/g, '')
    .replace(/\n/g, '\\n');
}

function unescapePhpSingleQuoted(value) {
  return value
    .replace(/\\'/g, "'")
    .replace(/\\\\/g, '\\');
}

function walkPhpFiles(directory) {
  const entries = fs.readdirSync(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    if (entry.name === '.git' || entry.name === 'node_modules' || entry.name === '.release' || entry.name === 'dist' || entry.name === 'languages') {
      continue;
    }

    const fullPath = path.join(directory, entry.name);

    if (entry.isDirectory()) {
      files.push(...walkPhpFiles(fullPath));
      continue;
    }

    if (entry.isFile() && entry.name.endsWith('.php')) {
      files.push(fullPath);
    }
  }

  return files;
}

function lineNumberAt(content, index) {
  return content.slice(0, index).split('\n').length;
}

function addReference(entryMap, key, reference, values) {
  if (!entryMap.has(key)) {
    entryMap.set(key, {
      ...values,
      references: new Set(),
    });
  }

  entryMap.get(key).references.add(reference);
}

function extractTranslatorComment(content, matchIndex) {
  const before = content.slice(0, matchIndex);
  const blocks = before.match(/\/\*[\s\S]*?\*\//g);

  if (!blocks || blocks.length === 0) {
    return '';
  }

  const lastBlock = blocks[blocks.length - 1];
  const lastIndex = before.lastIndexOf(lastBlock);
  const gap = before.slice(lastIndex + lastBlock.length);

  if (gap.trim() !== '') {
    return '';
  }

  const commentMatch = lastBlock.match(/translators:\s*([\s\S]*?)\*\//i);

  if (!commentMatch) {
    return '';
  }

  return commentMatch[1]
    .split('\n')
    .map((line) => line.replace(/^\s*\*\s?/, '').trim())
    .filter(Boolean)
    .join(' ')
    .replace(/\s*\*\/$/, '')
    .trim();
}

function collectEntries(filePath, content, entries) {
  const relativePath = path.relative(rootDir, filePath).replace(/\\/g, '/');
  const simplePattern = /(esc_html__|esc_attr__|__)\(\s*'((?:\\'|[^'])*)'\s*,\s*'wp-calendar'\s*\)/gms;
  const pluralPattern = /_n\(\s*'((?:\\'|[^'])*)'\s*,\s*'((?:\\'|[^'])*)'\s*,[\s\S]*?'wp-calendar'\s*\)/gms;

  for (const match of content.matchAll(simplePattern)) {
    const functionName = match[1];

    if (!phpFunctions.has(functionName)) {
      continue;
    }

    const msgid = unescapePhpSingleQuoted(match[2]);
    const line = lineNumberAt(content, match.index);
    const comment = extractTranslatorComment(content, match.index);
    const key = `single:${msgid}`;

    addReference(entries, key, `${relativePath}:${line}`, {
      type: 'single',
      msgid,
      comment,
    });
  }

  for (const match of content.matchAll(pluralPattern)) {
    const msgid = unescapePhpSingleQuoted(match[1]);
    const msgidPlural = unescapePhpSingleQuoted(match[2]);
    const line = lineNumberAt(content, match.index);
    const comment = extractTranslatorComment(content, match.index);
    const key = `plural:${msgid}:${msgidPlural}`;

    addReference(entries, key, `${relativePath}:${line}`, {
      type: 'plural',
      msgid,
      msgidPlural,
      comment,
    });
  }
}

function renderHeader() {
  const now = new Date().toISOString().replace('T', ' ').slice(0, 19) + '+0000';

  return [
    'msgid ""',
    'msgstr ""',
    `"Project-Id-Version: ${projectName}\\n"`,
    '"Report-Msgid-Bugs-To: \\n"',
    `"POT-Creation-Date: ${now}\\n"`,
    '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"',
    '"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"',
    '"Language-Team: LANGUAGE <LL@li.org>\\n"',
    '"MIME-Version: 1.0\\n"',
    '"Content-Type: text/plain; charset=UTF-8\\n"',
    '"Content-Transfer-Encoding: 8bit\\n"',
    '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
    '"X-Domain: wp-calendar\\n"',
    '',
  ].join('\n');
}

function renderEntry(entry) {
  const lines = [];
  const references = Array.from(entry.references).sort();

  if (entry.comment) {
    lines.push(`#. translators: ${entry.comment}`);
  }

  lines.push(`#: ${references.join(' ')}`);
  lines.push(`msgid "${escapePoString(entry.msgid)}"`);

  if (entry.type === 'plural') {
    lines.push(`msgid_plural "${escapePoString(entry.msgidPlural)}"`);
    lines.push('msgstr[0] ""');
    lines.push('msgstr[1] ""');
  } else {
    lines.push('msgstr ""');
  }

  lines.push('');

  return lines.join('\n');
}

fs.mkdirSync(languagesDir, { recursive: true });

const entries = new Map();
const phpFiles = walkPhpFiles(rootDir);

for (const filePath of phpFiles) {
  const content = fs.readFileSync(filePath, 'utf8');
  collectEntries(filePath, content, entries);
}

const sortedEntries = Array.from(entries.values()).sort((left, right) => left.msgid.localeCompare(right.msgid));
const output = [renderHeader(), ...sortedEntries.map(renderEntry)].join('\n');

fs.writeFileSync(outputPath, output, 'utf8');
process.stdout.write(`Created ${outputPath}\n`);