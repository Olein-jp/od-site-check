import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// タグと配布物のバージョンが一致しない場合は公開を止める。
const tag = process.argv[2];
assert.match(tag ?? '', /^v\d+\.\d+\.\d+$/, 'リリースタグは vX.Y.Z 形式で指定してください。');
const version = tag.slice(1);
const root = new URL('../', import.meta.url);
const read = (file) => readFileSync(new URL(file, root), 'utf8');
const plugin = read('od-site-check.php');
const versions = {
  'プラグインヘッダー': plugin.match(/^ \* Version:\s*(\S+)/m)?.[1],
  'ODSC_VERSION': plugin.match(/define\( 'ODSC_VERSION', '([^']+)' \)/)?.[1],
  'readme.txt': read('readme.txt').match(/^Stable tag:\s*(\S+)/m)?.[1],
  'package.json': JSON.parse(read('package.json')).version,
  'package-lock.json': JSON.parse(read('package-lock.json')).version,
  'package-lock.json のルート': JSON.parse(read('package-lock.json')).packages[''].version,
};

for (const [source, actual] of Object.entries(versions)) {
  assert.equal(actual, version, `${source} のバージョンがタグと一致しません。`);
}
console.log(`リリース対象 ${tag} のバージョン表記を確認しました。`);
