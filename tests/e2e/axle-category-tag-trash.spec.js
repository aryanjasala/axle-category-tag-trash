const { test, expect, wp, wpEval } = require( './utils' );

const TRASH = '/wp-admin/tools.php?page=axle-category-tag-trash';

/**
 * A category with a child, meta, two posts and a menu link.
 *
 * @param {string} prefix Unique name prefix.
 * @return {Object} IDs.
 */
function seed( prefix ) {
	const id = ( output ) => Number( output );
	const cat = id( wp( 'term', 'create', 'category', `${ prefix } Recipes`, '--description=Food posts', '--porcelain' ) );
	const child = id( wp( 'term', 'create', 'category', `${ prefix } Desserts`, `--parent=${ cat }`, '--porcelain' ) );
	const news = id( wp( 'term', 'create', 'category', `${ prefix } News`, '--porcelain' ) );
	wp( 'term', 'meta', 'add', String( cat ), 'color', 'red' );
	const only = id( wp( 'post', 'create', `--post_title=${ prefix } only recipes`, '--post_status=publish', `--post_category=${ cat }`, '--porcelain' ) );
	const both = id( wp( 'post', 'create', `--post_title=${ prefix } recipes and news`, '--post_status=publish', `--post_category=${ cat },${ news }`, '--porcelain' ) );
	const menu = id( wp( 'menu', 'create', `${ prefix } Menu`, '--porcelain' ) );
	wp( 'menu', 'item', 'add-term', String( menu ), 'category', String( cat ) );
	return { cat, child, news, only, both, menu };
}

const categoriesOf = ( postId ) => wp( 'post', 'term', 'list', String( postId ), 'category', '--field=name' ).split( '\n' ).sort();

function expectFullyRestored( ids, prefix ) {
	expect( wp( 'term', 'get', 'category', String( ids.cat ), '--field=name' ) ).toBe( `${ prefix } Recipes` );
	expect( wp( 'term', 'meta', 'get', String( ids.cat ), 'color' ) ).toBe( 'red' );
	expect( categoriesOf( ids.only ) ).toEqual( [ `${ prefix } Recipes` ] );
	expect( categoriesOf( ids.both ) ).toEqual( [ `${ prefix } News`, `${ prefix } Recipes` ].sort() );
	expect( wp( 'term', 'get', 'category', String( ids.child ), '--field=parent' ) ).toBe( String( ids.cat ) );
	expect( wp( 'menu', 'item', 'list', String( ids.menu ), '--fields=object_id', '--format=csv' ).split( '\n' ).slice( 1 ) ).toEqual( [ String( ids.cat ) ] );
}

test.describe( 'Axle Category & Tag Trash', () => {
	test.afterAll( () => {
		wp( 'site', 'empty', '--yes' );
	} );

	test( 'deleting a category shows Undo, and Undo brings everything back', async ( { page } ) => {
		const ids = seed( 'Undo' );
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=category' );
		const row = page.locator( `#tag-${ ids.cat }` );
		await row.hover();
		await row.locator( '.delete-tag' ).click();

		const notice = page.locator( '.axle-category-tag-trash-undo' );
		await expect( notice ).toContainText( '"Undo Recipes" moved to Axle Category & Tag Trash.' );
		await expect( page.locator( '.axle-category-tag-trash-note a' ) ).toHaveText( 'View Axle Category & Tag Trash (1)' );
		await expect( row ).toHaveCount( 0 );
		expect( categoriesOf( ids.only ) ).toEqual( [ 'Uncategorized' ] );

		await notice.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( `#tag-${ ids.cat }` ) ).toBeVisible();

		expectFullyRestored( ids, 'Undo' );
	} );

	test( 'bulk delete on a term screen offers Undo for all of it', async ( { page } ) => {
		const tags = [ 'Bulk one', 'Bulk two', 'Bulk keep' ].map( ( name ) => Number( wp( 'term', 'create', 'post_tag', name, '--porcelain' ) ) );
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=post_tag' );
		await page.locator( `#cb-select-${ tags[ 0 ] }` ).check();
		await page.locator( `#cb-select-${ tags[ 1 ] }` ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'delete' );
		await page.locator( '#doaction' ).click();

		const notice = page.locator( '.axle-category-tag-trash-undo' );
		await expect( notice ).toContainText( '2 terms moved to Axle Category & Tag Trash.' );
		await notice.getByRole( 'link', { name: 'Undo' } ).click();

		await expect( page.locator( '.actt-restored' ) ).toContainText( 'Restored Bulk one, Bulk two.' );
		await expect( page.locator( `#tag-${ tags[ 0 ] }` ) ).toBeVisible();
		await expect( page.locator( `#tag-${ tags[ 1 ] }` ) ).toBeVisible();
	} );

	test( 'the trash screen filters, searches, shows details and restores one term', async ( { page } ) => {
		const ids = seed( 'Screen' );
		wp( 'term', 'create', 'post_tag', 'Screen tag' );
		wp( 'term', 'delete', 'category', String( ids.cat ) );
		wp( 'term', 'delete', 'post_tag', wp( 'term', 'get', 'post_tag', 'screen-tag', '--by=slug', '--field=term_id' ) );

		await page.goto( TRASH );
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'Categories (1)' );
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'Tags (1)' );

		await page.locator( '.subsubsub' ).getByRole( 'link', { name: /Tags/ } ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '#the-list .actt-name' ) ).toHaveText( 'Screen tag' );

		await page.goto( TRASH );
		await page.locator( '#axle-category-tag-trash-search-input' ).fill( 'Recipes' );
		await page.locator( '#search-submit' ).click();
		const row = page.locator( '#the-list tr' );
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( '.column-attached' ) ).toHaveText( '2 items · 1 child term · 1 menu link' );

		await row.locator( '.actt-details summary' ).click();
		const details = row.locator( '.actt-details' );
		await expect( details ).toContainText( 'Screen only recipes' );
		await expect( details ).toContainText( 'Screen Desserts' );
		await expect( details ).toContainText( 'Screen Menu' );

		await row.hover();
		await row.locator( '.actt-restore' ).click();
		await expect( page.locator( '.actt-restored' ) ).toContainText( 'Restored Screen Recipes.' );
		expectFullyRestored( ids, 'Screen' );
	} );

	test( 'bulk restore, delete permanently, empty trash and the retention setting', async ( { page } ) => {
		const make = ( names ) =>
			names.forEach( ( name ) => {
				const id = wp( 'term', 'create', 'post_tag', name, '--porcelain' );
				wp( 'term', 'delete', 'post_tag', id );
			} );

		make( [ 'Batch A', 'Batch B', 'Batch C' ] );
		await page.goto( TRASH );
		await page.locator( '#cb-select-all-1' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'restore' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.actt-restored' ) ).toContainText( 'Batch A' );
		await expect( page.locator( '.axle-empty' ) ).toContainText( 'Axle Category & Tag Trash is empty.' );

		for ( const slug of [ 'batch-a', 'batch-b', 'batch-c' ] ) {
			wp( 'term', 'delete', 'post_tag', wp( 'term', 'get', 'post_tag', slug, '--by=slug', '--field=term_id' ) );
		}
		await page.goto( TRASH );

		await page.locator( '#cb-select-all-1' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'delete' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( '3 terms deleted for good.' );

		make( [ 'Batch D' ] );
		await page.goto( TRASH );
		let confirmed = '';
		page.once( 'dialog', ( dialog ) => {
			confirmed = dialog.message();
			dialog.accept();
		} );
		await page.getByRole( 'button', { name: 'Empty trash' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( '1 term deleted for good.' );
		expect( confirmed ).toContain( 'cannot be undone' );
		await expect( page.getByRole( 'button', { name: 'Empty trash' } ) ).toHaveCount( 0 );

		await page.locator( '#actt-retention' ).selectOption( '90' );
		await page.getByRole( 'button', { name: 'Save retention' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
		await expect( page.locator( '.actt-intro' ) ).toContainText( 'kept here for 90 days' );
		wp( 'option', 'delete', 'axle_category_tag_trash_settings' );
	} );

	test( 'a category on 6,200 posts is kept in chunks and restored in the background', async ( { page } ) => {
		test.setTimeout( 300_000 );
		const POSTS = 6200;
		const cat = Number( wp( 'term', 'create', 'category', 'Big archive', '--porcelain' ) );

		// Insert the posts with SQL: thousands of wp_insert_post() calls would take minutes.
		const ids = JSON.parse(
			wpEval( `
				global $wpdb;
				$tt  = (int) get_term( ${ cat }, 'category' )->term_taxonomy_id;
				$now = current_time( 'mysql', true );
				$ids = array();
				for ( $batch = 0; $batch < ${ POSTS } / 200; $batch++ ) {
					$rows = array();
					for ( $i = 0; $i < 200; $i++ ) {
						$rows[] = $wpdb->prepare( "( %s, %s, %s, 'publish', 'post', '', '', '', '' )", 'Archive post ' . ( $batch * 200 + $i ), $now, $now );
					}
					$wpdb->query( "INSERT INTO {$wpdb->posts} ( post_title, post_date, post_date_gmt, post_status, post_type, post_content, post_excerpt, to_ping, pinged ) VALUES " . implode( ',', $rows ) );
					$first = (int) $wpdb->insert_id;
					$pairs = array();
					for ( $i = 0; $i < 200; $i++ ) {
						$ids[]   = $first + $i;
						$pairs[] = $wpdb->prepare( '( %d, %d, %d )', $first + $i, $tt, $i % 7 );
					}
					$wpdb->query( "INSERT INTO {$wpdb->term_relationships} ( object_id, term_taxonomy_id, term_order ) VALUES " . implode( ',', $pairs ) );
				}
				wp_update_term_count_now( array( $tt ), 'category' );
				echo wp_json_encode( array( $ids[0], end( $ids ) ) );
			` )
		);
		const inRange = `object_id BETWEEN ${ ids[ 0 ] } AND ${ ids[ 1 ] }`;
		const countRows = ( where ) => Number( wpEval( `global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt USING ( term_taxonomy_id ) WHERE ${ where }" );` ) );

		// Core moves every post to Uncategorized, since this was their only category.
		wp( 'term', 'delete', 'category', String( cat ) );
		expect( countRows( `tt.term_id = 1 AND tr.${ inRange }` ) ).toBe( POSTS );

		const itemId = Number( wp( 'axle-category-tag-trash', 'list', '--format=ids' ).split( ' ' )[ 0 ] );
		const stored = JSON.parse(
			wpEval( `
				echo wp_json_encode( array(
					'objects'  => count( get_post_meta( ${ itemId }, '_axle_category_tag_trash_objects' ) ),
					'fallback' => count( get_post_meta( ${ itemId }, '_axle_category_tag_trash_fallback' ) ),
					'snapshot' => strlen( maybe_serialize( get_post_meta( ${ itemId }, '_axle_category_tag_trash_snapshot', true ) ) ),
				) );
			` )
		);
		expect( stored.objects ).toBe( 7 );
		expect( stored.fallback ).toBe( 7 );
		expect( stored.snapshot ).toBeLessThan( 4000 );

		await page.goto( TRASH );
		const row = page.locator( '#the-list tr', { hasText: 'Big archive' } );
		await expect( row.locator( '.column-attached' ) ).toHaveText( '6,200 items' );
		await row.hover();
		await row.locator( '.actt-restore' ).click();
		await expect( page.locator( '.actt-notes, .notice' ).filter( { hasText: 'being re-attached in the background' } ) ).toBeVisible();
		await expect( page.locator( '#the-list tr', { hasText: 'Big archive' } ) ).toHaveCount( 0 );

		// The first 5,000 came back in the request, the rest wait for cron.
		expect( Number( wp( 'term', 'get', 'category', String( cat ), '--field=term_id' ) ) ).toBe( cat );
		expect( countRows( `tt.term_id = ${ cat }` ) ).toBe( 5000 );
		expect( wpEval( `echo get_post_status( ${ itemId } );` ) ).toBe( 'pending' );

		// One cron run, then WP-CLI finishes the rest.
		wp( 'cron', 'event', 'run', 'axle_category_tag_trash_restore_batch' );
		expect( countRows( `tt.term_id = ${ cat }` ) ).toBe( POSTS );
		wp( 'axle-category-tag-trash', 'restore', String( itemId ) );

		expect( countRows( `tt.term_id = 1 AND tr.${ inRange }` ) ).toBe( 0 );
		expect( Number( wp( 'term', 'get', 'category', String( cat ), '--field=count' ) ) ).toBe( POSTS );
		expect( wpEval( `echo get_post_status( ${ itemId } ) ? 'still there' : 'gone';` ) ).toBe( 'gone' );
		expect( wpEval( `global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = ${ ids[ 0 ] } + 3 AND term_order = 3" );` ) ).toBe( '1' );
	} );

	test( 'abilities: an agent can list, restore and delete trashed terms', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		const ids = seed( 'Agent' );
		wp( 'term', 'delete', 'category', String( ids.cat ) );
		const tag = wp( 'term', 'create', 'post_tag', 'Agent tag', '--porcelain' );
		wp( 'term', 'delete', 'post_tag', tag );
		const path = ( name ) => `/wp-abilities/v1/abilities/axle-category-tag-trash/${ name }/run`;

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=axle-category-tag-trash' );
		expect( listed.body.map( ( ability ) => ability.name ).sort() ).toEqual( [ 'axle-category-tag-trash/delete', 'axle-category-tag-trash/list', 'axle-category-tag-trash/restore' ] );
		// MCP clients only list abilities marked public.
		expect( JSON.parse( wpEval( `echo wp_json_encode( array_values( array_filter( array_map( function ( $ability ) { $meta = $ability->get_meta(); return ( ! empty( $meta['public'] ) && ! empty( $meta['mcp']['public'] ) ) ? '' : $ability->get_name(); }, wp_get_abilities() ), function ( $name ) { return 0 === strpos( $name, 'axle-category-tag-trash/' ); } ) ) );` ) ) ).toEqual( [] );

		const trashed = await rest( 'GET', `${ path( 'list' ) }?input[taxonomy]=category&input[search]=Agent` );
		expect( trashed.status ).toBe( 200 );
		expect( trashed.body.items ).toHaveLength( 1 );
		const item = trashed.body.items[ 0 ];
		expect( item ).toMatchObject( { term_id: ids.cat, name: 'Agent Recipes', taxonomy: 'category', objects: 2, children: 1, menu_links: 1 } );

		const missing = await rest( 'POST', path( 'restore' ), { input: { id: 999999 } } );
		expect( missing.body.code ).toBe( 'axle_category_tag_trash_missing' );

		const restored = await rest( 'POST', path( 'restore' ), { input: { id: item.id } } );
		expect( restored.status ).toBe( 200 );
		expect( restored.body ).toMatchObject( { term_id: ids.cat, background: false } );
		expectFullyRestored( ids, 'Agent' );

		// Deleting for good is destructive, so the Abilities API runs it with DELETE.
		const tagItem = ( await rest( 'GET', `${ path( 'list' ) }?input[taxonomy]=post_tag` ) ).body.items[ 0 ];
		const deleted = await rest( 'DELETE', `${ path( 'delete' ) }?input[id]=${ tagItem.id }` );
		expect( deleted.status ).toBe( 200 );
		expect( deleted.body ).toEqual( { deleted: true } );
		expect( ( await rest( 'GET', path( 'list' ) ) ).body.total ).toBe( 0 );
	} );

	test( 'restores under a new slug when the old one was reused, from WP-CLI', () => {
		const id = wp( 'term', 'create', 'category', 'Reused', '--porcelain' );
		wp( 'term', 'delete', 'category', id );
		wp( 'term', 'create', 'category', 'Reused' );

		const [ itemId ] = wp( 'axle-category-tag-trash', 'list', '--format=ids' ).split( ' ' );
		const output = wp( 'axle-category-tag-trash', 'restore', itemId );
		expect( output ).toContain( `Restored term ${ id }.` );
		expect( output ).toContain( 'restored as "reused-2"' );
		expect( wp( 'term', 'get', 'category', id, '--field=slug' ) ).toBe( 'reused-2' );
	} );
} );
