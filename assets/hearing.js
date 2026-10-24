( function ( root ) {
	'use strict';

	// PHPと同じ固定定義を使い、未回答と明示unknownを区別する。
	function create( definition ) {
		const questions = definition && Array.isArray( definition.questions ) ? definition.questions : [];
		const ids = Array.from( { length: 20 }, ( _, index ) => 'H-' + String( index + 1 ).padStart( 2, '0' ) );
		const ready = definition && definition.schema_version === '1.0' && questions.length === 20 &&
			questions.every( ( question, index ) => question.id === ids[ index ] && question.options &&
				Object.keys( question.options ).length > 0 && ( index >= 5 && index <= 11
					? question.condition && question.condition.id === 'H-05' && question.condition.answer === 'yes'
					: question.condition === null ) );

		function evaluate( values ) {
			const backup = values[ 'H-05' ];
			const missing = [];
			const rows = [];
			let answered = 0;
			let total = 0;
			let valid = Boolean( ready ) && Object.keys( values ).length === 20 && ids.every( ( id ) => Object.hasOwnProperty.call( values, id ) );
			questions.forEach( ( question ) => {
				const applicable = question.condition === null || backup === 'yes';
				const value = values[ question.id ];
				if ( applicable ) {
					total++;
					if ( typeof value === 'string' && Object.hasOwnProperty.call( question.options, value ) ) {
						answered++;
					} else {
						missing.push( question.id );
						valid = false;
					}
				} else if ( value !== null ) {
					valid = false;
				}
				rows.push( [ question.id, applicable ? 'answered' : 'not_applicable', applicable ? value : null ] );
			} );
			return { valid, answered, total, missing, rows };
		}
		return { evaluate, ids, questions, ready };
	}
	root.odscHearing = { create };
}( globalThis ) );
