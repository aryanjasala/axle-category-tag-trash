// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 760 }, deviceScaleFactor: 2 } );
	let recipes;

	test.beforeAll( () => {
		prepareSite( 'Kitchen Notes' );
		for ( const option of [ 'axle_category_tag_trash_redirects', 'axle_category_tag_trash_redirect_hits', 'axle_category_tag_trash_old_hits', 'axle_category_tag_trash_old_paths' ] ) {
			try {
				wp( 'option', 'delete', option );
			} catch ( error ) {
				// Not there.
			}
		}
		recipes = wp( 'term', 'create', 'category', 'Recipes', '--description=Everything we cook', '--porcelain' );
		wp( 'term', 'create', 'category', 'Desserts', `--parent=${ recipes }` );
		const travel = wp( 'term', 'create', 'category', 'Travel', '--porcelain' );
		wp( 'term', 'create', 'category', 'News' );
		for ( const title of [ 'Sourdough basics', 'Weeknight dal', 'Lemon tart' ] ) {
			wp( 'post', 'create', `--post_title=${ title }`, '--post_status=publish', `--post_category=${ recipes }` );
		}
		wp( 'post', 'create', '--post_title=Three days in Hampi', '--post_status=publish', `--post_category=${ travel }` );
		const menu = wp( 'menu', 'create', 'Main menu', '--porcelain' );
		wp( 'menu', 'item', 'add-term', menu, 'category', recipes );
	} );

	test.afterAll( () => {
		wp( 'site', 'empty', '--yes' );
	} );

	test( '1: Undo right after deleting a category', async ( { page } ) => {
		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await page.goto( '/wp-admin/edit-tags.php?taxonomy=category' );
		const row = page.locator( `#tag-${ recipes }` );
		await row.hover();
		await row.locator( '.delete-tag' ).click();
		await expect( page.locator( '.axle-category-tag-trash-undo' ) ).toBeVisible();
		await expect( row ).toHaveCount( 0 );
		await shot( page, 1 );
	} );

	test( '2: Tools > Category & Tag Trash', async ( { page } ) => {
		wp( 'term', 'delete', 'category', wp( 'term', 'get', 'category', 'travel', '--by=slug', '--field=term_id' ) );
		const tag = wp( 'term', 'create', 'post_tag', 'summer', '--porcelain' );
		wp( 'term', 'delete', 'post_tag', tag );
		// The old address of Recipes got visits from people.
		wp( 'eval', "update_option( 'axle_category_tag_trash_old_hits', array( 'category/recipes' => array( 'n' => 42, 'last' => time(), 'd' => array( gmdate( 'Ymd' ) => 42 ) ) ), false );" );
		await page.goto( '/wp-admin/tools.php?page=axle-category-tag-trash' );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 3 );
		await expect( page.locator( '.actt-visits' ) ).toContainText( '42 times this week' );
		await shot( page, 2 );
	} );

	test( '3: what a restore brings back', async ( { page } ) => {
		await page.goto( '/wp-admin/tools.php?page=axle-category-tag-trash' );
		const row = page.locator( '#the-list tr', { hasText: 'Recipes' } );
		await row.locator( '.actt-details summary' ).click();
		await row.hover();
		await shot( page, 3, { keepHover: true } );
	} );

	test( '4: redirect the old address of a deleted category', async ( { page } ) => {
		const item = wp( 'axle-category-tag-trash', 'list', '--format=ids' ).split( ' ' ).pop();
		await page.goto( `/wp-admin/tools.php?page=axle-category-tag-trash&view=redirect-old&item=${ item }` );
		await page.getByLabel( 'Term to send visitors to' ).selectOption( { label: 'News' } );
		await shot( page, 4 );
	} );

	test( '5: the Redirects tab', async ( { page } ) => {
		wp( 'term', 'update', 'category', wp( 'term', 'get', 'category', 'news', '--by=slug', '--field=term_id' ), '--slug=latest-news' );
		wp( 'axle-category-tag-trash', 'redirect-old', wp( 'axle-category-tag-trash', 'list', '--format=ids' ).split( ' ' ).pop(), `--term=${ wp( 'term', 'get', 'category', 'latest-news', '--by=slug', '--field=term_id' ) }` );
		wp( 'option', 'update', 'axle_category_tag_trash_redirect_hits', JSON.stringify( { 'category/news': { n: 12, last: Math.floor( Date.now() / 1000 ) - 3600 } } ), '--format=json' );
		await page.goto( '/wp-admin/tools.php?page=axle-category-tag-trash&view=redirects' );
		await expect( page.locator( '.actt-redirects tbody tr' ) ).toHaveCount( 3 );
		await shot( page, 5 );
	} );
} );
