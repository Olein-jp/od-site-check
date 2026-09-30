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
