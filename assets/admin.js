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
		const hearing = document.getElementById( 'odsc-hearing' );
		if ( ! form || ! hearing || ! window.odscHearing ) {
			return;
		}
		const model = window.odscHearing.create( settings.hearing );
		const preview = document.getElementById( 'odsc-json-preview' );
		const copy = document.getElementById( 'odsc-copy-json' );
		const download = document.getElementById( 'odsc-download-json' );
		const progress = document.getElementById( 'odsc-hearing-progress' );
		const missing = document.getElementById( 'odsc-hearing-missing' );
		let revision = 0;
		let alive = form.dataset.diagnosticValid === '1';

		function read() {
			const values = {};
			model.questions.forEach( ( question ) => {
				const checked = hearing.querySelectorAll( 'input[name="hearing[' + question.id + ']"]:checked' );
				values[ question.id ] = checked.length === 1 ? checked[ 0 ].value : null;
			} );
			const state = model.evaluate( values );
			return Object.assign( state, { valid: state.valid && alive, revision } );
		}

		function update() {
			const backup = hearing.querySelector( 'input[name="hearing[H-05]"]:checked' );
			const enabled = backup && backup.value === 'yes';
			model.questions.forEach( ( question ) => {
				const fieldset = document.getElementById( 'odsc-' + question.id );
				if ( ! fieldset || question.condition === null ) {
					return;
				}
				fieldset.hidden = ! enabled;
				fieldset.disabled = ! enabled;
				if ( ! enabled ) {
					fieldset.querySelectorAll( 'input' ).forEach( ( input ) => { input.checked = false; } );
				}
			} );
			hearing.querySelectorAll( '[data-hearing-group]' ).forEach( ( group ) => {
				group.hidden = ! Array.from( group.querySelectorAll( 'fieldset' ) ).some( ( fieldset ) => ! fieldset.hidden );
			} );
			const state = read();
			progress.textContent = '回答済み ' + state.answered + ' / 対象 ' + state.total;
			missing.replaceChildren();
			if ( state.missing.length ) {
				const reason = document.createElement( 'p' );
				reason.textContent = '未回答が' + state.missing.length + '件あります';
				missing.appendChild( reason );
				state.missing.forEach( ( id ) => {
					const link = document.createElement( 'a' );
					link.href = '#odsc-' + id;
					link.textContent = id;
					link.addEventListener( 'click', ( event ) => {
						event.preventDefault();
						document.getElementById( 'odsc-' + id ).focus();
					} );
					missing.appendChild( link );
				} );
			}
			copy.disabled = ! state.valid;
			download.disabled = ! state.valid;
		}

		function invalidate() {
			revision++;
			preview.value = '';
			document.getElementById( 'odsc-copy-status' ).textContent = '';
			form.dispatchEvent( new Event( 'odsc:invalidate' ) );
			update();
		}
		function expire() {
			alive = false;
			hearing.querySelectorAll( 'input[type="radio"]' ).forEach( ( input ) => { input.checked = false; } );
			invalidate();
		}
		function requestExport( kind ) {
			if ( ! read().valid ) {
				invalidate();
				return;
			}
			form.dispatchEvent( new CustomEvent( 'odsc:export', { detail: { kind } } ) );
		}
		form.odscState = { read, expire };
		hearing.addEventListener( 'change', invalidate );
		copy.addEventListener( 'click', () => requestExport( 'copy' ) );
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			requestExport( 'download' );
		} );
		document.getElementById( 'odsc-restart' ).addEventListener( 'click', expire );
		window.addEventListener( 'pagehide', expire );
		window.addEventListener( 'pageshow', ( event ) => { if ( event.persisted ) { expire(); } } );
		hearing.querySelectorAll( 'input[type="radio"]' ).forEach( ( input ) => { input.checked = false; } );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		setupRunForm();
		setupResultForm();
	} );
}() );
