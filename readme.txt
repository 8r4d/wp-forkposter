=== Forkposter ===
Contributors: bradsalomons
Tags: versions, revisions, rewrite, archive, duplicate post
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later

Fork a published post into a new version. The original stays live, labeled as an earlier version and linked to the new one.

== Description ==

Forkposter is for writers who revisit their own ideas. Instead of overwriting a post or deleting it, you fork it: the new version starts as a copy you can rewrite, expand, or rethink, and the original stays published as a record of where your thinking was.

When the fork is published:

* The original gets a notice at the top pointing to the newest version, and a title badge ("Earlier version" by default).
* The new post gets a notice linking back to the original, plus an optional "why I revisited this" note.
* Both stay in the home page, archives, and RSS feed at their own dates. Older posts are labeled in post lists and in the feed.
* The original's modified date is never changed, so it doesn't look recently updated.
* Older posts get a hidden `forkposter_state` term, and optionally a tag of your choice, so auto-share plugins can exclude them.

Unpublishing, trashing or deleting the fork restores the original to a normal post. Chains (v1 → v2 → v3) are supported: every older version points to the newest one.

All labels and notices are added when the page is rendered. Nothing is written into your post titles or content, so deactivating the plugin leaves your posts exactly as you wrote them.

== Usage ==

* **Fork a post:** use the "Fork" link under a published post in Posts > All Posts, the "Fork this post" button in the editor's Versions box, or "Fork this post" in the admin bar while viewing it.
* **See all your versions:** Posts > Versions lists every forked post grouped with all its versions (published, earlier, drafts and branches), with word counts and links. Only you and other editors can see it.
* **Compare versions:** from Posts > Versions or the editor's Versions box, compare any two versions of a piece. Changes are highlighted word by word in readable text, with an option to compare the raw HTML instead.
* **Version history:** add `[forkposter_history]` to a post to list every version of it.
* **Settings:** Settings > Forkposter for labels, notice wording, display toggles, and the optional tag.

Notices accept `{link}` (the other version's title, linked) and `{date}` (its publish date).

== Styling ==

Posts get `forkposter-superseded` or `forkposter-fork` in their post classes. Notices use `.forkposter-notice` (with `--single`, `--list`, `--superseded`, `--fork` modifiers) and badges use `.forkposter-badge`.

== For developers ==

Filters:

* `forkposter_post_types` — post types that can be forked (default `array( 'post' )`).
* `forkposter_copy_meta_keys` — extra meta keys to copy onto a fork.

Actions:

* `forkposter_forked( $fork_id, $parent_id )` — a fork draft was created.
* `forkposter_superseded( $parent_id, $fork_id )` — a post became an earlier version.
* `forkposter_restored( $parent_id, $fork_id )` — a post stopped being an earlier version.

To exclude earlier versions from a query:

    'tax_query' => array( array(
        'taxonomy' => 'forkposter_state',
        'field'    => 'slug',
        'terms'    => 'superseded',
        'operator' => 'NOT IN',
    ) )

== Uninstalling ==

Deleting the plugin removes the links between versions, the notes, the plugin's tags and its settings. Your posts are not deleted.
