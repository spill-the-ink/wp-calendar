import fs from 'node:fs';
import path from 'node:path';

const rootDir = process.cwd();
const languagesDir = path.join(rootDir, 'languages');

function unescapePoString(value) {
  return value
    .replace(/\\n/g, '\n')
    .replace(/\\r/g, '\r')
    .replace(/\\t/g, '\t')
    .replace(/\\"/g, '"')
    .replace(/\\\\/g, '\\');
}

function parsePoFile(content) {
  const entries = [];
  let currentEntry = null;
  let activeField = null;
  let activeIndex = null;

  function ensureEntry() {
    if (!currentEntry) {
      currentEntry = {
        msgid: '',
        msgidPlural: null,
        msgstr: [],
      };
    }

    return currentEntry;
  }

  function flushEntry() {
    if (!currentEntry) {
      return;
    }

    entries.push(currentEntry);
    currentEntry = null;
    activeField = null;
    activeIndex = null;
  }

  for (const rawLine of content.split(/\r?\n/)) {
    const line = rawLine.trim();

    if (line === '') {
      flushEntry();
      continue;
    }

    if (line.startsWith('#')) {
      continue;
    }

    let match = line.match(/^msgid\s+"([\s\S]*)"$/);

    if (match) {
      const entry = ensureEntry();
      entry.msgid = unescapePoString(match[1]);
      activeField = 'msgid';
      activeIndex = null;
      continue;
    }

    match = line.match(/^msgid_plural\s+"([\s\S]*)"$/);

    if (match) {
      const entry = ensureEntry();
      entry.msgidPlural = unescapePoString(match[1]);
      activeField = 'msgidPlural';
      activeIndex = null;
      continue;
    }

    match = line.match(/^msgstr(?:\[(\d+)\])?\s+"([\s\S]*)"$/);

    if (match) {
      const entry = ensureEntry();
      const index = match[1] ? Number.parseInt(match[1], 10) : 0;
      entry.msgstr[index] = unescapePoString(match[2]);
      activeField = 'msgstr';
      activeIndex = index;
      continue;
    }

    match = line.match(/^"([\s\S]*)"$/);

    if (match && currentEntry && activeField) {
      const value = unescapePoString(match[1]);

      if (activeField === 'msgid') {
        currentEntry.msgid += value;
      } else if (activeField === 'msgidPlural') {
        currentEntry.msgidPlural = (currentEntry.msgidPlural ?? '') + value;
      } else if (activeField === 'msgstr' && activeIndex !== null) {
        currentEntry.msgstr[activeIndex] = (currentEntry.msgstr[activeIndex] ?? '') + value;
      }
    }
  }

  flushEntry();

  return entries;
}

function buildMoBuffer(entries) {
  const normalizedEntries = entries.map((entry) => {
    const original = entry.msgidPlural ? `${entry.msgid}\u0000${entry.msgidPlural}` : entry.msgid;
    const translation = entry.msgidPlural ? entry.msgstr.join('\u0000') : (entry.msgstr[0] ?? '');

    return {
      original,
      translation,
    };
  }).sort((left, right) => left.original.localeCompare(right.original));

  const originalBuffers = normalizedEntries.map((entry) => Buffer.from(entry.original, 'utf8'));
  const translationBuffers = normalizedEntries.map((entry) => Buffer.from(entry.translation, 'utf8'));
  const entryCount = normalizedEntries.length;
  const headerSize = 28;
  const tableSize = entryCount * 8;
  const originalsTableOffset = headerSize;
  const translationsTableOffset = originalsTableOffset + tableSize;
  let stringOffset = translationsTableOffset + tableSize;
  const originalTable = Buffer.alloc(tableSize);
  const translationTable = Buffer.alloc(tableSize);
  const stringChunks = [];

  for (let index = 0; index < entryCount; index += 1) {
    const originalBuffer = originalBuffers[index];
    originalTable.writeUInt32LE(originalBuffer.length, index * 8);
    originalTable.writeUInt32LE(stringOffset, index * 8 + 4);
    stringChunks.push(originalBuffer, Buffer.from([0]));
    stringOffset += originalBuffer.length + 1;
  }

  for (let index = 0; index < entryCount; index += 1) {
    const translationBuffer = translationBuffers[index];
    translationTable.writeUInt32LE(translationBuffer.length, index * 8);
    translationTable.writeUInt32LE(stringOffset, index * 8 + 4);
    stringChunks.push(translationBuffer, Buffer.from([0]));
    stringOffset += translationBuffer.length + 1;
  }

  const header = Buffer.alloc(headerSize);
  header.writeUInt32LE(0x950412de, 0);
  header.writeUInt32LE(0, 4);
  header.writeUInt32LE(entryCount, 8);
  header.writeUInt32LE(originalsTableOffset, 12);
  header.writeUInt32LE(translationsTableOffset, 16);
  header.writeUInt32LE(0, 20);
  header.writeUInt32LE(0, 24);

  return Buffer.concat([header, originalTable, translationTable, ...stringChunks]);
}

function compilePoToMo(poPath) {
  const moPath = poPath.replace(/\.po$/i, '.mo');
  const content = fs.readFileSync(poPath, 'utf8');
  const entries = parsePoFile(content);
  const buffer = buildMoBuffer(entries);

  fs.writeFileSync(moPath, buffer);
  process.stdout.write(`Created ${moPath}\n`);
}

if (!fs.existsSync(languagesDir)) {
  process.exit(0);
}

const languageFiles = fs.readdirSync(languagesDir)
  .filter((fileName) => fileName.endsWith('.po'))
  .filter((fileName) => fileName !== 'wp-calendar.pot');

for (const fileName of languageFiles) {
  compilePoToMo(path.join(languagesDir, fileName));
}