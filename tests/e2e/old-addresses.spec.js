// Old addresses of deleted terms that still get visits: counting, the row, the redirect added after the delete, Undo.
const { test, expect, wp, wpEval, logInAs } = require( './utils' );

const TRASH = '/wp-admin/tools.php?page=axle-category-tag-trash';
const PERSON = { 'user-agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36' };
const BOT = { 'user-agent': 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' };
const MU = 'wp-content/mu-plugins/ctt-e2e-no-redirects.php';
const PASSWORD = 'tagger-Pass-123!';

const OPTIONS = [ 'axle_category_tag_trash_redirects', 'axle_category_tag_trash_redirect_hits', 'axle_category_tag_trash_old_hits', 'axle_category_tag_trash_old_paths' ];
const clean = () => {
	for ( const option of OPTIONS ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not there.
		}
	}
};
const term = ( taxonomy, name ) => Number( wp( 'term', 'create', taxonomy, name, '--porcelain' ) );
const oldHits = () => {
	try {
		return JSON.parse( wp( 'option', 'get', 'axle_category_tag_trash_old_hits', '--format=json' ) );
	} catch ( error ) {
		return null;
	}
};
const itemId = ( taxonomy ) => Number( wp( 'axle-category-tag-trash', 'list', `--taxonomy=${ taxonomy }`, '--format=ids' ).split( ' ' ).filter( Boolean )[ 0 ] );
const redirects = () => JSON.parse( wp( 'axle-category-tag-trash', 'redirects', 'list', '--format=json' ) || '[]' );

async function status( request, url, headers = PERSON ) {
	const response = await request.get( url, { maxRedirects: 0, failOnStatusCode: false, headers } );
	return { status: response.status(), location: response.headers().location || '' };
}

test.describe( 'Old addresses of deleted terms', () => {
	test.beforeEach( () => {
		wp( 'site', 'empty', '--yes' );
		clean();
	} );

	test.afterAll( () => {
		wp( 'site', 'empty', '--yes' );
		clean();
	} );

	test( 'only visits from people to the address of a trashed term are counted, and a visit does not write every time', async ( { page, request } ) => {
		const id = term( 'category', 'Gone cooking' );
		wp( 'term', 'delete', 'category', String( id ) );

		expect( ( await status( request, '/category/gone-cooking/', BOT ) ).status ).toBe( 404 );
		expect( ( await status( request, '/category/never-existed/' ) ).status ).toBe( 404 );
		expect( oldHits(), 'a bot and an unknown address leave no trace' ).toBeNull();

		expect( ( await status( request, '/category/gone-cooking/' ) ).status ).toBe( 404 );
		expect( oldHits()[ 'category/gone-cooking' ].n ).toBe( 1 );

		// A second visit in the same minute does not write again, with no object cache.
		const before = wp( 'option', 'get', 'axle_category_tag_trash_old_hits', '--format=json' );
		await status( request, '/category/gone-cooking/' );
		await status( request, '/category/gone-cooking/feed/' );
		expect( wp( 'option', 'get', 'axle_category_tag_trash_old_hits', '--format=json' ) ).toBe( before );

		await page.goto( TRASH );
		const row = page.locator( '#the-list tr', { hasText: 'Gone cooking' } );
		await expect( row.locator( '.actt-visits' ) ).toHaveText( 'Old address visited 1 time this week' );
		await expect( row.getByRole( 'link', { name: 'Redirect its old address...' } ) ).toBeVisible();
	} );

	test( 'the old address can be redirected to a term after the delete, with Undo', async ( { page, request } ) => {
		const gone = term( 'category', 'Gone baking' );
		term( 'category', 'Fresh baking' );
		wp( 'term', 'delete', 'category', String( gone ) );
		await status( request, '/category/gone-baking/' );

		await page.goto( TRASH );
		const first = page.locator( '#the-list tr', { hasText: 'Gone baking' } );
		await first.hover();
		await first.getByRole( 'link', { name: 'Redirect its old address...' } ).click();
		await expect( page.locator( '.actt-redirect-old' ) ).toContainText( '/category/gone-baking/' );
		await expect( page.locator( '.actt-redirect-old' ) ).toContainText( 'Visited 1 time this week.' );
		await page.getByLabel( 'Term to send visitors to' ).selectOption( { label: 'Fresh baking' } );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();

		await expect( page.locator( '.actt-old-redirect' ) ).toContainText( 'Redirecting /category/gone-baking/ to' );
		const result = await status( request, '/category/gone-baking/' );
		expect( result.status ).toBe( 301 );
		expect( result.location ).toContain( '/category/fresh-baking/' );
		expect( redirects().map( ( row ) => [ row.old, row.kind ] ) ).toEqual( [ [ 'category/gone-baking', 'manual' ] ] );

		const row = page.locator( '#the-list tr', { hasText: 'Gone baking' } );
		await expect( row ).toContainText( 'Its old address redirects.' );
		await expect( row.getByRole( 'link', { name: 'Redirect its old address...' } ) ).toHaveCount( 0 );

		await page.goto( `${ TRASH }&view=redirects` );
		await expect( page.locator( '.actt-redirects' ) ).toContainText( 'Added from the trash' );

		await page.goto( TRASH );
		await page.goto( `${ TRASH }&actt_old_redirect=${ require( 'crypto' ).createHash( 'md5' ).update( 'category/gone-baking' ).digest( 'hex' ) }` );
		await page.getByRole( 'link', { name: 'Undo' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Took the redirect away.' );
		expect( redirects() ).toEqual( [] );
		expect( ( await status( request, '/category/gone-baking/' ) ).status ).toBe( 404 );
	} );

	test( 'an address on this site works as a target, another site does not, and restoring the term drops the redirect', async ( { page, request } ) => {
		const gone = term( 'category', 'Gone roasting' );
		wp( 'term', 'delete', 'category', String( gone ) );
		const item = itemId( 'category' );

		await page.goto( `${ TRASH }&view=redirect-old&item=${ item }` );
		await page.getByLabel( 'An address on this site' ).check();
		await page.getByLabel( 'Address to send visitors to' ).fill( 'https://elsewhere.example/page/' );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'Enter an address on this site' );
		expect( redirects() ).toEqual( [] );

		await page.getByLabel( 'An address on this site' ).check();
		await page.getByLabel( 'Address to send visitors to' ).fill( '/blog/' );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();
		await expect( page.locator( '.actt-old-redirect' ) ).toContainText( '/blog/' );
		expect( await status( request, '/category/gone-roasting/' ) ).toEqual( { status: 301, location: expect.stringContaining( '/blog/' ) } );

		wp( 'axle-category-tag-trash', 'restore', String( item ) );
		expect( redirects() ).toEqual( [] );
		expect( ( await status( request, '/category/gone-roasting/' ) ).status ).toBe( 200 );
	} );

	test( 'WP-CLI, the abilities and Site Health work with old addresses', async () => {
		const gone = term( 'category', 'Gone grilling' );
		const other = term( 'category', 'Moved grilling' );
		wp( 'term', 'delete', 'category', String( gone ) );
		const item = itemId( 'category' );

		wpEval( "update_option( 'axle_category_tag_trash_old_hits', array( 'category/gone-grilling' => array( 'n' => 40, 'last' => time(), 'd' => array( gmdate( 'Ymd' ) => 40 ) ) ), false );" );

		const listed = JSON.parse( wp( 'axle-category-tag-trash', 'old-addresses', '--min=10', '--format=json' ) );
		expect( listed.map( ( row ) => [ row.old, row.week ] ) ).toEqual( [ [ 'category/gone-grilling', 40 ] ] );
		expect( wp( 'axle-category-tag-trash', 'old-addresses', '--min=100', '--format=count' ) ).toBe( '0' );

		const health = JSON.parse(
			wpEval( "wp_set_current_user( 1 ); $t = apply_filters( 'site_status_tests', array( 'direct' => array() ) ); echo wp_json_encode( call_user_func( $t['direct']['axle_category_tag_trash_old_visits']['test'] ) );" )
		);
		expect( health.status ).toBe( 'recommended' );
		expect( health.description ).not.toContain( 'Gone grilling' );

		if ( 'yes' === wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ) ) {
			const visits = JSON.parse( wpEval( "wp_set_current_user( 1 ); echo wp_json_encode( wp_get_ability( 'axle-category-tag-trash/list-old-address-visits' )->execute( array( 'minimum' => 10 ) ) );" ) );
			expect( visits.items[ 0 ].visits_week ).toBe( 40 );
			const added = JSON.parse( wpEval( `wp_set_current_user( 1 ); echo wp_json_encode( wp_get_ability( 'axle-category-tag-trash/redirect-old-address' )->execute( array( 'id' => ${ item }, 'term' => ${ other } ) ) );` ) );
			expect( added.path ).toBe( 'category/gone-grilling' );
			wp( 'axle-category-tag-trash', 'redirects', 'remove', 'category/gone-grilling' );
		}

		wp( 'axle-category-tag-trash', 'redirect-old', String( item ), `--term=${ other }` );
		expect( redirects().map( ( row ) => row.old ) ).toEqual( [ 'category/gone-grilling' ] );
		expect( wp( 'axle-category-tag-trash', 'old-addresses', '--format=count' ) ).toBe( '0' );

		wp( 'axle-category-tag-trash', 'delete', String( item ) );
		expect( wp( 'option', 'get', 'axle_category_tag_trash_old_paths', '--format=json' ) ).toBe( '[]' );
	} );

	test( 'a person who may not change redirects of the taxonomy sees no visits and gets no redirect form', async ( { browser } ) => {
		wpEval( `
			$dir = ABSPATH . 'wp-content/mu-plugins';
			if ( ! is_dir( $dir ) ) { mkdir( $dir ); }
			file_put_contents( ABSPATH . '${ MU }', '<?php
add_filter( "axle_category_tag_trash_can_access_taxonomy", function ( $allowed, $taxonomy, $context ) {
	return "redirects" === $context && "post_tag" === $taxonomy ? false : $allowed;
}, 10, 3 );' );
		` );
		try {
			wp( 'role', 'create', 'tag_viewer', 'Tag viewer' );
		} catch ( error ) {
			// Left over.
		}
		wp( 'cap', 'add', 'tag_viewer', 'read' );
		wp( 'cap', 'add', 'tag_viewer', 'manage_categories' );
		try {
			wp( 'user', 'create', 'tagviewer', 'tagviewer@example.com', '--role=tag_viewer', `--user_pass=${ PASSWORD }` );
		} catch ( error ) {
			// Left over.
		}

		try {
			const tag = term( 'post_tag', 'Hidden visits' );
			wp( 'term', 'delete', 'post_tag', String( tag ) );
			const item = itemId( 'post_tag' );
			wpEval( "update_option( 'axle_category_tag_trash_old_hits', array( 'tag/hidden-visits' => array( 'n' => 9, 'last' => time(), 'd' => array( gmdate( 'Ymd' ) => 9 ) ) ), false );" );

			const { context, page } = await logInAs( browser, 'tagviewer', PASSWORD );
			try {
				await page.goto( TRASH );
				const row = page.locator( '#the-list tr', { hasText: 'Hidden visits' } );
				await expect( row ).toBeVisible();
				await expect( row ).not.toContainText( 'visited' );
				await expect( row.getByRole( 'link', { name: 'Redirect its old address...' } ) ).toHaveCount( 0 );

				await page.goto( `${ TRASH }&view=redirect-old&item=${ item }` );
				await expect( page.locator( '.actt-redirect-old' ) ).toHaveCount( 0 );
				await expect( page.locator( 'body' ) ).not.toContainText( 'Hidden visits' );
				await expect( page.locator( 'body' ) ).not.toContainText( 'hidden-visits' );
			} finally {
				await context.close();
			}
		} finally {
			wpEval( `@unlink( ABSPATH . '${ MU }' );` );
			try {
				wp( 'user', 'delete', 'tagviewer', '--yes' );
				wp( 'role', 'delete', 'tag_viewer' );
			} catch ( error ) {
				// Best effort.
			}
		}
	} );
} );
