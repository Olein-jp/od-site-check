( function () {
	'use strict';

	const settings = window.odscAdmin || {};

	function setupRunForm() {
		const form = document.getElementById( 'odsc-run-form' );
		const consent = document.getElementById( 'odsc-consent' );
		const button = document.getElementById( 'odsc-run-button' );
		const progress = document.getElementById( 'odsc-progress' );

		if ( ! form || ! consent || ! button || ! progress ) {
			return;
		}

		consent.addEventListener( 'change', function () {
			button.disabled = ! consent.checked;
		} );

		form.addEventListener( 'submit', function () {
			button.disabled = true;
			button.textContent = settings.checking || '確認しています…';
			progress.textContent = settings.checkingNote || '';
		} );
	}

	function setupResultForm() {
		const form = document.getElementById( 'odsc-export-form' );
		const preview = document.getElementById( 'odsc-json-preview' );
		const copyButton = document.getElementById( 'odsc-copy-json' );
		const copyStatus = document.getElementById( 'odsc-copy-status' );

		if ( ! form || ! preview || ! copyButton || ! copyStatus ) {
			return;
		}

		let payload;
		try {
			payload = JSON.parse( preview.value );
		} catch ( error ) {
			copyStatus.textContent = settings.copyFailed || 'JSONを読み込めませんでした。';
			return;
		}

		const originalManualResults = {};
		form.querySelectorAll( '[data-odsc-manual-id]' ).forEach( function ( field ) {
			const result = payload.results.find( function ( item ) {
				return item.id === field.dataset.odscManualId;
			} );

			if ( result ) {
				originalManualResults[ result.id ] = JSON.parse( JSON.stringify( result ) );
			}

			field.addEventListener( 'input', function () {
				updateManualResult( field );
			} );
		} );

		function updateManualResult( field ) {
			const id = field.dataset.odscManualId;
			const index = payload.results.findIndex( function ( item ) {
				return item.id === id;
			} );

			if ( -1 === index || ! originalManualResults[ id ] ) {
				return;
			}

			const answer = field.value.trim();
			if ( '' === answer ) {
				payload.results[ index ] = JSON.parse( JSON.stringify( originalManualResults[ id ] ) );
			} else {
				payload.results[ index ] = {
					id,
					status: 'collected',
					source: 'manual_input',
					value: { response: answer },
					note: settings.manualNote || '管理画面で手動入力されました。',
					error_code: null,
				};
			}

			preview.value = JSON.stringify( payload, null, 2 ) + '\n';
			updateStatusDisplay( id, payload.results[ index ].status );
			updateSummary();
		}

		function updateStatusDisplay( id, status ) {
			const row = document.querySelector( '[data-odsc-result-id="' + id + '"]' );
			const badge = row ? row.querySelector( '[data-odsc-status]' ) : null;

			if ( ! badge || ! settings.statusIcons || ! settings.statusLabels ) {
				return;
			}

			badge.className = 'odsc-status odsc-status--' + status;
			badge.querySelector( '.dashicons' ).className = 'dashicons ' + settings.statusIcons[ status ];
			badge.querySelector( '[data-odsc-status-label]' ).textContent = 'collected' === status
				? settings.manualFilled || settings.statusLabels[ status ]
				: settings.statusLabels[ status ];
		}

		function updateSummary() {
			const summary = { collected: 0, missing: 0, manual: 0, error: 0 };

			payload.results.forEach( function ( item ) {
				if ( 'collected' === item.status ) {
					summary.collected++;
				} else if ( 'manual_required' === item.status ) {
					summary.manual++;
				} else if ( 'error' === item.status ) {
					summary.error++;
				} else {
					summary.missing++;
				}
			} );

			Object.keys( summary ).forEach( function ( key ) {
				const element = document.getElementById( 'odsc-summary-' + key );
				if ( element ) {
					element.textContent = summary[ key ];
				}
			} );
		}

		copyButton.addEventListener( 'click', function () {
			const copyPromise = navigator.clipboard && window.isSecureContext
				? navigator.clipboard.writeText( preview.value )
				: fallbackCopy();

			Promise.resolve( copyPromise ).then( function () {
				copyStatus.textContent = settings.copied || 'JSONをコピーしました。';
			} ).catch( function () {
				copyStatus.textContent = settings.copyFailed || 'コピーできませんでした。';
			} );
		} );

		function fallbackCopy() {
			preview.focus();
			preview.select();
			const copied = document.execCommand( 'copy' );
			preview.setSelectionRange( 0, 0 );

			return copied ? Promise.resolve() : Promise.reject();
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		setupRunForm();
		setupResultForm();
	} );
}() );
