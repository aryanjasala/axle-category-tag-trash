// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );

test( 'demo: delete, undo, then restore from Axle Category & Tag Trash', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Kitchen Notes' );
	const recipes = wp( 'term', 'create', 'category', 'Recipes', '--description=Everything we cook', '--porcelain' );
	wp( 'term', 'create', 'category', 'Desserts', `--parent=${ recipes }` );
	wp( 'term', 'create', 'category', 'Travel' );
	wp( 'term', 'create', 'category', 'News' );
	for ( const title of [ 'Sourdough basics', 'Weeknight dal', 'Lemon tart' ] ) {
		wp( 'post', 'create', `--post_title=${ title }`, '--post_status=publish', `--post_category=${ recipes }` );
	}
	const menu = wp( 'menu', 'create', 'Main menu', '--porcelain' );
	wp( 'menu', 'item', 'add-term', menu, 'category', recipes );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/edit-tags.php?taxonomy=category' );
			await demo.start();
			await demo.pause( 1000 );

			// Delete, then take it back with Undo.
			const row = page.locator( `#tag-${ recipes }` );
			await demo.moveTo( row.locator( '.row-title' ) );
			await demo.click( row.locator( '.delete-tag' ), 300 );
			const notice = page.locator( '.axle-category-tag-trash-undo' );
			await expect( notice ).toBeVisible();
			await demo.pause( 1800 );
			await demo.click( notice.getByRole( 'button', { name: 'Undo' } ) );
			await expect( page.locator( `#tag-${ recipes }` ) ).toBeVisible();
			await demo.pause( 1500 );

			// Delete it again and restore it later from Tools > Axle Category & Tag Trash.
			const again = page.locator( `#tag-${ recipes }` );
			await demo.moveTo( again.locator( '.row-title' ) );
			await demo.click( again.locator( '.delete-tag' ), 300 );
			await expect( page.locator( '.axle-category-tag-trash-note a' ) ).toHaveText( 'View Axle Category & Tag Trash (1)' );
			await demo.pause( 800 );
			await demo.click( page.locator( '.axle-category-tag-trash-note a' ), 300 );

			const trashed = page.locator( '#the-list tr', { hasText: 'Recipes' } );
			await expect( trashed ).toBeVisible();
			await demo.pause( 1000 );
			await demo.click( trashed.locator( '.actt-details summary' ) );
			await demo.pause( 2200 );
			await demo.moveTo( trashed.locator( '.actt-name' ) );
			await demo.click( trashed.locator( '.actt-restore' ), 300 );
			await expect( page.locator( '.actt-restored' ) ).toContainText( 'Restored Recipes.' );
			await demo.pause( 1500 );

			await page.goto( '/wp-admin/edit-tags.php?taxonomy=category' );
			await expect( page.locator( `#tag-${ recipes }` ) ).toBeVisible();
			await demo.moveTo( page.locator( `#tag-${ recipes } .column-posts` ) );
			await demo.pause( 1500 );
		} );
	} finally {
		wp( 'site', 'empty', '--yes' );
	}
} );
