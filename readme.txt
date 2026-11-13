=== Axle Category & Tag Trash ===
Contributors: aryanjasala
Tags: trash, categories, tags, undo, restore
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A trash for categories, tags and custom terms. Deleted a category by mistake? Restore it with its posts, child terms and menu links.

== Description ==

Posts and pages go to the trash when you delete them. Categories and tags don't: one click on "Delete" and the term is gone. WordPress also moves its posts to "Uncategorized", moves its child categories up a level and removes it from your menus. Getting it back means restoring a database backup.

Axle Category & Tag Trash keeps every deleted term for 30 days (or 7, 90 or 365, your choice).

**Undo** appears the moment you delete a term, on the Categories, Tags or any other term screen, for single and bulk deletes. **Restore** later from Tools > Axle Category & Tag Trash puts back:

* the term itself, with the **same ID**, name, slug, description and parent,
* all of its term meta (colours, images, SEO fields stored by other plugins),
* every post it was assigned to, in the same order,
* its child terms, moved back underneath it,
* the menu links that pointed to it, in the same menu and position, with their sub-items,
* and it removes "Uncategorized" from posts that only got it because of the deletion.

Keeping the same ID matters: navigation blocks, widgets and shortcodes that point to the term by ID work again without editing them.

**Merge** terms when you have the same thing twice: "WordPress", "wordpress" and "Word Press" as three tags. Choose "Merge into..." on a term, or in the bulk actions, pick the term to keep and read the preview. Every post of the old term moves to the one you kept, and the old term goes to the trash. If it was the wrong call, "Undo merge" brings the old term back on all its posts and takes the other term off only the posts that got it through the merge. Posts that already had both keep both. Big merges run in the background with a progress bar.

**Old links keep working.** Change a category's slug, move it under another parent, or merge it, and its old address sends visitors and search engines to the new one with a 301 redirect, instead of a 404. Feeds and numbered pages of the old address are sent along. The **Redirects** tab on Tools > Axle Category & Tag Trash lists each old address with its new address, why it redirects, since when and how many times it was used. Remove one and use Undo if that was a mistake. To redirect a term you are about to delete, pick a target under "If this term is deleted" on its edit screen. If you rename a term twice, the first old address goes straight to the last one, so redirects never chain.

Who can do what follows the taxonomy's own capabilities. A user who manages tags but not a custom taxonomy never sees, restores, deletes, merges or redirects that taxonomy's terms, on the screen or through the Abilities API.

Works with categories, tags, WooCommerce product categories and attributes, and any custom taxonomy that has an admin screen.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= Where to find it =

* **Tools > Axle Category & Tag Trash** lists deleted terms with what they were attached to, who deleted them and when. Filter by taxonomy, search, restore or delete in bulk, see exactly which posts, child terms and menus a restore brings back, and empty the trash.
* Every category and tag screen has a link to it under the list, and "Merge into..." in each row and in the bulk actions.
* The **Redirects** tab on the same screen lists the old term addresses that redirect.
* The Plugins screen has an "Open the trash" link in this plugin's row.

= WP-CLI =

* `wp axle-category-tag-trash list`
* `wp axle-category-tag-trash merge <source> <target> --taxonomy=post_tag` (add `--dry-run` to preview)
* `wp axle-category-tag-trash restore <id>...`
* `wp axle-category-tag-trash delete <id>...` or `wp axle-category-tag-trash delete --all`
* `wp axle-category-tag-trash redirects list` and `wp axle-category-tag-trash redirects remove <old-path>...`

**Try it before you install.** [try.axlewp.com/axle-category-tag-trash](https://try.axlewp.com/axle-category-tag-trash) opens a private WordPress in your browser with this plugin and some demo content.

Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

== Frequently Asked Questions ==

= What does a merge change? =

Posts, pages and other items of the old term get the term you kept. The name, slug, description and meta of the term you kept do not change. Menu links to the old term point at the term you kept. Child terms move under the term you kept, or up one level, see the `axle_category_tag_trash_merge_children` filter.

= Can I undo a merge? =

Yes, for as long as the old term is in the trash. Choose "Undo merge" on it. The default term (Uncategorized) cannot be merged away, and a taxonomy you left out of the trash cannot be merged, because it could not be undone.

= When does an old term address redirect? =

When a term's slug changes, when its parent (or a parent's slug) changes so that its address changes, when it is merged into another term, and when it is deleted with a target chosen under "If this term is deleted". A term deleted without a target shows a 404, as WordPress does. Redirects need pretty permalinks.

= What if a new term takes the old slug? =

The new term wins. A redirect only runs when the old address would not show a real term archive, so a new term with the old slug is shown as it is. Use the Redirects tab to remove the old redirect.

= Do redirects slow my site down? =

No. They are stored in two options that WordPress does not load on a normal page load, and they are only read on a request that is a 404, a feed of a term that does not exist, or a term archive opened at an address that is not its own.

= What happens if a new term uses the old slug on restore? =

The restored term gets a unique slug instead, and Axle Category & Tag Trash tells you which one.

= What if a post was deleted while the term was in the trash? =

It is skipped. Everything else is restored.

= What if I changed a post's categories in the meantime? =

Only posts that still have exactly the default category plus the restored term lose the default category. Posts you changed by hand are left alone, and so are child terms you moved somewhere else.

= How long are terms kept? =

30 days by default, then a daily task deletes them for good. Choose 7, 30, 90 or 365 days on the Axle Category & Tag Trash screen, or use the `axle_category_tag_trash_retention_days` filter.

= Can I leave a taxonomy out? =

Use the `axle_category_tag_trash_supports_taxonomy` filter. Menus are never kept, because deleting a menu removes its items first.

= Does it work for a tag on thousands of posts? =

Yes. The posts are saved in small chunks, so the trash stays light. A restore brings back the first 5,000 posts right away and the rest in the background, and `wp axle-category-tag-trash restore <id>` does it all in one go. Search plugins like ElasticPress are told about every post that gets its term back.

== Screenshots ==

1. Undo right after deleting a category.
2. Tools > Axle Category & Tag Trash.
3. What a restore brings back.

== Changelog ==

= 3.0.0 =
* Changed (breaking change): rename to Axle Category & Tag Trash for WordPress.org
* Fix: clean the term cache once per object type

= 2.0.0 =
* Changed (breaking change): rename to Axle Category & Tag Trash for WordPress.org

= 1.3.0 =
* Fix: make the abilities visible to MCP agents
* New: add abilities so AI agents can find, restore and delete trashed terms

= 1.2.0 =
* New: add filters for which terms are kept, retention choices and the Undo window, and actions for trash events
* Fix: keep big terms safe to delete and restore on large sites
* Fix: update the Axle Category & Tag Trash count right after a delete

= 1.1.0 =
* New: Undo notice right after deleting a term, for single and bulk deletes.
* New: Axle Category & Tag Trash screen rebuilt as a list table with taxonomy tabs, search, bulk restore and delete, Empty trash, and a "what restore brings back" view.
* New: choose how long deleted terms are kept.
* Rewritten as PSR-4 classes.

= 1.0.0 =
* First release.
