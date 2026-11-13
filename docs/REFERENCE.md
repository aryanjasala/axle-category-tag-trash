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
| `axle_category_tag_trash_can_access_taxonomy` | filter | `( bool $allowed, string $taxonomy, string $context, int $user_id )`. Whether the user may use the trash for a taxonomy. `$context` is `list`, `restore`, `delete`, `merge` or `redirects`. The default follows the taxonomy's own `manage_terms`, `edit_terms` and `delete_terms` capabilities. Runs on the trash screen, row and bulk actions, the term screens and abilities. WP-CLI has no user, so it is not checked |
| `axle_category_tag_trash_can_merge` | filter | `( bool $allowed, WP_Term $source, WP_Term $target )`. Whether the user may merge this pair, after the taxonomy and per-term capability checks |
| `axle_category_tag_trash_merge_children` | filter | `( string $policy, WP_Term $source, WP_Term $target )`. What happens to the source's child terms: `target` (default) moves them under the target, `parent` moves them up one level like a normal delete |
| `axle_category_tag_trash_merge_batch_size` | filter | `( int $size )`. Posts a merge moves per request or cron run. Default 5000, at least 100 |
| `axle_category_tag_trash_merge_search_limit` | filter | `( int $limit, string $taxonomy )`. Terms listed as possible targets on the merge screen. Default 20, at most 100 |
| `axle_category_tag_trash_merged_notice` | filter | `( string $message, int[] $item_ids, int $moved )`. Text of the notice after a merge |
| `axle_category_tag_trash_before_merge` | action | `( WP_Term $source, WP_Term $target, string $children )`. A merge is about to start |
| `axle_category_tag_trash_merge_batch_moved` | action | `( int $target_id, int $source_id, int[] $object_ids )`. A batch of posts now has the target term |
| `axle_category_tag_trash_merged` | action | `( int $item_id, WP_Term $source, WP_Term $target, int $moved )`. A merge finished and the old term is in the trash. `$item_id` is 0 when a filter kept it out of the trash |
| `axle_category_tag_trash_merge_undone` | action | `( int $term_id, int $target_id, string $taxonomy, Snapshot $snapshot )`. A merge was undone: the old term is back on all its posts |
| `axle_category_tag_trash_redirects_enabled` | filter | `( bool $enabled )`. Return `false` to stop recording and serving old term addresses. Default `true` |
| `axle_category_tag_trash_redirect_supports_taxonomy` | filter | `( bool $yes, string $taxonomy )`. Whether a taxonomy's old addresses redirect. Default `true` for public taxonomies with a rewrite rule, and only with pretty permalinks |
| `axle_category_tag_trash_redirect_target_url` | filter | `( string $url, string $old_path, array $entry )`. The address an old address redirects to. Return an empty string to not redirect |
| `axle_category_tag_trash_redirect_status` | filter | `( int $status )`. 301 (default), 302, 307 or 308 |
| `axle_category_tag_trash_redirect_limit` | filter | `( int $limit )`. Redirects kept. The oldest go first. Default 1000 |
| `axle_category_tag_trash_redirect_descendant_limit` | filter | `( int $limit )`. Child terms checked for a changed address when a parent changes. Default 200 |
| `axle_category_tag_trash_redirect_choices_limit` | filter | `( int $limit, string $taxonomy )`. Terms offered under "If this term is deleted". Default 200 |
| `axle_category_tag_trash_redirects_per_page` | filter | `( int $per_page )`. Rows per page on the Redirects tab. Default 50 |
| `axle_category_tag_trash_redirect_removed_notice` | filter | `( string $message, int $removed )`. Text of the notice after a redirect is removed |
| `axle_category_tag_trash_redirect_added` | action | `( string $old_path, array $entry )`. An old address now redirects. `$old_path` is relative to the site address. `$entry` has `taxonomy`, `term` (the target term ID), `old_term`, `kind` (`rename`, `move`, `merge` or `trash`), `created` and `chain` |
| `axle_category_tag_trash_redirect_removed` | action | `( string $old_path, array $entry )`. An old address stopped redirecting |
| `axle_category_tag_trash_redirected` | action | `( string $path, string $url, array $entry )`. Just before a request is redirected |

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
| `axle-category-tag-trash/list` | Read-only. Trashed terms, newest first, with what a restore brings back and `merged_into` for merged terms. Input: `taxonomy`, `search`, `limit`, `page` | the Axle Category & Tag Trash capability, and `manage_terms` of the taxonomy. Taxonomies you cannot manage are left out of the items and the total, and asking for one is a 403 |
| `axle-category-tag-trash/restore` | Restores trash item `id` with its posts, child terms and menu links. For a merged-away term it undoes the merge | the Axle Category & Tag Trash capability, and `manage_terms` and `edit_terms` of the term's taxonomy |
| `axle-category-tag-trash/delete` | Deletes trash item `id` for good. Destructive, so the REST API runs it with `DELETE` | the Axle Category & Tag Trash capability, and `manage_terms` and `delete_terms` of the term's taxonomy |
| `axle-category-tag-trash/preview-merge` | Read-only. What merging `source` into `target` would do: posts that move, posts that already have the target, child terms, menu links. Input: `taxonomy`, `source`, `target`, `children` | as `merge` |
| `axle-category-tag-trash/merge` | Moves every post of `source` to `target` and trashes `source`. Destructive, but restoring the trash item undoes it. Returns `item_id` once done, or `done: false` while the rest runs in the background | the Axle Category & Tag Trash capability, `manage_terms`, `edit_terms` and `delete_terms` of the taxonomy, `delete_term` on the source and `edit_term` on the target |
| `axle-category-tag-trash/list-redirects` | Read-only. Old term addresses that redirect, newest first, with `new_url`, `kind`, `hits` and `target_gone`. Input: `taxonomy`, `search`, `limit`, `page` | the Axle Category & Tag Trash capability, and `manage_terms` of the taxonomy. Taxonomies you cannot manage are left out, and asking for one is a 403 |
| `axle-category-tag-trash/remove-redirect` | Stops the old address `path` (from `list-redirects`) from redirecting. Destructive, so the REST API runs it with `DELETE` | the Axle Category & Tag Trash capability, and `manage_terms` and `edit_terms` of the redirect's taxonomy |

## WP-CLI

```sh
wp axle-category-tag-trash list [--taxonomy=<taxonomy>] [--format=table|csv|json|count|ids]
wp axle-category-tag-trash merge <source> <target> --taxonomy=<taxonomy> [--children=target|parent] [--dry-run]
wp axle-category-tag-trash restore <id>...
wp axle-category-tag-trash delete <id>...
wp axle-category-tag-trash delete --all [--yes]
wp axle-category-tag-trash redirects list [--taxonomy=<taxonomy>] [--format=table|csv|json|count|ids]
wp axle-category-tag-trash redirects remove <old-path>...
```

`redirects list` shows each old address as a path such as `topics/seo`, where it leads now, why, its hits and when it was made. `redirects remove` takes those paths. To redirect a term before you delete it, set its target with `wp term meta update <term-id> axle_category_tag_trash_redirect_to <target-term-id>`.

`<id>` is the trash item ID from `wp axle-category-tag-trash list`, not the term ID. `restore` re-attaches every object in the same run, even for a term on a million posts, and also finishes a restore that is running in the background.

`merge` takes term IDs or slugs, moves every post in the same run and prints the trash item to restore for an undo. `--dry-run` shows what would happen and changes nothing. Run it again for a merge that stopped, and it carries on where it was.

## PHP API

```php
$plugin = AryanX\Axle\CategoryTagTrash\Plugin::instance();

$plugin->trash()->find( array( 'taxonomy' => 'category', 'per_page' => 100 ) ); // [ 'ids' => [...], 'total' => n ], at most 500 per page
$plugin->restorer()->restore( $item_id );        // [ 'term_id' => n, 'notes' => [...], 'background' => bool ] or WP_Error
$plugin->restorer()->restore( $item_id, true );  // the same, with every object re-attached before it returns
```

## How a merge works

Merging `source` into `target`:

1. Checks the taxonomy's capabilities, `delete_term` on the source and `edit_term` on the target. The default term cannot be merged away, and the taxonomy must be one that is kept in the trash, so the merge can be undone.
2. Moves the posts in batches of up to 5,000 per request or cron run. A batch finds posts that have the source but not the target, saves their IDs as the posts that gained the target, then adds the target. Posts that already had both are not touched.
3. When none are left, points menu links at the target and moves child terms under the target (or leaves them to WordPress, see the `axle_category_tag_trash_merge_children` filter).
4. Deletes the source through the normal delete, so it lands in the trash with a snapshot of exactly which posts it was on.

Undo restores the source on all its posts, then takes the target off the posts that gained it through the merge, moves child terms and menu links back, and recounts both terms. A big undo runs in the background like any big restore.

## How old term links work

A redirect is recorded when:

- a term's **slug** changes
- a term's **parent** changes, or a parent's slug changes, so that the address of a child term changes (up to 200 child terms are checked)
- a term is **merged** away
- a term is **deleted** and has a target set under "If this term is deleted"

It needs pretty permalinks and a public taxonomy with a rewrite rule. A term that is deleted without a target is not redirected: its address shows a 404, as before.

A redirect points at a term, not at a fixed address, so there are no chains. If the term is renamed again, every old address leads to the last one. If a term is merged or deleted with a target, the old addresses that led to it follow to the target. Undoing a merge, or restoring a term, takes back the redirects it made and points the older ones at the restored term again.

It runs on `template_redirect` at priority 9, before WordPress guesses a post for a 404, and only for a request that is not a real archive: a 404, a feed of a term that does not exist, or a term archive opened at an address other than its own (WordPress serves `/category/old-parent/child/` for a child that moved). A new term that takes the old slug wins. Feeds (`/feed/`, `/feed/atom/`) and numbered pages (`/page/2/`) of the old address redirect to the same part of the new one, and the query string is kept. The status is 301.

A redirect whose target term was deleted does nothing, and Site Health says so. It works again when the term is restored.

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
| `_axle_category_tag_trash_merge` | on an item that was merged away: the target, how many posts gained it, child terms and menu links that moved |
| `_axle_category_tag_trash_merge_gained` | one row per 1,000 posts that got the target term through the merge, packed as `id,id` |
| `_axle_category_tag_trash_merge_job` | on a running merge: source, target, how far it got |

Items in the trash have the status `publish`, items being restored in the background `pending`, and merges that are still moving posts `draft`. The setting is the `axle_category_tag_trash_settings` option.

Redirects live in two options that are not autoloaded, so a normal page load never reads them: `axle_category_tag_trash_redirects` (one entry per old path, at most 1,000) and `axle_category_tag_trash_redirect_hits` (hits and last hit per old path). The term meta key `axle_category_tag_trash_redirect_to` holds the target set under "If this term is deleted". The Undo for a removed redirect is a short-lived transient per user. No visitor data is stored: a hit is a count and a time. The cron events are `axle_category_tag_trash_purge` (daily), `axle_category_tag_trash_empty`, `axle_category_tag_trash_restore_batch` and `axle_category_tag_trash_merge_batch`.

Uninstalling removes all of it on every site of a multisite network, 100 sites at a time.
