# Reference

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `axle_category_tag_trash_supports_taxonomy` | filter | `( bool $keep, string $taxonomy )`. Return `false` to delete a taxonomy's terms for good, as WordPress normally does. Menus (`nav_menu`) are never kept |
| `axle_category_tag_trash_keep_term` | filter | `( bool $keep, WP_Term $term )`. Whether one deleted term goes to the trash, for example to let empty tags go for good |
| `axle_category_tag_trash_retention_days` | filter | `( int $days )`. Days a trashed term is kept. Wins over the setting on the screen |
| `axle_category_tag_trash_retention_choices` | filter | `( int[] $days )`. Periods offered on the screen. Default 7, 30, 90 and 365 |
| `axle_category_tag_trash_undo_seconds` | filter | `( int $seconds )`. How long the Undo notice after a bulk delete stays. Default 300 |
| `axle_category_tag_trash_trashed` | action | `( int $item_id, Snapshot $snapshot )`. A deleted term landed in the trash |
| `axle_category_tag_trash_deleted` | action | `( int $item_id, Snapshot $snapshot )`. A trashed term is about to be deleted for good: Empty trash, Delete permanently or the daily clean-up. Not on restore |
| `axle_category_tag_trash_purged` | action | `( int $deleted )`. The daily clean-up deleted this many expired terms |
| `axle_category_tag_trash_restored` | action | `( int $term_id, string $taxonomy, Snapshot $snapshot, int[] $object_ids )`. Fires once the term is back. `$object_ids` holds the objects re-attached in that request, at most 5,000 |
| `axle_category_tag_trash_objects_restored` | action | `( int $term_id, string $taxonomy, int[] $object_ids )`. Fires for every batch of objects re-attached, in the request and in the background |
| `axle_category_tag_trash_restore_complete` | action | `( int $term_id, string $taxonomy, Snapshot $snapshot )`. Fires once every object is back |
| `axle_category_tag_trash_restore_batch_size` | filter | `( int $size )`. Objects re-attached per request or cron run. Default 5000 |
| `axle_category_tag_trash_capability` | filter | `( string $capability )`. Capability for Tools > Axle Category & Tag Trash. Default `manage_categories`. Each restore and delete still checks the term's own taxonomy |

```php
// Never keep deleted tags.
add_filter( 'axle_category_tag_trash_supports_taxonomy', function ( $keep, $taxonomy ) {
	return 'post_tag' === $taxonomy ? false : $keep;
}, 10, 2 );

// Let empty categories go for good, keep the rest.
add_filter( 'axle_category_tag_trash_keep_term', fn( $keep, $term ) => $keep && $term->count > 0, 10, 2 );

// Offer a 14-day option.
add_filter( 'axle_category_tag_trash_retention_choices', fn( $days ) => array_merge( $days, array( 14 ) ) );
```

## Abilities

On WordPress 6.9 and later, Axle Category & Tag Trash registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. An agent that deleted the wrong category can put it back.

| Ability | Does | Needs |
| --- | --- | --- |
| `axle-category-tag-trash/list` | Read-only. Trashed terms, newest first, with what a restore brings back. Input: `taxonomy`, `search`, `limit`, `page` | the Axle Category & Tag Trash capability. Terms of taxonomies you cannot manage are left out |
| `axle-category-tag-trash/restore` | Restores trash item `id` with its posts, child terms and menu links | the Axle Category & Tag Trash capability and the term's own taxonomy |
| `axle-category-tag-trash/delete` | Deletes trash item `id` for good. Destructive, so the REST API runs it with `DELETE` | the Axle Category & Tag Trash capability and the term's own taxonomy |

## WP-CLI

```sh
wp axle-category-tag-trash list [--taxonomy=<taxonomy>] [--format=table|csv|json|count|ids]
wp axle-category-tag-trash restore <id>...
wp axle-category-tag-trash delete <id>...
wp axle-category-tag-trash delete --all [--yes]
```

`<id>` is the trash item ID from `wp axle-category-tag-trash list`, not the term ID. `restore` re-attaches every object in the same run, even for a term on a million posts, and also finishes a restore that is running in the background.

## PHP API

```php
$plugin = AryanX\Axle\CategoryTagTrash\Plugin::instance();

$plugin->trash()->find( array( 'taxonomy' => 'category', 'per_page' => 100 ) ); // [ 'ids' => [...], 'total' => n ], at most 500 per page
$plugin->restorer()->restore( $item_id );        // [ 'term_id' => n, 'notes' => [...], 'background' => bool ] or WP_Error
$plugin->restorer()->restore( $item_id, true );  // the same, with every object re-attached before it returns
```

## How a restore works

The term is captured on `pre_delete_term`, before WordPress touches posts or child terms, and saved on `delete_term` once the delete has really happened.

Restoring:

- inserts the term row with its original `term_id` and `term_taxonomy_id` when they are still free, and otherwise gives it new ones and says so
- re-adds the meta, moves children back only if nobody moved them since, and recreates the menu items in their old position
- re-attaches objects with their `term_order` in batches, skipping objects deleted in the meantime, and fires `added_term_relationship` and `set_object_terms` for each one so search indexes such as ElasticPress and VIP Enterprise Search pick them up
- takes the default term (Uncategorized) back from objects that only got it because of the delete, if nobody changed their terms since

## Large sites

A term on a million posts is fine:

- **Delete:** relationships are read 1,000 at a time and kept as packed `id:order` strings, one meta row per 1,000. The snapshot itself stays a few KB. The work grows with the number of posts, the same way WordPress's own delete does.
- **Restore:** the first 5,000 objects come back in the request. The rest are re-attached through WP-Cron, 5,000 per run, with a lock so two runs never overlap. The trash screen says so, and the item leaves the list while it runs. `wp axle-category-tag-trash restore <id>` finishes it in one go.
- **Empty trash:** deletes 200 items in the request and the rest through WP-Cron.

## Data

Each trashed term is an `axle_ctt_item` post (hidden, not exported, kept when its author is deleted). Its post meta holds:

| Meta key | What |
| --- | --- |
| `_axle_category_tag_trash_snapshot` | the term row, meta, children, menu links, object count and a sample of object IDs |
| `_axle_category_tag_trash_objects` | one row per 1,000 objects, packed as `id:order,id:order` |
| `_axle_category_tag_trash_fallback` | one row per 1,000 objects that only had this term, packed as `id,id` |
| `_axle_category_tag_trash_restore_state` | where a background restore is up to |

Items in the trash have the status `publish`, items being restored in the background `pending`. The setting is the `axle_category_tag_trash_settings` option. The cron events are `axle_category_tag_trash_purge` (daily), `axle_category_tag_trash_empty` and `axle_category_tag_trash_restore_batch`.

Uninstalling removes all of it on every site of a multisite network, 100 sites at a time.
