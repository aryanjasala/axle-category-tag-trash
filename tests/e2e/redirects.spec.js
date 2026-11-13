const { test, expect, wp, wpEval } = require( './utils' );

const TRASH = '/wp-admin/tools.php?page=axle-category-tag-trash';
const REDIRECTS = `${ TRASH }&view=redirects`;

const term = ( taxonomy, name, parent = 0 ) => Number( wp( 'term', 'create', taxonomy, name, `--parent=${ parent }`, '--porcelain' ) );
const stored = () => JSON.parse( wp( 'axle-category-tag-trash', 'redirects', 'list', '--format=json' ) || '[]' );
const paths = () => stored().map( ( row ) => row.old ).sort();

/**
 * Requests an address without following redirects.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request context.
 * @param {string}                                       url     Path.
 * @return {Promise<{status: number, location: string}>} Status and Location header.
 */
async function hit( request, url ) {
	const response = await request.get( url, { maxRedirects: 0, failOnStatusCode: false } );
	return { status: response.status(), location: response.headers().location || '' };
}

test.describe( 'Old term links keep working', () => {
	test.afterAll( () => {
		wp( 'site', 'empty', '--yes' );
		wp( 'option', 'delete', 'axle_category_tag_trash_redirects' );
		wp( 'option', 'delete', 'axle_category_tag_trash_redirect_hits' );
	} );

	test.beforeEach( () => {
		wp( 'option', 'delete', 'axle_category_tag_trash_redirects' );
		wp( 'option', 'delete', 'axle_category_tag_trash_redirect_hits' );
	} );

	test( 'a changed slug redirects the address, its feed and its numbered pages, and chains collapse', async ( { request } ) => {
		const id = term( 'category', 'Old seo' );
		wp( 'term', 'update', 'category', String( id ), '--slug=old-search' );

		let result = await hit( request, '/category/old-seo/' );
		expect( result.status ).toBe( 301 );
		expect( result.location ).toBe( 'http://localhost:8910/category/old-search/' );

		result = await hit( request, '/category/old-seo/feed/' );
		expect( result.status ).toBe( 301 );
		expect( result.location ).toBe( 'http://localhost:8910/category/old-search/feed/' );

		result = await hit( request, '/category/old-seo/page/2/' );
		expect( result.status ).toBe( 301 );
		expect( result.location ).toBe( 'http://localhost:8910/category/old-search/page/2/' );

		result = await hit( request, '/category/old-seo/?utm_source=news' );
		expect( result.location ).toContain( 'utm_source=news' );

		// Renamed again: the first old address goes straight to the last one.
		wp( 'term', 'update', 'category', String( id ), '--slug=old-find' );
		expect( paths() ).toEqual( [ 'category/old-search', 'category/old-seo' ] );
		result = await hit( request, '/category/old-seo/' );
		expect( result.location ).toBe( 'http://localhost:8910/category/old-find/' );
		result = await hit( request, '/category/old-search/' );
		expect( result.location ).toBe( 'http://localhost:8910/category/old-find/' );

		// The live address is not touched, and an address that never existed is still a 404.
		expect( ( await hit( request, '/category/old-find/' ) ).status ).toBe( 200 );
		expect( ( await hit( request, '/category/never-was/' ) ).status ).toBe( 404 );
	} );

	test( 'a new term that takes the old slug wins', async ( { request } ) => {
		const id = term( 'category', 'Wins before' );
		wp( 'term', 'update', 'category', String( id ), '--slug=wins-after' );
		expect( ( await hit( request, '/category/wins-before/' ) ).status ).toBe( 301 );

		term( 'category', 'Wins before' );
		expect( ( await hit( request, '/category/wins-before/' ) ).status ).toBe( 200 );
	} );

	test( 'a changed parent redirects the child at its old address, and a parent slug change moves its children', async ( { request } ) => {
		const parent = term( 'category', 'Parent a' );
		const other = term( 'category', 'Parent b' );
		const child = term( 'category', 'Kid', parent );

		wp( 'term', 'update', 'category', String( child ), `--parent=${ other }` );
		let result = await hit( request, '/category/parent-a/kid/' );
		expect( result.status ).toBe( 301 );
		expect( result.location ).toBe( 'http://localhost:8910/category/parent-b/kid/' );

		wp( 'term', 'update', 'category', String( other ), '--slug=parent-c' );
		result = await hit( request, '/category/parent-b/kid/' );
		expect( result.location ).toBe( 'http://localhost:8910/category/parent-c/kid/' );
		result = await hit( request, '/category/parent-a/kid/' );
		expect( result.location ).toBe( 'http://localhost:8910/category/parent-c/kid/' );
	} );

	test( 'a merge redirects the old term, and Undo takes the redirect back', async ( { request } ) => {
		const source = term( 'post_tag', 'Merge from' );
		const target = term( 'post_tag', 'Merge to' );
		wp( 'axle-category-tag-trash', 'merge', String( source ), String( target ), '--taxonomy=post_tag' );

		const result = await hit( request, '/tag/merge-from/' );
		expect( result.status ).toBe( 301 );
		expect( result.location ).toBe( 'http://localhost:8910/tag/merge-to/' );
		expect( stored()[ 0 ].kind ).toBe( 'merge' );

		const item = wp( 'axle-category-tag-trash', 'list', '--taxonomy=post_tag', '--format=ids' ).split( ' ' )[ 0 ];
		wp( 'axle-category-tag-trash', 'restore', item );
		expect( paths() ).toEqual( [] );
		expect( ( await hit( request, '/tag/merge-from/' ) ).status ).toBe( 200 );
	} );

	test( 'a deleted term shows a 404 unless it was given a target', async ( { request } ) => {
		const plain = term( 'post_tag', 'Gone plain' );
		const kept = term( 'post_tag', 'Gone kept' );
		const target = term( 'post_tag', 'Gone target' );

		wp( 'term', 'meta', 'update', String( kept ), 'axle_category_tag_trash_redirect_to', String( target ) );
		wp( 'term', 'delete', 'post_tag', String( plain ) );
		wp( 'term', 'delete', 'post_tag', String( kept ) );

		expect( ( await hit( request, '/tag/gone-plain/' ) ).status ).toBe( 404 );
		const result = await hit( request, '/tag/gone-kept/' );
		expect( result.status ).toBe( 301 );
		expect( result.location ).toBe( 'http://localhost:8910/tag/gone-target/' );
	} );

	test( 'the target can be picked on the term edit screen', async ( { page, request } ) => {
		const id = term( 'category', 'Edit pick' );
		const target = term( 'category', 'Edit pick target' );

		await page.goto( `/wp-admin/term.php?taxonomy=category&tag_ID=${ id }` );
		await page.getByLabel( 'If this term is deleted' ).selectOption( String( target ) );
		await page.getByRole( 'button', { name: 'Update' } ).click();
		expect( wp( 'term', 'meta', 'get', String( id ), 'axle_category_tag_trash_redirect_to' ) ).toBe( String( target ) );

		wp( 'term', 'delete', 'category', String( id ) );
		expect( ( await hit( request, '/category/edit-pick/' ) ).status ).toBe( 301 );
	} );

	test( 'the Redirects tab lists, counts hits, removes with a confirm, and undoes', async ( { page, request } ) => {
		await page.goto( REDIRECTS );
		await expect( page.locator( '.axle-empty' ) ).toContainText( 'No old addresses redirect yet.' );

		const id = term( 'category', 'Tab before' );
		wp( 'term', 'update', 'category', String( id ), '--slug=tab-after' );
		await hit( request, '/category/tab-before/' );
		await hit( request, '/category/tab-before/' );

		await page.goto( REDIRECTS );
		await expect( page.getByRole( 'link', { name: 'Redirects', exact: true } ) ).toHaveAttribute( 'aria-current', 'page' );
		const row = page.locator( '.actt-redirects tbody tr' );
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( '.actt-old' ) ).toHaveText( '/category/tab-before/' );
		await expect( row.locator( '.actt-new' ) ).toHaveText( '/category/tab-after/' );
		await expect( row ).toContainText( 'Slug changed' );
		await expect( row.locator( 'td' ).nth( 4 ) ).toContainText( '2' );

		await row.getByRole( 'link', { name: /Remove the redirect from/ } ).click();
		await expect( page.locator( '.actt-row-confirm' ) ).toContainText( 'You can undo this right after.' );
		await page.getByRole( 'link', { name: 'Remove redirect' } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Removed 1 redirect.' );
		expect( paths() ).toEqual( [] );
		expect( ( await hit( request, '/category/tab-before/' ) ).status ).toBe( 404 );

		await page.getByRole( 'link', { name: 'Undo' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Put back 1 redirect.' );
		expect( paths() ).toEqual( [ 'category/tab-before' ] );
		await expect( page.locator( '.actt-redirects tbody tr td' ).nth( 4 ) ).toContainText( '2' );
		expect( ( await hit( request, '/category/tab-before/' ) ).status ).toBe( 301 );
	} );

	test( 'a redirect whose target term was deleted says so, and WP-CLI removes it', async ( { page } ) => {
		const id = term( 'category', 'Dead before' );
		wp( 'term', 'update', 'category', String( id ), '--slug=dead-after' );
		wp( 'term', 'delete', 'category', String( id ) );

		await page.goto( REDIRECTS );
		await expect( page.locator( '.actt-redirects' ) ).toContainText( 'Target deleted' );

		wp( 'axle-category-tag-trash', 'redirects', 'remove', 'category/dead-before' );
		expect( paths() ).toEqual( [] );
	} );

	test( 'abilities list and remove redirects', async () => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );

		const id = term( 'category', 'Ability before' );
		wp( 'term', 'update', 'category', String( id ), '--slug=ability-after' );

		const listed = JSON.parse( wpEval( "wp_set_current_user( 1 ); echo wp_json_encode( wp_get_ability( 'axle-category-tag-trash/list-redirects' )->execute( array() ) );" ) );
		expect( listed.total ).toBe( 1 );
		expect( listed.items[ 0 ].path ).toBe( 'category/ability-before' );
		expect( listed.items[ 0 ].new_url ).toContain( '/category/ability-after/' );

		const removed = JSON.parse( wpEval( "wp_set_current_user( 1 ); echo wp_json_encode( wp_get_ability( 'axle-category-tag-trash/remove-redirect' )->execute( array( 'path' => 'category/ability-before' ) ) );" ) );
		expect( removed.removed ).toBe( true );
		expect( paths() ).toEqual( [] );
	} );
} );
