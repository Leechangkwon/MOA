'use strict';
// Run the actual app.js return-path implementation against the shared PHP/JS vectors.
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/assets/app.js'), 'utf8');
const begin = source.indexOf('var RETURN_PATH_MAX =');
const end = source.indexOf('function loginDestination()', begin);
if (begin < 0 || end < 0) throw Error('Return path implementation boundary missing');
const context = { TextEncoder, TextDecoder };
vm.createContext(context);
vm.runInContext(source.slice(begin, end), context);
let count = 0;
for (const line of fs.readFileSync(path.join(root, 'tests/lib/return_path_vectors.txt'), 'utf8').split('\n')) {
  if (!line || line.startsWith('#')) continue;
  const [raw, expected] = line.split('\t');
  const actual = context.returnPath(raw);
  if (actual !== (expected === '-' ? null : expected)) throw Error(`Return path mismatch at vector ${count + 1}`);
  count++;
}
console.log(`PASS: ${count} real app.js return-path vectors`);
