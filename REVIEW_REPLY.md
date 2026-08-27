# Reply draft — WordPress.org plugin review (thread: pixelpanda-ai-image-tools / kramer5789 / 26Aug26)

**Send as a REPLY to the existing review email thread from plugins@wordpress.org.**
Keep it short — they explicitly ask for no verbose/AI filler.

---

Hi,

Version 1.0.1 has been uploaded via "Add your plugin".

**Ownership / name.** PixelPanda is our own product — DynamicDuo LLC owns and operates
pixelpanda.ai, and this is the official plugin for it. To verify, I've added the requested
TXT record at the domain root:

    pixelpanda.ai.  TXT  "wordpressorg-kramer5789-verification"

I've also changed my WordPress.org account email to ryan@pixelpanda.ai so the account domain
matches the plugin. On that basis I'd like to keep the display name "PixelPanda AI Image Tools"
and the slug "pixelpanda-ai-image-tools".

**Writing data to disallowed locations.** Fixed. In replace mode the untouched original is no
longer copied beside the attachment; it's written to a dedicated folder inside the uploads
directory, resolved at runtime with wp_upload_dir() and created with wp_mkdir_p(), protected
from direct access with index.php and .htaccess. The copy now goes through WP_Filesystem rather
than copy(), and attachment meta stores only the file name, resolved inside that folder, so a
stored value cannot point elsewhere on disk. Uninstall removes the plugin's options and meta and
deletes the folder when it holds no originals. I checked the rest of the codebase for the same
pattern — new attachments already go through wp_upload_bits(), and nothing else writes to disk.

**Category.** For context on the saturation note: this isn't an image optimiser — it does no
compression or local processing. It's a client for our cloud AI service (background removal,
2x/4x upscaling, restoration, text/watermark removal) with a WooCommerce bulk workflow for
product galleries, which is not something the compression plugins in that category do.

Thanks,
Ryan Kramer — DynamicDuo LLC

---

## Before sending, Ryan must do these two things

1. **Upload 1.0.1** at https://wordpress.org/plugins/developers/add/ while logged in as `kramer5789`
   — file: `~/Projects/pixelpanda-wordpress/dist/pixelpanda-ai-image-tools-1.0.1.zip` (revealed in Finder).
2. **Change the WP.org profile email** to `ryan@pixelpanda.ai` at https://profiles.wordpress.org/me/profile/edit/group/1/
   (they cannot change it for you; future review emails then go to that inbox).
   If you'd rather not switch the email, delete that sentence from the reply — the DNS record alone
   may be enough, but the email domain match is the strongest single proof.

## Already done (by Claude, 2026-08-26)

- DNS TXT `wordpressorg-kramer5789-verification` added at pixelpanda.ai root via GoDaddy
  (existing google-site-verification, Microsoft and SPF records preserved — verified on the
  authoritative NS; public resolvers pick it up within the 3600s TTL).
- Code fix + readme + uninstall + version bump committed and pushed (7a9c261), zip built.
