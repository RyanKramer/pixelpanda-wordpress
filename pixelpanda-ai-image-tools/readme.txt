=== PixelPanda AI Image Tools ===
Contributors: kramer5789
Tags: ai, background removal, image upscaler, woocommerce, product images
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI background removal, upscaling, enhancement and text removal for your Media Library and WooCommerce product images.

== Description ==

PixelPanda AI Image Tools connects your WordPress site to [PixelPanda](https://pixelpanda.ai), an AI image platform for e-commerce, and lets you process images without leaving wp-admin:

* **Remove background** — clean cutouts with transparent PNG output
* **Upscale 2x / 4x** — AI super-resolution for crisp product photos and print
* **AI enhance** — fix blur, color and sharpness in one click
* **Remove text & watermarks** — strip stamps, labels and overlaid text

Works from three places:

1. **Media → Library** — hover any image for one-click actions, or select many and use Bulk actions.
2. **WooCommerce → Products** — bulk-process every image of the selected products (featured + gallery).
3. **Attachment details** — see which images were processed.

Results are saved as **new images by default** (originals untouched). Optionally replace originals in place — a backup is kept and one click restores it.

= Pricing =

Processing runs on PixelPanda credits (1 credit per image for all operations above). Credits never expire. A $1.99 starter pack is available with no subscription. See [pixelpanda.ai/pricing](https://pixelpanda.ai/pricing).

== External services ==

This plugin sends images to the PixelPanda API (https://pixelpanda.ai) for processing. The image (as a public URL or the image data itself), your API key, and your site URL (in the User-Agent header) are transmitted when you run an operation — nothing is sent automatically. Processed images are stored on PixelPanda's CDN and downloaded back into your Media Library.

* Service: PixelPanda — https://pixelpanda.ai
* Terms: https://pixelpanda.ai/terms
* Privacy: https://pixelpanda.ai/privacy

== Installation ==

1. Install and activate the plugin.
2. Create a free account at [pixelpanda.ai](https://pixelpanda.ai) and copy your API key from your profile.
3. Go to Settings → PixelPanda, paste the key, and click "Test key".
4. Open Media → Library and hover any image.

== Frequently Asked Questions ==

= Does it overwrite my images? =

No — by default every result is saved as a new attachment. If you switch to replace mode, the original file is backed up and can be restored from the Media Library row actions.

= Where does the plugin store data? =

Settings (your API key and the default mode) are stored as WordPress options. In replace mode, one copy of the untouched original is kept in `uploads/pixelpanda-ai-image-tools/`, resolved at runtime with `wp_upload_dir()` and protected from direct access; the file name is referenced from attachment meta so you can restore it later. Nothing is written to plugin, theme or core folders. On uninstall the options and meta are removed, and the folder is deleted when it no longer holds any originals — stored originals themselves are kept, because for replaced images they are the only remaining copy of your file.

= Does it work without WooCommerce? =

Yes. WooCommerce only adds the product bulk actions; the Media Library integration works on any WordPress site.

= Do unused credits expire? =

No, credits roll over forever.

== Screenshots ==

1. Remove background — every result is a transparent PNG, saved as a new attachment by default.
2. AI upscale — a 350px supplier photo rebuilt at 1392px, with detail preserved for print.
3. Remove text, stamps and promo overlays from marketplace photos.
4. AI enhance — fix dark, blurry, heavily compressed product shots in one click.

== Changelog ==

= 1.0.1 =
* Originals kept in replace mode are now stored in a dedicated, access-protected folder inside the uploads directory (resolved with `wp_upload_dir()`) instead of beside the attachment file.
* Backups are written through `WP_Filesystem` and referenced by file name only, so a stored value can never resolve outside the plugin's own folder.
* Uninstall removes the plugin's post meta and the backup folder when it holds no originals.

= 1.0.0 =
* Initial release: background removal, 2x/4x upscale, AI enhance, text removal; Media Library row + bulk actions; WooCommerce product bulk actions; replace-with-backup mode.
