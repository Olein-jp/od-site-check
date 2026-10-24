const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
require( '../../assets/hearing.js' );
const definition = JSON.parse( fs.readFileSync( new URL( '../../schemas/hearing-1.0.json', 'file://' + __filename ) ) );
const model = globalThis.odscHearing.create( definition );
const values = ( backup ) => Object.fromEntries( model.questions.map( ( q ) => [ q.id, q.id === 'H-05' ? backup : q.condition && backup !== 'yes' ? null : 'unknown' ] ) );

test( '13問と20問を分岐し、unknownを明示回答として扱う', () => {
	for ( const backup of [ 'no', 'unknown', 'yes' ] ) {
		const state = model.evaluate( values( backup ) );
		assert.equal( state.valid, true );
		assert.equal( state.total, backup === 'yes' ? 20 : 13 );
		assert.equal( state.answered, state.total );
		assert.equal( state.rows.length, 20 );
	}
} );
test( '初期未回答と不正なコード・余分なキーを拒否する', () => {
	const empty = Object.fromEntries( model.ids.map( ( id ) => [ id, null ] ) );
	assert.equal( model.evaluate( empty ).answered, 0 );
	assert.equal( model.evaluate( empty ).total, 13 );
	assert.equal( model.evaluate( empty ).valid, false );
	for ( const id of model.ids ) {
		const data = values( 'yes' );
		data[ id ] = null;
		assert.equal( model.evaluate( data ).valid, false );
	}
	assert.equal( model.evaluate( { ...values( 'no' ), 'H-21': 'unknown' } ).valid, false );
	assert.equal( model.evaluate( { ...values( 'no' ), 'H-01': 'invalid' } ).valid, false );
	assert.equal( model.evaluate( { ...values( 'no' ), 'H-06': 'unknown' } ).valid, false );
	assert.equal( globalThis.odscHearing.create( { ...definition, questions: definition.questions.slice( 1 ) } ).evaluate( values( 'yes' ) ).valid, false );
} );
