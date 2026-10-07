=== Forkposter ===
Contributors: bradsalomons
Tags: versions, revisions, rewrite, archive, duplicate post
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later

Fork a published post into a new version. The original stays live, labeled as an earlier version and linked to the new one.

== Description ==

Forkposter is for writers who revisit their own ideas. Instead of overwriting a post or deleting it, you fork it: the new version starts as a copy you can rewrite, expand, or rethink, and the original stays published as a record of where your thinking was.

When the fork is published:

* The original gets a notice at the top pointing to the newest version, and a title badge ("Earlier version" by default).
* The new post gets a notice linking back to the original, plus an optional "why I revisited this" note.
* Both stay in the home page, archives, and RSS feed at their own dates. Older posts are labeled in post lists and in the feed.
* The original's modified date is never changed, so it doesn't look recently updated.
* Search engines are told how the versions relate: each version links to the previous, next and newest version in its page head (`rel="predecessor-version"` and friends), and newer posts' structured data says they are based on the earlier one. With Yoast SEO, Rank Math or All in One SEO active, this is added to their existing data instead of printed separately. Each version keeps its own canonical URL.
* Page caches are cleared for the original and every earlier version when a fork is published or removed, and for the whole site when settings change. WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache and SiteGround Optimizer are handled directly; other caches can hook `forkposter_purge_post` and `forkposter_purge_all`.
* Yoast Duplicate Post doesn't copy Forkposter's links, version numbers or labels onto copies, so duplicating a post never creates a phantom version.
* Older posts get a hidden `forkposter_state` term, and optionally a tag of your choice, so auto-share plugins can exclude them.

Unpublishing, trashing or deleting the fork restores the original to a normal post. Chains (v1 → v2 → v3) are supported: every older version points to the newest one.

All labels and notices are added when the page is rendered. Nothing is written into your post titles or content, so deactivating the plugin leaves your posts exactly as you wrote them.

== Usage ==

* **Fork a post:** use the "Fork" link under a published post in Posts > All Posts, the "Fork this post" button in the editor's Versions panel, or "Fork this post" in the admin bar while viewing it.
* **Versions panel:** in the block editor's post sidebar. Shows where this post sits in its family, links to compare, and edits the version number and the "why I revisited it" note. Both are saved with the post and kept in its revision history. The classic editor gets the same controls in a Versions box.
* **See all your versions:** Posts > Versions lists every forked post grouped with all its versions (published, earlier, drafts and branches), with word counts and links. Only you and other editors can see it.
* **Compare versions:** from Posts > Versions or the editor's Versions box, compare any two versions of a piece. Changes are highlighted word by word in readable text, with an option to compare the raw HTML instead.
* **Version numbers (optional):** turn on Settings > Forkposter > Version numbers. New forks are numbered automatically (1 → 2, 2.0 → 3.0; forking the same post twice gives 2 and 3), and the number shows in badges ("Earlier version · v.1"), notice links, the feed, the history list and Posts > Versions. Change any post's number in the editor's Versions box, and the display format (default `v.{version}`) in settings.
* **Version history:** add the Version history block (or the `[forkposter_history]` shortcode) to a post to list every published version of it. In a block theme, put the block in the Single Posts template to show it on every post that has versions; it renders nothing on posts without other versions.
* **Settings:** Settings > Forkposter for labels, notice wording, display toggles, and the optional tag.

Notices accept `{link}` (the other version's title, linked) and `{date}` (its publish date).

== Styling ==

Posts get `forkposter-superseded` or `forkposter-fork` in their post classes. Notices use `.forkposter-notice` (with `--single`, `--list`, `--superseded`, `--fork` modifiers) and badges use `.forkposter-badge`.

== For developers ==

Filters:

* `forkposter_post_types` — post types that can be forked (default `array( 'post' )`).
* `forkposter_copy_meta_keys` — extra meta keys to copy onto a fork.
* `forkposter_schema_handled_elsewhere` — return true if another plugin prints Article structured data, so Forkposter adds none of its own.

Actions:

* `forkposter_forked( $fork_id, $parent_id )` — a fork draft was created.
* `forkposter_superseded( $parent_id, $fork_id )` — a post became an earlier version.
* `forkposter_restored( $parent_id, $fork_id )` — a post stopped being an earlier version.
* `forkposter_purge_post( $post_id )` — a post's cached page should be cleared.
* `forkposter_purge_all()` — every cached page should be cleared (settings changed).

To exclude earlier versions from a query:

    'tax_query' => array( array(
        'taxonomy' => 'forkposter_state',
        'field'    => 'slug',
        'terms'    => 'superseded',
        'operator' => 'NOT IN',
    ) )

== Development ==

Tests run against a throwaway WordPress site in WordPress Playground (needs Node.js 20+):

    npm install
    npx playwright install chromium   # only for the browser test
    npm test                          # integration tests
    npm run test:editor               # plus the block editor browser test

The test files, `package.json` and `node_modules` are listed in `.distignore`, so they stay out of the plugin zip.

== Uninstalling ==

Deleting the plugin removes the links between versions, the notes, version numbers, the plugin's tags and its settings, on every site of a multisite network. Your posts are not deleted.
