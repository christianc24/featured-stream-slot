# Changelog

## 1.2.0 (2026-11-19)
- First public release on GitHub.
- New `ssp_should_render` filter to hide the player on chosen pages.
- New `ssp_settings` filter to change settings per request.
- Settings page now shows a "Settings saved" notice.
- Flat offline background; removed unused code and legacy migrations.

## 1.1.2
- The video is drawn at its real 16:9 size with a slim 24px toolbar above it. Twitch autoplays a plain 352x198 player; what blocked autoplay before was shrinking a larger player with a CSS transform.

## 1.1.1
- Toolbar moved out of the video area; resize from the top-left grip; one automatic Twitch reload if playback never starts.

## 1.1.0
- Direct Twitch iframe with preconnect hints (no SDK), border styling for every provider, custom offline message, close and reset buttons, Twitch clip links.
