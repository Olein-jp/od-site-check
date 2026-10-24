( function () {
	'use strict';
	document.addEventListener( 'DOMContentLoaded', function () {
		const form = document.getElementById( 'odsc-export-form' );
		if ( ! form || ! form.odscState ) {
			return;
		}
		const preview = document.getElementById( 'odsc-json-preview' );
		const status = document.getElementById( 'odsc-copy-status' );
		const buttons = [ document.getElementById( 'odsc-copy-json' ), document.getElementById( 'odsc-download-json' ) ];
		const initial = { payload: form.elements.odsc_payload.value, signature: form.elements.odsc_signature.value };
		let cached = null;
		let controller = null;
		let busy = false;

		function encode( text ) {
			const bytes = new TextEncoder().encode( text );
			let binary = '';
			bytes.forEach( ( byte ) => { binary += String.fromCharCode( byte ); } );
			return btoa( binary );
		}
		function current( state ) {
			const now = form.odscState.read();
			return now.valid && now.revision === state.revision && JSON.stringify( now.rows ) === JSON.stringify( state.rows );
		}
		async function post( data, signal ) {
			const response = await fetch( form.getAttribute( 'action' ), { method: 'POST', credentials: 'same-origin', cache: 'no-store',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: new URLSearchParams( data ), signal } );
			if ( ! response.ok || ! ( response.headers.get( 'content-type' ) || '' ).startsWith( 'application/json' ) ) {
				throw new Error( '出力を検証できません。再診断してください。' );
			}
			return response;
		}
		form.addEventListener( 'odsc:invalidate', function () {
			cached = null;
			if ( controller ) {
				controller.abort();
			}
		} );
		form.addEventListener( 'odsc:export', async function ( event ) {
			const state = form.odscState.read();
			if ( busy || ! state.valid ) {
				return;
			}
			busy = true;
			controller = new AbortController();
			buttons.forEach( ( button ) => { button.disabled = true; } );
			status.textContent = '出力内容を確認しています…';
			try {
				const source = cached ? { payload: encode( cached.json ), signature: cached.signature } : initial;
				const response = await post( { action: 'odsc_prepare', odsc_payload: source.payload, odsc_signature: source.signature,
					_odsc_prepare_nonce: form.elements._odsc_prepare_nonce.value, odsc_answers: JSON.stringify( state.rows ) }, controller.signal );
				const snapshot = await response.json();
				if ( ! current( state ) ) {
					return;
				}
				if ( typeof snapshot.json !== 'string' || typeof snapshot.signature !== 'string' || typeof snapshot.filename !== 'string' ) {
					throw new Error( '出力形式が正しくありません。' );
				}
				cached = snapshot;
				preview.value = snapshot.json;
				if ( event.detail.kind === 'copy' ) {
					if ( navigator.clipboard && window.isSecureContext ) {
						await navigator.clipboard.writeText( snapshot.json );
					} else {
						preview.focus();
						preview.select();
						if ( ! document.execCommand( 'copy' ) ) {
							throw new Error( 'クリップボードが利用できません。' );
						}
						preview.setSelectionRange( 0, 0 );
					}
					if ( current( state ) ) {
						status.textContent = 'JSONをコピーしました。';
					}
				} else {
					const download = await post( { action: 'odsc_download', odsc_payload: encode( snapshot.json ), odsc_signature: snapshot.signature,
						_odsc_download_nonce: form.elements._odsc_download_nonce.value }, controller.signal );
					const blob = await download.blob();
					if ( ! current( state ) ) {
						return;
					}
					if ( await blob.text() !== snapshot.json || ! current( state ) ) {
						throw new Error( 'ダウンロード内容が一致しません。' );
					}
					const url = URL.createObjectURL( blob );
					const link = document.createElement( 'a' );
					link.href = url;
					link.download = snapshot.filename;
					document.body.appendChild( link );
					link.click();
					link.remove();
					setTimeout( () => URL.revokeObjectURL( url ), 1000 );
					status.textContent = 'ダウンロードを開始しました。';
				}
			} catch ( error ) {
				if ( current( state ) && error.name !== 'AbortError' ) {
					status.textContent = '出力できませんでした。回答と権限を確認し、再度お試しください。';
				}
			} finally {
				busy = false;
				controller = null;
				buttons.forEach( ( button ) => { button.disabled = ! form.odscState.read().valid; } );
			}
		} );
	} );
}() );
