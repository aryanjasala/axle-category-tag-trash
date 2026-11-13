// A user who manages tags and categories, but not a custom taxonomy with its own capabilities, must not
// see, restore, delete or merge that taxonomy's terms: not on the screen, not through the Abilities API.
const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );

const TRASH = '/wp-admin/tools.php?page=axle-category-tag-trash';
const MU = 'wp-content/mu-plugins/ctt-e2e-brands.php';
const PASSWORD = 'tagger-Pass-123!';

const trashItems = ( taxonomy ) => wp( 'axle-category-tag-trash', 'list', `--taxonomy=${ taxonomy }`, '--format=ids' ).split( ' ' ).filter( Boolean ).map( Number );
const path = ( name ) => `/wp-abilities/v1/abilities/axle-category-tag-trash/${ name }/run`;

test.describe( 'Access follows the capabilities of each taxonomy', () => {
	let brandItem;
	let tagItem;
	let brandTerm;
	let tagTerm;

	test.beforeAll( () => {
		// A taxonomy with its own capabilities, and application passwords on this plain HTTP site.
		wpEval( `
			$dir = ABSPATH . 'wp-content/mu-plugins';
			if ( ! is_dir( $dir ) ) { mkdir( $dir ); }
			file_put_contents( ABSPATH . '${ MU }', '<?php
add_filter( "wp_is_application_passwords_available", "__return_true" );
add_action( "init", function () {
	register_taxonomy( "ctt_brand", "post", array(
		"label" => "Brands", "public" => true, "show_ui" => true, "hierarchical" => false,
		"capabilities" => array( "manage_terms" => "manage_brands", "edit_terms" => "manage_brands", "delete_terms" => "manage_brands", "assign_terms" => "edit_posts" ),
	) );
} );' );
		` );
		wp( 'cap', 'add', 'administrator', 'manage_brands' );
		try {
			wp( 'role', 'create', 'tag_manager', 'Tag manager' );
		} catch ( error ) {
			// Left over from an earlier run.
		}
		wp( 'cap', 'add', 'tag_manager', 'read' );
		wp( 'cap', 'add', 'tag_manager', 'manage_categories' );
		try {
			wp( 'user', 'create', 'tagger', 'tagger@example.com', '--role=tag_manager', `--user_pass=${ PASSWORD }` );
		} catch ( error ) {
			// Left over from an earlier run.
		}

		brandTerm = Number( wp( 'term', 'create', 'ctt_brand', 'Acme brand', '--porcelain' ) );
		tagTerm = Number( wp( 'term', 'create', 'post_tag', 'Visible tag', '--porcelain' ) );
		wp( 'term', 'delete', 'ctt_brand', String( brandTerm ) );
		wp( 'term', 'delete', 'post_tag', String( tagTerm ) );
		[ brandItem ] = trashItems( 'ctt_brand' );
		[ tagItem ] = trashItems( 'post_tag' );
	} );

	test.afterAll( () => {
		wp( 'site', 'empty', '--yes' );
		wpEval( `@unlink( ABSPATH . '${ MU }' );` );
		try {
			wp( 'user', 'delete', 'tagger', '--yes' );
			wp( 'role', 'delete', 'tag_manager' );
			wp( 'cap', 'remove', 'administrator', 'manage_brands' );
		} catch ( error ) {
			// Best effort.
		}
	} );

	test( 'the screen shows, counts and searches only what the user may manage', async ( { browser } ) => {
		const { context, page } = await logInAs( browser, 'tagger', PASSWORD );
		try {
			await page.goto( TRASH );
			await expect( page.locator( '.subsubsub' ) ).toContainText( 'Tags (1)' );
			await expect( page.locator( '.subsubsub' ) ).not.toContainText( 'Brands' );
			await expect( page.locator( '.subsubsub' ) ).toContainText( 'All (1)' );
			await expect( page.locator( '#the-list .actt-name' ) ).toHaveText( [ 'Visible tag' ] );
			await expect( page.locator( '.tablenav .displaying-num' ).first() ).toHaveText( '1 item' );

			await page.goto( `${ TRASH }&taxonomy=ctt_brand` );
			await expect( page.locator( '#the-list .actt-name' ) ).toHaveCount( 0 );
			await page.goto( `${ TRASH }&s=Acme` );
			await expect( page.locator( '#the-list .actt-name' ) ).toHaveCount( 0 );
			await expect( page.locator( 'body' ) ).not.toContainText( 'Acme brand' );

			// A hand-made bulk request for the brand item: the nonce is real, the item is not theirs.
			await page.goto( TRASH );
			const nonce = await page.locator( '#actt-list-form input[name="_wpnonce"]' ).inputValue();
			await page.goto( `${ TRASH }&action=restore&items[]=${ brandItem }&_wpnonce=${ nonce }` );
			await expect( page.locator( '.notice-error' ) ).toContainText( 'You are not allowed to restore "Acme brand".' );
			await page.goto( `${ TRASH }&action=delete&items[]=${ brandItem }&_wpnonce=${ nonce }` );
			expect( trashItems( 'ctt_brand' ) ).toEqual( [ brandItem ] );
			expect( wp( 'term', 'list', 'ctt_brand', '--format=count' ) ).toBe( '0' );

			// The merge screen refuses the taxonomy too.
			await page.goto( `${ TRASH }&view=merge&taxonomy=ctt_brand&terms=1,2` );
			await expect( page.locator( '.notice-error' ) ).toContainText( 'You cannot merge terms of this taxonomy' );

			// Empty trash only empties what they may delete, and says so.
			await page.goto( TRASH );
			await page.getByRole( 'button', { name: 'Empty trash' } ).click();
			await page.getByRole( 'button', { name: 'Delete everything for good' } ).click();
			await expect( page.locator( '.notice-success' ).first() ).toContainText( '1 term deleted for good.' );
			expect( trashItems( 'ctt_brand' ) ).toEqual( [ brandItem ] );
			expect( trashItems( 'post_tag' ) ).toEqual( [] );
		} finally {
			await context.close();
		}
	} );

	test( 'abilities: list, restore, delete, preview and merge check the taxonomy too', async () => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );

		// Another tag in the trash for them to see.
		const again = Number( wp( 'term', 'create', 'post_tag', 'Second visible tag', '--porcelain' ) );
		wp( 'term', 'delete', 'post_tag', String( again ) );
		const brandA = Number( wp( 'term', 'create', 'ctt_brand', 'Brand A', '--porcelain' ) );
		const brandB = Number( wp( 'term', 'create', 'ctt_brand', 'Brand B', '--porcelain' ) );
		const tagA = Number( wp( 'term', 'create', 'post_tag', 'Merge tag A', '--porcelain' ) );
		const tagB = Number( wp( 'term', 'create', 'post_tag', 'Merge tag B', '--porcelain' ) );

		const client = await appPasswordClient( 'tagger', 'e2e-tagger' );
		try {
			const listed = await client.call( 'GET', path( 'list' ) );
			expect( listed.status ).toBe( 200 );
			expect( listed.body.items.map( ( item ) => item.taxonomy ) ).toEqual( [ 'post_tag' ] );
			// The total never counts what they cannot see.
			expect( listed.body.total ).toBe( trashItems( 'post_tag' ).length );

			const brands = await client.call( 'GET', `${ path( 'list' ) }?input[taxonomy]=ctt_brand` );
			expect( brands.status ).toBe( 403 );
			expect( brands.body.code ).toBe( 'axle_category_tag_trash_forbidden' );

			expect( ( await client.call( 'POST', path( 'restore' ), { input: { id: brandItem } } ) ).status ).toBe( 403 );
			expect( ( await client.call( 'DELETE', `${ path( 'delete' ) }?input[id]=${ brandItem }` ) ).status ).toBe( 403 );
			expect( trashItems( 'ctt_brand' ) ).toEqual( [ brandItem ] );

			const input = { taxonomy: 'ctt_brand', source: brandA, target: brandB };
			expect( ( await client.call( 'POST', path( 'merge' ), { input } ) ).status ).toBe( 403 );
			expect( ( await client.call( 'GET', `${ path( 'preview-merge' ) }?input[taxonomy]=ctt_brand&input[source]=${ brandA }&input[target]=${ brandB }` ) ).status ).toBe( 403 );
			expect( wp( 'term', 'get', 'ctt_brand', String( brandA ), '--field=name' ) ).toBe( 'Brand A' );

			// Their own taxonomy works.
			const merged = await client.call( 'POST', path( 'merge' ), { input: { taxonomy: 'post_tag', source: tagA, target: tagB } } );
			expect( merged.status ).toBe( 200 );
			expect( merged.body.done ).toBe( true );
			expect( ( await client.call( 'POST', path( 'restore' ), { input: { id: merged.body.item_id } } ) ).status ).toBe( 200 );
		} finally {
			await client.dispose();
		}

		// An administrator with the brand capabilities can restore the brand.
		const admin = await appPasswordClient( 'admin', 'e2e-admin-brands' );
		try {
			const restored = await admin.call( 'POST', path( 'restore' ), { input: { id: brandItem } } );
			expect( restored.status ).toBe( 200 );
			expect( wp( 'term', 'get', 'ctt_brand', String( brandTerm ), '--field=name' ) ).toBe( 'Acme brand' );
		} finally {
			await admin.dispose();
		}
	} );

	test( 'redirects: the tab and the abilities only hold taxonomies the user may manage', async ( { browser } ) => {
		wp( 'option', 'update', 'axle_category_tag_trash_redirects', JSON.stringify( {
			'brand/old-acme': { taxonomy: 'ctt_brand', term: brandTerm, old_term: 0, kind: 'rename', created: 1000, chain: [] },
			'tag/old-visible': { taxonomy: 'post_tag', term: tagTerm, old_term: 0, kind: 'rename', created: 2000, chain: [] },
		} ), '--format=json' );

		const { context, page } = await logInAs( browser, 'tagger', PASSWORD );
		try {
			await page.goto( `${ TRASH }&view=redirects` );
			await expect( page.locator( '.actt-old' ) ).toHaveText( [ '/tag/old-visible/' ] );
			await expect( page.locator( 'body' ) ).not.toContainText( 'old-acme' );
		} finally {
			await context.close();
		}

		if ( 'yes' === wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ) ) {
			const client = await appPasswordClient( 'tagger', 'e2e-tagger-redirects' );
			try {
				const listed = await client.call( 'GET', path( 'list-redirects' ) );
				expect( listed.status ).toBe( 200 );
				expect( listed.body.items.map( ( item ) => item.path ) ).toEqual( [ 'tag/old-visible' ] );
				expect( ( await client.call( 'GET', `${ path( 'list-redirects' ) }?input[taxonomy]=ctt_brand` ) ).status ).toBe( 403 );
				expect( ( await client.call( 'DELETE', `${ path( 'remove-redirect' ) }?input[path]=brand/old-acme` ) ).status ).toBe( 403 );
				expect( wp( 'axle-category-tag-trash', 'redirects', 'list', '--format=ids' ) ).toContain( 'brand/old-acme' );
			} finally {
				await client.dispose();
			}
		}
		wp( 'option', 'delete', 'axle_category_tag_trash_redirects' );
	} );

	test( 'the filter can hide a taxonomy from everyone', async ( { browser } ) => {
		const id = Number( wp( 'term', 'create', 'post_tag', 'Hidden by filter', '--porcelain' ) );
		wp( 'term', 'delete', 'post_tag', String( id ) );
		wpEval( `file_put_contents( ABSPATH . 'wp-content/mu-plugins/ctt-e2e-hide.php', '<?php add_filter( "axle_category_tag_trash_can_access_taxonomy", function ( $allowed, $taxonomy ) { return "post_tag" === $taxonomy ? false : $allowed; }, 10, 2 );' );` );
		try {
			const { context, page } = await logInAs( browser, 'tagger', PASSWORD );
			await page.goto( TRASH );
			await expect( page.locator( 'body' ) ).not.toContainText( 'Hidden by filter' );
			await expect( page.locator( '#the-list .actt-name' ) ).toHaveCount( 0 );
			await context.close();
		} finally {
			wpEval( `@unlink( ABSPATH . 'wp-content/mu-plugins/ctt-e2e-hide.php' );` );
		}
	} );
} );
