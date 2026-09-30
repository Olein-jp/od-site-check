( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
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
			button.textContent = '確認しています…';
			progress.textContent = 'サイトの情報を確認しています。この画面を閉じずにお待ちください。';
		} );
	} );
}() );
