=== Featured Stream Slot ===
Contributors: christianc24
Tags: twitch, stream, youtube, kick, sticky video
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A floating, draggable, resizable live stream player for Twitch, YouTube, Kick and Vimeo, with muted autoplay and a custom offline message.

== Description ==

Featured Stream Slot pins a live stream to the corner of every page of your site. Paste a Twitch, YouTube, Kick or Vimeo link and visitors get a small floating player that autoplays muted, can be dragged, resized or closed, and shows your own message when the stream is offline.

Created for a tech and gaming website.

* Twitch channels, videos and clips, YouTube videos, live links and playlists, Kick channels, Vimeo videos, or any HTTPS embed URL.
* Fast muted autoplay: the iframe is written at the top of the page with preconnect hints.
* Twitch offline detection with your own offline message.
* Drag, resize, reset and close controls for visitors; size and position are remembered.
* Border color, width, style, radius, sizes and offsets from the admin.
* No jQuery, no external SDKs.

Source code and issues: https://github.com/christianc24/featured-stream-slot

== Installation ==

1. Upload the plugin zip from Plugins > Add New Plugin > Upload Plugin and activate it.
2. Open Featured Stream in the admin menu.
3. Paste a stream link and save.

== Frequently Asked Questions ==

= Why does the stream start muted? =
Browsers block autoplay with sound. Visitors can unmute in the player.

= Does it autoplay on phones? =
Twitch never autoplays on phones; visitors tap to play.

= How do I hide it on some pages? =
Use the `ssp_should_render` filter, for example `add_filter( 'ssp_should_render', fn( $show ) => is_page( 'checkout' ) ? false : $show );`.

== Changelog ==

= 1.2.0 =
* First public release.
* New `ssp_should_render` and `ssp_settings` filters.
* "Settings saved" notice on the settings page.
* Flat offline background, cleaned up code and comments.

= 1.1.2 =
* Plain 16:9 video at its real size with a slim toolbar above it, so Twitch autoplays reliably.

= 1.1.0 =
* Direct Twitch iframe (no SDK), preconnect hints, custom offline message, close and reset buttons.
