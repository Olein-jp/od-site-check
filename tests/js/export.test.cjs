const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

function harness( options = {} ) {
	const callbacks = {};
	const status = { textContent: '' };
	const preview = { value: '', focus() {}, select() {}, setSelectionRange() {} };
	const buttons = [ {}, {} ];
	let state = { valid: true, revision: 0, rows: [ [ 'H-05', 'answered', 'no' ] ] };
	const form = { action: { name: 'action' }, getAttribute: () => '/wp-admin/admin-post.php',
		elements: { odsc_payload: { value: 'base' }, odsc_signature: { value: 'base-signature' }, _odsc_prepare_nonce: { value: 'prepare' }, _odsc_download_nonce: { value: 'download' } },
		odscState: { read: () => state }, addEventListener: ( name, callback ) => { callbacks[ name ] = callback; } };
	const snapshot = { json: '{"hearing":{"answer":"日本語"}}\n', signature: 'complete-signature', filename: 'diagnostic.json' };
	const requests = [];
	const writes = [];
	let downloads = 0;
	const document = {
		getElementById: ( id ) => ( { 'odsc-export-form': form, 'odsc-json-preview': preview, 'odsc-copy-status': status, 'odsc-copy-json': buttons[ 0 ], 'odsc-download-json': buttons[ 1 ] } )[ id ],
		addEventListener: ( name, callback ) => callback(),
		createElement: () => ( { click: () => downloads++, remove() {} } ), body: { appendChild() {} },
	};
	const context = { document, window: { isSecureContext: true }, navigator: { clipboard: { writeText: async ( value ) => { if ( options.clipboardFailure ) { throw new Error( '拒否' ); } writes.push( value ); } } },
		TextEncoder, URLSearchParams, AbortController, btoa, setTimeout: ( callback ) => callback(), URL: { createObjectURL: () => 'blob:test', revokeObjectURL() {} },
		fetch: async ( url, config ) => {
			assert.equal( url, '/wp-admin/admin-post.php' );
			const data = Object.fromEntries( config.body );
			requests.push( data );
			if ( options.beforeResponse ) { await options.beforeResponse(); }
			return { ok: ! options.httpFailure, headers: { get: () => 'application/json; charset=utf-8' }, json: async () => snapshot,
				blob: async () => new Blob( [ options.wrongDownload ? '別の内容' : snapshot.json ], { type: 'application/json' } ) };
		},
	};
	vm.runInNewContext( fs.readFileSync( new URL( '../../assets/export.js', 'file://' + __filename ), 'utf8' ), context );
	return { requests, writes, preview, status, snapshot, get downloads() { return downloads; },
		export: ( kind ) => callbacks[ 'odsc:export' ]( { detail: { kind } } ),
		invalidate: () => { state = { ...state, revision: state.revision + 1 }; callbacks[ 'odsc:invalidate' ](); },
		disable: () => { state = { ...state, valid: false }; } };
}

test( 'コピーとダウンロードで同一UTF-8文字列を使い、毎回サーバーで再検証する', async () => {
	const app = harness();
	await app.export( 'copy' );
	assert.equal( app.status.textContent, 'JSONをコピーしました。' );
	await app.export( 'download' );
	assert.equal( app.downloads, 1 );
	assert.equal( app.requests.length, 3 );
	assert.equal( Buffer.from( app.requests[ 1 ].odsc_payload, 'base64' ).toString(), app.writes[ 0 ] );
	assert.equal( Buffer.from( app.requests[ 2 ].odsc_payload, 'base64' ).toString(), app.writes[ 0 ] );
	assert.equal( app.preview.value, app.writes[ 0 ] );
} );
test( '未回答時は直接呼出しでもネットワーク送信しない', async () => {
	const app = harness(); app.disable(); await app.export( 'copy' ); assert.equal( app.requests.length, 0 );
} );
test( 'クリップボード拒否とHTTP失敗は成功表示しない', async () => {
	for ( const options of [ { clipboardFailure: true }, { httpFailure: true } ] ) {
		const app = harness( options ); await app.export( 'copy' );
		assert.equal( app.writes.length, 0 ); assert.match( app.status.textContent, /出力できませんでした/ );
	}
} );
test( '回答変更で進行中の古い応答を出力しない', async () => {
	let resolve;
	const app = harness( { beforeResponse: () => new Promise( ( done ) => { resolve = done; } ) } );
	const pending = app.export( 'copy' );
	app.invalidate(); resolve(); await pending;
	assert.equal( app.writes.length, 0 ); assert.equal( app.preview.value, '' );
} );
test( 'ダウンロード応答がスナップショットと違う場合はファイルを生成しない', async () => {
	const app = harness( { wrongDownload: true } ); await app.export( 'download' );
	assert.equal( app.downloads, 0 ); assert.match( app.status.textContent, /出力できませんでした/ );
} );
