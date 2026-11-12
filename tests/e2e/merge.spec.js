const { test, expect, wp, wpEval } = require( './utils' );

const TRASH = '/wp-admin/tools.php?page=axle-category-tag-trash';

const term = ( taxonomy, name, parent = 0 ) => Number( wp( 'term', 'create', taxonomy, name, `--parent=${ parent }`, '--porcelain' ) );

/**
 * A published post with exactly these terms of one taxonomy.
 *
 * @param {string}   title    Post title.
 * @param {string}   taxonomy Taxonomy.
 * @param {number[]} termIds  Term IDs.
 * @return {number} Post ID.
 */
function post( title, taxonomy, termIds ) {
	const id = Number( wp( 'post', 'create', `--post_title=${ title }`, '--post_status=publish', '--porcelain' ) );
	wpEval( `wp_set_object_terms( ${ id }, array( ${ termIds.join( ',' ) } ), '${ taxonomy }' );` );
	return id;
}

const termsOf = ( postId, taxonomy ) => wp( 'post', 'term', 'list', String( postId ), taxonomy, '--field=term_id' ).split( '\n' ).filter( Boolean ).map( Number ).sort( ( a, b ) => a - b );
const sorted = ( ...ids ) => ids.sort( ( a, b ) => a - b );
const count = ( taxonomy, id ) => Number( wp( 'term', 'get', taxonomy, String( id ), '--field=count' ) );
const exists = ( taxonomy, id ) => {
	try {
		wp( 'term', 'get', taxonomy, String( id ), '--field=term_id' );
		return true;
	} catch ( error ) {
		return false;
	}
};

async function pickTargetAndMerge( page, search, targetName, buttonName ) {
	await page.getByLabel( 'Find the term to keep' ).fill( search );
	await page.getByRole( 'button', { name: 'Search' } ).click();
	await page.getByRole( 'radio', { name: new RegExp( `^${ targetName }` ) } ).check();
	await page.getByRole( 'button', { name: 'Preview the merge' } ).click();
	await page.getByRole( 'button', { name: buttonName } ).click();
}

test.describe( 'Merging terms', () => {
	test.afterAll( () => {
		wp( 'site', 'empty', '--yes' );
	} );

	test( 'preview, merge from a row action, and Undo puts back exactly what changed', async ( { page } ) => {
		const target = term( 'post_tag', 'Merge WordPress' );
		const source = term( 'post_tag', 'Merge wp' );
		const other = term( 'post_tag', 'Merge Word Press' );
		const a = post( 'Merge post A', 'post_tag', [ target ] );
		const b = post( 'Merge post B', 'post_tag', [ target, source ] );
		const c = post( 'Merge post C', 'post_tag', [ source ] );
		const d = post( 'Merge post D', 'post_tag', [ other ] );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=post_tag' );
		const row = page.locator( `#tag-${ source }` );
		await row.hover();
		await row.getByRole( 'link', { name: /Merge .* into another term/ } ).click();

		await expect( page.getByRole( 'heading', { level: 1 } ) ).toHaveText( 'Category & Tag Trash' );
		await expect( page.locator( '.actt-sources' ) ).toContainText( 'Merge wp' );
		await expect( page.locator( '.actt-sources' ) ).toContainText( '2 posts' );

		await page.getByLabel( 'Find the term to keep' ).fill( 'Merge WordPress' );
		await page.getByRole( 'button', { name: 'Search' } ).click();
		await expect( page.getByRole( 'radio', { name: /Merge Word Press/ } ) ).toHaveCount( 0 );
		await page.getByRole( 'radio', { name: /^Merge WordPress/ } ).check();
		await page.getByRole( 'button', { name: 'Preview the merge' } ).click();

		// The preview changes nothing: the source is still there, on both posts.
		const preview = page.locator( '.actt-page table.widefat tbody tr' );
		await expect( preview ).toHaveCount( 1 );
		await expect( preview.locator( 'td' ).nth( 0 ) ).toHaveText( '2' );
		await expect( preview.locator( 'td' ).nth( 1 ) ).toHaveText( '1' );
		await expect( preview.locator( 'td' ).nth( 2 ) ).toHaveText( '1' );
		await expect( page.locator( '.axle-diff del' ) ).toContainText( 'Merge wp' );
		await expect( page.locator( '.axle-diff ins' ) ).toContainText( 'Merge WordPress' );
		await expect( page.locator( '.axle-confirm' ) ).toContainText( 'You can undo it from the trash.' );
		expect( exists( 'post_tag', source ) ).toBe( true );
		expect( count( 'post_tag', target ) ).toBe( 2 );

		await page.getByRole( 'button', { name: 'Merge 1 term into "Merge WordPress"' } ).click();
		await expect( page.locator( '.actt-merged' ) ).toContainText( 'Merged 1 term. 1 item moved.' );

		expect( exists( 'post_tag', source ) ).toBe( false );
		expect( termsOf( a, 'post_tag' ) ).toEqual( [ target ] );
		expect( termsOf( b, 'post_tag' ) ).toEqual( [ target ] );
		expect( termsOf( c, 'post_tag' ) ).toEqual( [ target ] );
		expect( termsOf( d, 'post_tag' ) ).toEqual( [ other ] );
		expect( count( 'post_tag', target ) ).toBe( 3 );

		// It sits in the trash as a merge, not as a plain delete.
		const rowInTrash = page.locator( '#the-list tr', { hasText: 'Merge wp' } );
		await expect( rowInTrash ).toContainText( 'Merged into Merge WordPress' );
		await expect( rowInTrash.getByRole( 'link', { name: 'Undo merge' } ) ).toBeVisible();

		await page.locator( '.actt-merged' ).getByRole( 'link', { name: 'Undo merge' } ).click();
		await expect( page.locator( '.actt-restored' ) ).toContainText( 'Restored Merge wp.' );
		await expect( page.locator( '.actt-restored' ) ).toContainText( 'Merge undone.' );

		// The source is back on its own posts. The target is off C, which only got it through the merge, and stays on B, which had both.
		expect( exists( 'post_tag', source ) ).toBe( true );
		expect( termsOf( a, 'post_tag' ) ).toEqual( [ target ] );
		expect( termsOf( b, 'post_tag' ) ).toEqual( sorted( target, source ) );
		expect( termsOf( c, 'post_tag' ) ).toEqual( [ source ] );
		expect( termsOf( d, 'post_tag' ) ).toEqual( [ other ] );
		expect( count( 'post_tag', target ) ).toBe( 2 );
		expect( count( 'post_tag', source ) ).toBe( 2 );
	} );

	test( 'bulk merge: two tags into one, then undo each', async ( { page } ) => {
		const target = term( 'post_tag', 'Bulk keep tag' );
		const one = term( 'post_tag', 'Bulk lose one' );
		const two = term( 'post_tag', 'Bulk lose two' );
		const p1 = post( 'Bulk post 1', 'post_tag', [ one ] );
		const p2 = post( 'Bulk post 2', 'post_tag', [ two ] );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=post_tag' );
		await page.locator( `#cb-select-${ one }` ).check();
		await page.locator( `#cb-select-${ two }` ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'axle_merge' );
		await page.locator( '#doaction' ).click();

		await expect( page.locator( '.actt-sources li' ) ).toHaveCount( 2 );
		await pickTargetAndMerge( page, 'Bulk keep', 'Bulk keep tag', 'Merge 2 terms into "Bulk keep tag"' );
		await expect( page.locator( '.actt-merged' ) ).toContainText( 'Merged 2 terms. 2 items moved.' );
		expect( termsOf( p1, 'post_tag' ) ).toEqual( [ target ] );
		expect( termsOf( p2, 'post_tag' ) ).toEqual( [ target ] );

		await page.locator( '.actt-merged' ).getByRole( 'link', { name: 'Undo merge' } ).click();
		await expect( page.locator( '.actt-restored' ) ).toContainText( 'Bulk lose one' );
		expect( termsOf( p1, 'post_tag' ) ).toEqual( [ one ] );
		expect( termsOf( p2, 'post_tag' ) ).toEqual( [ two ] );
		expect( count( 'post_tag', target ) ).toBe( 0 );
	} );

	test( 'child terms go under the target by default, or up a level, and Undo moves them back', () => {
		const parent = term( 'category', 'Kids parent' );
		const source = term( 'category', 'Kids source', parent );
		const child = term( 'category', 'Kids child', source );
		const target = term( 'category', 'Kids target' );

		const dry = wp( 'axle-category-tag-trash', 'merge', String( source ), String( target ), '--taxonomy=category', '--dry-run' );
		expect( dry ).toContain( 'Would merge "Kids source" into "Kids target".' );
		expect( dry ).toContain( '1 child terms (target)' );
		expect( exists( 'category', source ) ).toBe( true );

		const out = wp( 'axle-category-tag-trash', 'merge', 'kids-source', 'kids-target', '--taxonomy=category' );
		expect( out ).toContain( 'Merged "Kids source" into "Kids target".' );
		expect( wp( 'term', 'get', 'category', String( child ), '--field=parent' ) ).toBe( String( target ) );

		const item = wp( 'axle-category-tag-trash', 'list', '--taxonomy=category', '--format=ids' ).split( ' ' )[ 0 ];
		wp( 'axle-category-tag-trash', 'restore', item );
		expect( wp( 'term', 'get', 'category', String( child ), '--field=parent' ) ).toBe( String( source ) );
		expect( wp( 'term', 'get', 'category', String( source ), '--field=parent' ) ).toBe( String( parent ) );

		// The other choice leaves them to core: one level up, where the source was.
		wp( 'axle-category-tag-trash', 'merge', String( source ), String( target ), '--taxonomy=category', '--children=parent' );
		expect( wp( 'term', 'get', 'category', String( child ), '--field=parent' ) ).toBe( String( parent ) );
	} );

	test( 'a term inside the source can be the target, and the default category cannot be merged away', () => {
		const source = term( 'category', 'Inside source' );
		const target = term( 'category', 'Inside target', source );
		const post1 = post( 'Inside post', 'category', [ source ] );

		wp( 'axle-category-tag-trash', 'merge', String( source ), String( target ), '--taxonomy=category' );
		expect( wp( 'term', 'get', 'category', String( target ), '--field=parent' ) ).toBe( '0' );
		expect( termsOf( post1, 'category' ) ).toEqual( [ target ] );

		let refused = '';
		try {
			wp( 'axle-category-tag-trash', 'merge', '1', String( target ), '--taxonomy=category' );
		} catch ( error ) {
			refused = String( error.stderr );
		}
		expect( refused ).toContain( 'is the default term and cannot be merged away' );

		try {
			wp( 'axle-category-tag-trash', 'merge', String( target ), String( target ), '--taxonomy=category' );
		} catch ( error ) {
			refused = String( error.stderr );
		}
		expect( refused ).toContain( 'Pick two different terms.' );
	} );

	test( 'menu links follow the merge, and Undo points them back', () => {
		const source = term( 'category', 'Nav source' );
		const target = term( 'category', 'Nav target' );
		const menu = Number( wp( 'menu', 'create', 'Merge menu', '--porcelain' ) );
		wp( 'menu', 'item', 'add-term', String( menu ), 'category', String( source ) );
		const linked = () => wp( 'menu', 'item', 'list', String( menu ), '--fields=object_id', '--format=csv' ).split( '\n' ).slice( 1 );

		wp( 'axle-category-tag-trash', 'merge', String( source ), String( target ), '--taxonomy=category' );
		expect( linked() ).toEqual( [ String( target ) ] );

		const item = wp( 'axle-category-tag-trash', 'list', '--taxonomy=category', '--format=ids' ).split( ' ' )[ 0 ];
		wp( 'axle-category-tag-trash', 'restore', item );
		expect( linked() ).toEqual( [ String( source ) ] );
	} );

	test( 'a big merge runs in the background with a progress card, then Undo finishes everything', async ( { page } ) => {
		test.setTimeout( 300_000 );
		const POSTS = 5400;
		const source = term( 'post_tag', 'Big source tag' );
		const target = term( 'post_tag', 'Big target tag' );
		const kept = post( 'Big shared post', 'post_tag', [ source, target ] );

		const range = JSON.parse(
			wpEval( `
				global $wpdb;
				$tt  = (int) get_term( ${ source }, 'post_tag' )->term_taxonomy_id;
				$now = current_time( 'mysql', true );
				$ids = array();
				for ( $batch = 0; $batch < ${ POSTS } / 200; $batch++ ) {
					$rows = array();
					for ( $i = 0; $i < 200; $i++ ) {
						$rows[] = $wpdb->prepare( "( %s, %s, %s, 'publish', 'post', '', '', '', '' )", 'Big merge post ' . ( $batch * 200 + $i ), $now, $now );
					}
					$wpdb->query( "INSERT INTO {$wpdb->posts} ( post_title, post_date, post_date_gmt, post_status, post_type, post_content, post_excerpt, to_ping, pinged ) VALUES " . implode( ',', $rows ) );
					$first = (int) $wpdb->insert_id;
					$pairs = array();
					for ( $i = 0; $i < 200; $i++ ) {
						$ids[]   = $first + $i;
						$pairs[] = $wpdb->prepare( '( %d, %d, 0 )', $first + $i, $tt );
					}
					$wpdb->query( "INSERT INTO {$wpdb->term_relationships} ( object_id, term_taxonomy_id, term_order ) VALUES " . implode( ',', $pairs ) );
				}
				wp_update_term_count_now( array( $tt ), 'post_tag' );
				echo wp_json_encode( array( $ids[0], end( $ids ) ) );
			` )
		);
		const onTarget = () => Number( wpEval( `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", get_term( ${ target }, 'post_tag' )->term_taxonomy_id ) );` ) );

		await page.goto( `${ TRASH }&view=merge&taxonomy=post_tag&terms=${ source }` );
		await pickTargetAndMerge( page, 'Big target', 'Big target tag', 'Merge 1 term into "Big target tag"' );
		await expect( page.locator( '.actt-merged' ) ).toContainText( 'carries on in the background' );

		// The first 5,000 moved in the request. The card shows how far it got, and the source is still there.
		const card = page.locator( '.actt-running' );
		await expect( card ).toContainText( 'Big source tag into Big target tag: 5,000 of 5,401 moved' );
		await expect( card.getByRole( 'progressbar' ) ).toHaveAttribute( 'aria-valuenow', '92' );
		expect( exists( 'post_tag', source ) ).toBe( true );
		expect( onTarget() ).toBe( 5001 );
		expect( Number( wp( 'cron', 'event', 'list', '--hook=axle_category_tag_trash_merge_batch', '--format=count' ) ) ).toBeGreaterThanOrEqual( 1 );

		wp( 'cron', 'event', 'run', 'axle_category_tag_trash_merge_batch' );
		expect( exists( 'post_tag', source ) ).toBe( false );
		expect( onTarget() ).toBe( POSTS + 1 );
		expect( count( 'post_tag', target ) ).toBe( POSTS + 1 );

		await page.goto( TRASH );
		await expect( page.locator( '.actt-running' ) ).toHaveCount( 0 );
		const row = page.locator( '#the-list tr', { hasText: 'Big source tag' } );
		await expect( row ).toContainText( 'Merged into Big target tag' );
		await expect( row.locator( '.column-attached' ) ).toContainText( '5,401 items' );

		// Undo from WP-CLI finishes it in one go: the shared post keeps both, the rest lose the target.
		const item = wp( 'axle-category-tag-trash', 'list', '--taxonomy=post_tag', '--format=ids' ).split( ' ' )[ 0 ];
		wp( 'axle-category-tag-trash', 'restore', item );
		expect( count( 'post_tag', target ) ).toBe( 1 );
		expect( termsOf( kept, 'post_tag' ) ).toEqual( sorted( source, target ) );
		expect( Number( wp( 'term', 'get', 'post_tag', String( source ), '--field=count' ) ) ).toBe( POSTS + 1 );
		expect( range[ 1 ] - range[ 0 ] + 1 ).toBe( POSTS );
	} );

	test( 'abilities: preview is read-only, merge is destructive, and restore undoes it', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		const source = term( 'post_tag', 'Agent merge source' );
		const target = term( 'post_tag', 'Agent merge target' );
		const p1 = post( 'Agent merge post', 'post_tag', [ source ] );
		const path = ( name ) => `/wp-abilities/v1/abilities/axle-category-tag-trash/${ name }/run`;
		const input = `input[taxonomy]=post_tag&input[source]=${ source }&input[target]=${ target }`;

		const meta = ( await rest( 'GET', '/wp-abilities/v1/abilities?category=axle-category-tag-trash' ) ).body;
		const annotations = ( name ) => meta.find( ( ability ) => ability.name === `axle-category-tag-trash/${ name }` ).meta.annotations;
		expect( annotations( 'preview-merge' ) ).toMatchObject( { readonly: true } );
		expect( annotations( 'merge' ) ).toMatchObject( { readonly: false, destructive: true } );

		const preview = await rest( 'GET', `${ path( 'preview-merge' ) }?${ input }` );
		expect( preview.status ).toBe( 200 );
		expect( preview.body ).toMatchObject( { objects: 1, already: 0, moving: 1, children: 0, source_id: source, target_id: target } );
		expect( exists( 'post_tag', source ) ).toBe( true );

		const merged = await rest( 'POST', path( 'merge' ), { input: { taxonomy: 'post_tag', source, target } } );
		expect( merged.status ).toBe( 200 );
		expect( merged.body ).toMatchObject( { done: true, moved: 1 } );
		expect( termsOf( p1, 'post_tag' ) ).toEqual( [ target ] );

		const listed = await rest( 'GET', `${ path( 'list' ) }?input[taxonomy]=post_tag` );
		expect( listed.body.items[ 0 ] ).toMatchObject( { name: 'Agent merge source', merged_into: target } );

		const undone = await rest( 'POST', path( 'restore' ), { input: { id: merged.body.item_id } } );
		expect( undone.status ).toBe( 200 );
		expect( termsOf( p1, 'post_tag' ) ).toEqual( [ source ] );

		const same = await rest( 'GET', `${ path( 'preview-merge' ) }?input[taxonomy]=post_tag&input[source]=${ source }&input[target]=${ source }` );
		expect( same.body.code ).toBe( 'axle_category_tag_trash_same' );
	} );
} );
