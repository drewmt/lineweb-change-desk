=== Lineweb Change Desk ===
Contributors: drewmt
Tags: gutenberg, content management, bulk edit, ai, editorial
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Review content changes across selected Gutenberg posts and pages. Exact text mode, optional AI proposals, selective apply and guarded restore.

== Description ==

Change opening hours, service wording or policy references with a human-reviewed workflow. Preview selected content, inspect each before/after proposal, approve only what you want, then confirm the final changes. Nothing is published autonomously.

* Exact, case-sensitive text changes work without AI or external requests.
* Optional AI uses a named compatible provider configured through the WordPress AI Client. Provider costs and retention terms apply.
* Published, unprotected posts and pages only. Paragraph, heading, list-item and button text; normal groups/columns/lists/buttons are traversed.
* Link destinations and Gutenberg markup remain unchanged. Custom blocks, HTML, synced patterns, builders and split-node phrases need manual review.
* Source hashes, current permissions and other users' edit locks are rechecked before applying or restoring.
* No telemetry, Lineweb account, WooCommerce dependency or storefront assets.

This 0.1 source is a release candidate. It is not a complete-site scanner, autonomous agent or guarantee of AI factual accuracy. Products, prices, orders, titles, metadata and templates are outside its scope.

Limit: ten sources, 60,000 supported characters, 1,000 fields and 1 MB raw content per source. AI processes at most five chunks of eight fields/12,000 characters. Oversized and remaining fields are unscanned; incomplete or invalid output fails. Each source is applied once per job; approve all its desired proposals before applying.

= External services and costs =

Exact mode contacts no service. AI mode sends only the explicitly confirmed excerpts, opaque field IDs and instruction to the named WordPress-configured provider. There is no hardcoded provider or fallback. Review any personal data in the preview and the privacy/terms links supplied by your chosen provider's Connector before sending. AI data is not sent to Lineweb. Credentials stay with WordPress/the provider plugin.

At most 20 reserved requests per user and 50 per site per UTC day. Failed/interrupted attempts may consume quota without a billed call. Cancellation stops future requests, not an already sent one. Unknown provider outcomes are not automatically retried; after ten minutes the job needs verification. Human-approved existing proposals remain reviewable once no call is outstanding. Live provider quality has not been established by the synthetic SDK tests.

= History, retention and restore =

Private jobs contain excerpts, instructions and restore snapshots. They expire after 30 days; bounded cleanup runs through WP-Cron. Deleting a selected source deletes its entire associated job and all its restore history. Deactivation stops cleanup without changing content. Uninstall removes only plugin-owned jobs, quota counters and cron, not source content or revisions.

Restore refuses newer edits and cannot undo emails/webhooks or other third-party hook side effects. InnoDB is required for posts, postmeta and options. Arbitrary transaction changes by another plugin cannot be guaranteed safe. Inspect per-source results and refresh uncertain operations instead of repeating writes blindly.

= Source and build =

Readable source and build instructions: https://github.com/drewmt/lineweb-change-desk
Admin JS/SCSS: src/admin/. Compiled JS/CSS: build/admin/. Run npm ci --legacy-peer-deps, npm run build and npm run plugin-zip. WordPress-provided React/API libraries are externalized, not shipped as a separate frontend runtime.

English and Greek catalogs are available for direct installations. Directory language packs are not yet approved and follow WordPress.org policy at submission.

== Installation ==

1. Build/upload the plugin ZIP and activate it.
2. Open Change Desk in the admin menu as an editor or administrator.
3. Start with an exact text change on a small selection of published Gutenberg pages.
4. Optionally configure a compatible structured-text provider in Settings > Connectors for reviewed AI proposals.

== Frequently Asked Questions ==

= Does it work without AI? =
Yes. Exact text mode works immediately without a provider, key or charge.

= Does it scan the entire site? =
No. It only processes selected supported text fields. Unsupported content and split-node phrases require manual review.

= Does it overwrite later human changes? =
No. A changed source or active editing lock produces a conflict rather than a force overwrite.

= Is the AI result automatically applied? =
No. Every proposal is unchecked until you approve it, followed by final confirmation. Check facts yourself.

= What happens to my pages if I uninstall? =
Their content and revisions remain. Only private Change Desk history, counters and its scheduled cleanup are removed.

== Screenshots ==

1. Desktop workspace: exact changes, selected Gutenberg content and the centered official LineWeb logo.
2. Before/after proposal review with explicit approval and final confirmation.
3. Responsive mobile workspace using synthetic content.

== Changelog ==

= 0.1.0 =
* Initial release candidate: exact text changes, native AI proposals, private jobs, selective application and guarded restore.
* English/native Greek, responsive administration, explicit provider transfer and bounded quotas.

== Upgrade Notice ==

= 0.1.0 =
Initial candidate. Verify provider behavior and use staging before production content changes.
