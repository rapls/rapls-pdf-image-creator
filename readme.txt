=== Rapls PDF Image Creator – PDF Thumbnails & Featured Images ===

Contributors: rapls
Donate link: https://buymeacoffee.com/rapls
Tags: pdf, thumbnail, image, featured image, media
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.3.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The first page of each uploaded PDF becomes an image in every registered size, ready to use as a post cover or inside content. Needs ImageMagick.

 == Description ==

 Rapls PDF Image Creator automatically generates thumbnail images when you upload PDF files to your WordPress Media Library. The plugin uses ImageMagick (Imagick PHP extension) to convert the first page of a PDF into an image.

👉 **Setup guide & troubleshooting:** [How to fix CMYK black thumbnails and PDF/X issues](https://raplsworks.com/rapls-pdf-image-creator-guide/)

 = Key Features =

* **Automatic Generation** - Thumbnails are created instantly when PDFs are uploaded
* **Featured Image Support** - Generated thumbnails are automatically set as the PDF's featured image
* **Multiple Sizes** - Images are generated in all registered WordPress image sizes
* **Media Library Integration** - Display thumbnails instead of default PDF icons
* **Editor Integration** - Insert PDF links with thumbnail images into your posts
* **Bulk Generation** - Generate thumbnails for all existing PDFs at once
* **Flexible Output** - Choose from JPEG, PNG, or WebP formats

= How It Works =

1. Upload a PDF file to the Media Library
2. The plugin automatically converts the first page to an image
3. The image is registered as the PDF's featured image
4. Use shortcodes or template functions to display the thumbnail

= Generated Files =

When you upload `my-document.pdf`, the plugin creates:

* my-document-pdf.jpg (Full size cover image)
* my-document-pdf-1024x768.jpg (Large)
* my-document-pdf-300x225.jpg (Medium)
* my-document-pdf-150x150.jpg (Thumbnail)
* Additional sizes based on your theme settings

= Shortcodes =

* `[rapls_pdf_thumbnail id="123"]` - Display thumbnail image
* `[rapls_pdf_thumbnail_url id="123"]` - Output thumbnail URL
* `[rapls_pdf_clickable_thumbnail id="123"]` - Thumbnail linked to PDF
* `[rapls_pdf_download_link id="123"]` - Download link with thumbnail

= Template Functions =

* `rapls_pic_get_thumbnail_url( $pdf_id, $size )` - Get thumbnail URL
* `rapls_pic_get_thumbnail_id( $pdf_id )` - Get thumbnail attachment ID
* `rapls_pic_get_thumbnail_image( $pdf_id, $size, $attr )` - Get thumbnail HTML
* `rapls_pic_has_thumbnail( $pdf_id )` - Check if PDF has thumbnail
* `rapls_pic_generate_thumbnail( $pdf_id, $force )` - Generate thumbnail

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* ImageMagick with Imagick PHP extension and PDF support

Most shared hosting providers have ImageMagick available. Check the Status tab in plugin settings to verify your server meets the requirements.

== Installation ==

1. Upload the `rapls-pdf-image-creator` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Rapls PDF Image Creator
4. Check the Status tab to verify ImageMagick is available
5. Upload a PDF to test thumbnail generation

== Screenshots ==

1. Settings page - Configure image size, quality, and format options
2. Bulk Generate tab - Generate thumbnails for existing PDFs
3. Status tab - View ImageMagick availability and server capabilities
4. Media Library - PDFs display with generated thumbnail icons

== Frequently Asked Questions ==

= Where can I find detailed documentation? =

A full guide with setup instructions, the fix for the infamous "black thumbnail" problem (CMYK / PDF/X), and real-world usage tips:

* [Rapls PDF Image Creator — Setup & Troubleshooting Guide](https://raplsworks.com/rapls-pdf-image-creator-guide/)
* [Source code on GitHub](https://github.com/rapls/rapls-pdf-image-creator)
* [Developer's blog — Rapls Works](https://raplsworks.com/)

= What are the server requirements? =

You need ImageMagick with the Imagick PHP extension and PDF support. Most shared hosting providers have this available. Contact your hosting provider if you need to enable it.

= How do I check if my server supports PDF conversion? =

Go to Settings > Rapls PDF Image Creator > Status tab. The plugin will show whether ImageMagick is available and properly configured.

For detailed troubleshooting including common server setup issues, see the [setup guide](https://raplsworks.com/rapls-pdf-image-creator-guide/).

= My thumbnail is a completely blank white image. What is wrong? =

Check Settings > Rapls PDF Image Creator > Status. If it reports ImageMagick 6, that is the cause.

ImageMagick 6 renders any PDF it judges to be CMYK through Ghostscript's `bmpsep8` device, which writes a separation BMP that ImageMagick's own BMP reader cannot decode. Depending on the exact ImageMagick and Ghostscript versions, the read either fails outright or returns an empty raster — which becomes a blank white thumbnail. ImageMagick 7 uses a different device and is unaffected.

The plugin cannot work around this, because ImageMagick picks the device from the PDF's own content and no Imagick API call overrides it. Ask your hosting provider to upgrade to ImageMagick 7, or to change the `ps:cmyk` delegate in `delegates.xml` from `bmpsep8` to `pamcmyk32`.

RGB PDFs are not affected, which is why only some of your PDFs fail.

= Why do my thumbnails look more vivid than the original PDF? =

If the PDF uses CMYK colours and no ICC profile is available on your server, the plugin falls back to a simple arithmetic conversion, which exaggerates greens and blues. Check Settings > Rapls PDF Image Creator > Status to see whether ICC colour management is active. Installing a CMYK profile on the server, or pointing the `rapls_pdf_image_creator_icc_paths` filter at one, restores accurate colours.

= I updated and my thumbnails still have the old colours. Why? =

Thumbnails are not regenerated automatically, because regenerating every PDF on a large site during an update is not something a plugin should decide to do. Go to Settings > Rapls PDF Image Creator > Bulk Generate and regenerate them.

If you would rather keep the previous colours, pin the old behaviour:

`
add_filter( 'rapls_pdf_image_creator_color_conversion', function() {
    return 'naive';
} );
`

= Can I generate thumbnails for PDFs uploaded before installing this plugin? =

Yes. Go to Settings > Rapls PDF Image Creator > Bulk Generate tab to scan and generate thumbnails for all existing PDFs.

= I raised Max Width and Max Height but the thumbnail is the same size. Why? =

Those two settings only scale the rendered page *down*. They never enlarge it. The size the page is rendered at comes from Rendering Resolution instead, which was fixed at 150 DPI before version 1.2.0 — an A4 page at 150 DPI is about 1240x1754 pixels, and no maximum above that could produce anything bigger.

Raise Rendering Resolution (Settings > Rapls PDF Image Creator > Image Settings) and the rendered page grows with it; the maximum dimensions then cap the result as before. Bear in mind that memory use during generation grows with the square of the resolution, so raise it in steps if your server is small.

= I uploaded a PDF and no thumbnail appeared. How do I find out why? =

Go to Tools > Site Health. The "PDF thumbnail generation" test says whether this server can render PDFs, and if not, what to ask your hosting provider for. The same report is on the Status tab of the plugin's settings page, and Site Health > Info has a Rapls PDF Image Creator section you can copy into a support request.

There are two different server problems, and they need different requests:

* **The Imagick PHP extension is not installed.** Ask your host to install and enable it. It is the PHP binding for ImageMagick.
* **ImageMagick is installed but its security policy forbids reading PDFs.** Many hosts ship a `policy.xml` that denies the PDF coder, a precaution left over from a 2018 Ghostscript vulnerability. Ask your host to give the PDF coder read rights. The plugin shows the path of the file responsible.

The plugin tells these apart for you, so you do not have to guess which request to make.

= What image formats are supported? =

JPEG, PNG, and WebP. Configure your preferred format in the Image Settings tab.

= Will thumbnails be deleted when I uninstall the plugin? =

By default, yes. To keep generated images as regular attachments, enable "Keep Images on Uninstall" in Display Settings before uninstalling.

= Can I use a different page for the thumbnail? =

Yes. Use the `rapls_pdf_image_creator_thumbnail_page` filter:

`
add_filter( 'rapls_pdf_image_creator_thumbnail_page', function( $page, $pdf_id ) {
    return 1; // Use second page (0-indexed)
}, 10, 2 );
`

= How do I customize the insert output? =

Go to Settings > Rapls PDF Image Creator > Insert Settings. Choose from Image only, Title link, or Custom HTML with placeholders like `{thumbnail}`, `{pdf_url}`, `{pdf_title}`.

== Other Notes ==

= Using Template Functions =

Display a PDF thumbnail in your theme:

`
$pdf_id = 123;
if ( rapls_pic_has_thumbnail( $pdf_id ) ) {
    echo rapls_pic_get_thumbnail_image( $pdf_id, 'medium' );
}
`

Link thumbnail to PDF file:

`
$pdf_id = 123;
if ( $thumbnail_id = get_post_thumbnail_id( $pdf_id ) ) {
    echo '<a href="' . esc_url( wp_get_attachment_url( $pdf_id ) ) . '" target="_blank">';
    echo wp_get_attachment_image( $thumbnail_id, 'medium' );
    echo '</a>';
}
`

= Display All PDFs Attached to a Post =

`
$pdfs = get_posts( array(
    'post_type'      => 'attachment',
    'post_mime_type' => 'application/pdf',
    'post_parent'    => get_the_ID(),
    'posts_per_page' => -1,
) );

foreach ( $pdfs as $pdf ) {
    if ( rapls_pic_has_thumbnail( $pdf->ID ) ) {
        printf(
            '<a href="%s">%s</a>',
            esc_url( wp_get_attachment_url( $pdf->ID ) ),
            rapls_pic_get_thumbnail_image( $pdf->ID, 'thumbnail' )
        );
    }
}
`

= Available Filter Hooks =

* `rapls_pdf_image_creator_thumbnail_page` - PDF page to use (default: 0)
* `rapls_pdf_image_creator_thumbnail_max_width` - Maximum width
* `rapls_pdf_image_creator_thumbnail_max_height` - Maximum height
* `rapls_pdf_image_creator_thumbnail_resolution` - Rendering resolution in DPI (default: 150)
* `rapls_pdf_image_creator_thumbnail_quality` - Image quality (1-100)
* `rapls_pdf_image_creator_thumbnail_format` - Output format
* `rapls_pdf_image_creator_thumbnail_bgcolor` - Background color
* `rapls_pdf_image_creator_thumbnail_image_attributes` - Image tag attributes
* `rapls_pdf_image_creator_custom_insert_html` - Custom insert HTML
* `rapls_pdf_image_creator_hide_thumbnails_in_library` - Hide in Media Library
* `rapls_pdf_image_creator_policy_paths` - ImageMagick policy.xml paths to search when diagnosing blocked PDF support

= Color Conversion Filters =

* `rapls_pdf_image_creator_color_conversion` - `auto` (default), `icc` or `naive`. `naive` restores the pre-1.1.0 colors
* `rapls_pdf_image_creator_icc_paths` - Array of absolute paths to search, per profile type (`srgb` / `cmyk`)
* `rapls_pdf_image_creator_rendering_intent` - An `Imagick::RENDERINGINTENT_*` value (default: relative colorimetric)
* `rapls_pdf_image_creator_flatten_background` - Background transparency is flattened onto (default: the configured background, or `white` for JPEG)

Pointing the plugin at a specific pair of profiles:

`
add_filter( 'rapls_pdf_image_creator_icc_paths', function( $paths, $type ) {
    if ( 'cmyk' === $type ) {
        return array( '/srv/icc/JapanColor2011Coated.icc' );
    }
    return $paths;
}, 10, 2 );
`

= Available Action Hooks =

* `rapls_pdf_image_creator_before_generate` - Before thumbnail generation
* `rapls_pdf_image_creator_after_generate` - After successful generation
* `rapls_pdf_image_creator_generation_failed` - When generation fails

== Changelog ==
= 1.3.1 =
* Fixed: the availability check was asking the wrong question. `Imagick::queryFormats('PDF')` reports which coders were compiled in, not whether the security policy permits them — verified on ImageMagick 7.1.1, where a policy.xml denying the PDF coder still leaves PDF in queryFormats while reading one throws `NotAuthorized`. On such a server 1.3.0 reported "PDF thumbnails can be generated" and then failed silently on the first upload, which is the exact problem 1.3.0 set out to solve
* Fixed: a server with ImageMagick but without Ghostscript was also reported as working, for the same reason
* Changed: availability is now decided by handing ImageMagick an actual PDF — a blank one-inch page built in memory, no file written and no process started by the plugin — and reading the exception. `NotAuthorized` means the policy; a Ghostscript failure means the delegate. The result is cached for 12 hours and re-checked when the ImageMagick version changes
* Fixed: the policy.xml reader counted rules inside `<!-- -->` as active. Hosting providers usually unblock PDF by commenting the deny rule out rather than deleting it, so an already-fixed server could be reported as still blocked

= 1.3.0 =
* Added: a "PDF thumbnail generation" test in Tools > Site Health, plus a Rapls PDF Image Creator section under Site Health > Info. This is where people look first when something does not work, and where support requests get copied from
* Added: activating the plugin on a server that cannot render PDFs now says so on the next admin screen. Previously activation succeeded in silence, uploads produced no thumbnail, and nothing anywhere explained why
* Added: a PDF upload that fails because no engine is available is now recorded and reported, instead of failing silently
* Changed: "ImageMagick is not available" is now two distinct messages. A missing Imagick extension and an ImageMagick security policy that forbids reading PDFs are different server problems needing different requests to a hosting provider, and the plugin now names which one it is — including the path of the policy.xml responsible
* Changed: the Status tab, the admin notice and Site Health all explain what to ask the host for, rather than only reporting a failure
* Fixed: the activation routine kept its own copy of the default settings, which had gone out of date and did not include the rendering resolution added in 1.2.0
* Added: filter `rapls_pdf_image_creator_policy_paths`, for builds that keep policy.xml somewhere unusual

= 1.2.1 =
* Fixed: twelve strings in the admin screens were shown in English under a translated locale. The bundled Japanese translation had drifted from the code — it still carried fourteen entries for a Status tab that no longer exists, and had none for the strings that replaced them, for the Bulk Generate error messages, or for the review link
* No functional change; translation files only

= 1.2.0 =
* Added: Rendering Resolution setting (Settings > Image Settings), so the DPI the PDF page is rasterized at is no longer fixed at 150. Raising it is what produces a larger thumbnail — the maximum width and height only scale the rendered page down, never up, so raising them alone had no effect once the page already fit
* Added: filter `rapls_pdf_image_creator_thumbnail_resolution`, for setting the DPI per attachment
* Note: the default is 150, which is what every previous version used, so thumbnails do not change until you raise it. Existing thumbnails are not regenerated automatically; use the Bulk Generate tab

= 1.1.1 =
* Display name updated: the plugin is listed as "Rapls PDF Image Creator – PDF Thumbnails & Featured Images" so that the directory search finds it by what it does, not only by its brand name.
* The description in the plugin header no longer differs from the one in this readme, and neither now simply repeats the title. No functional change.

= 1.1.0 =
* Fixed: CMYK PDFs produced thumbnails with oversaturated colors — greens and blues in particular came out close to fluorescent. The conversion to sRGB now goes through ICC profiles when the server has them, instead of the arithmetic formula that ignores ink behavior
* Fixed: transparent regions are now flattened onto the configured background explicitly, which addresses the "black thumbnail" problem without touching the color space. Choosing a transparent background with JPEG output now falls back to white, since JPEG cannot store transparency
* Changed: replaced the deprecated flattenImages() call with mergeImageLayers(), which behaves correctly under ImageMagick 7
* Added: the Status tab now reports whether ICC color management is active and which profiles are in use
* Added: the Status tab warns when the server runs ImageMagick 6, which renders CMYK PDFs through Ghostscript's bmpsep8 device and produces a blank white thumbnail. This is a limitation of ImageMagick 6 that the plugin cannot work around; the notice explains what to ask your host for
* Added: filters `rapls_pdf_image_creator_icc_paths`, `rapls_pdf_image_creator_rendering_intent`, `rapls_pdf_image_creator_color_conversion` and `rapls_pdf_image_creator_flatten_background`
* Note: existing thumbnails are not regenerated automatically. Use the Bulk Generate tab to refresh them. To keep the previous colors, pass 'naive' to the `rapls_pdf_image_creator_color_conversion` filter
* No ICC profile is bundled and no external program is invoked; profiles are read from the server at run time

= 1.0.9.10 =
* Fixed: PHP Fatal error "Argument #5 ($attr) must be of type array, string given" in MediaLibrary::filterAttachmentImage() on PHP 8 when WordPress core (or another plugin) calls wp_get_attachment_image() with the default string $attr value
* The wp_get_attachment_image filter callback now accepts both string and array $attr values, matching the WordPress core filter signature

= 1.0.9.9 =
* Fixed: "Undefined array key \"width\"" / "Undefined array key \"height\"" PHP warnings in wp-includes/media.php when a PDF (or a generated image attached via the PDF) is rendered as a featured image on post-list views (regression introduced in 1.0.9.8)
* PDF attachment metadata now also exposes top-level "width" and "height" derived from the full-size preview so core's srcset and image-size helpers no longer hit undefined keys

= 1.0.9.8 =
* Fixed: "Undefined array key \"file\"" PHP warning in wp-includes/media.php when a PDF embedded as an image is displayed on WordPress 6.9.x and earlier
* PDF attachment metadata now exposes a top-level "file" key so WordPress core's srcset handling no longer accesses an undefined key (core added its own guard in WP 7.0)

= 1.0.9.7 =
* Tested up to WordPress 7.0
* Verified compatibility with WordPress 7.0 (block editor, Site Health, REST API)

= 1.0.9.6 =
* Fixed: When "Auto Generate" is OFF, WordPress core's built-in PDF preview is now also suppressed so no thumbnail is created on upload
* Added wp_generate_attachment_metadata filter to remove core-generated -pdf.jpg files when auto-generation is disabled

= 1.0.9.5 =
* Updated Plugin URI to new plugin page (https://raplsworks.com/plugins/rapls-pdf-image-creator/)

= 1.0.9.4 =
* Fixed: Resolved PHP warnings for missing .l10n.php translation files on WordPress 6.5+
* Added PHP-optimized translation file (ja.l10n.php) for faster translation loading
* Load translations directly from plugin directory to bypass global path warnings
* Clear translation file cache on deactivation/uninstall to prevent stale file references

= 1.0.9.3 =
* Fixed: Resolved PHP warnings for missing .l10n.php translation files on WordPress 6.5+
* Added PHP-optimized translation file (ja.l10n.php) for faster translation loading
* Restored load_plugin_textdomain() to ensure translations load from plugin directory

= 1.0.9.2 =
* Added review link in Status tab support section
* Updated Plugin URI to new guide page

= 1.0.9 =
* Fixed: PDF/X-1:2001 format PDFs now generate correct thumbnails instead of black images
* Added CMYK to sRGB colorspace conversion for print-optimized PDFs

= 1.0.8 =
* Fixed: PDF attachment details now show PDF URL instead of thumbnail URL
* Fixed: Generated thumbnails show source PDF URL in attachment details
* Fixed: "Copy URL to clipboard" copies PDF URL for both PDF and thumbnail
* Fixed: Generated thumbnails properly hidden in AJAX media library queries
* Removed deprecated load_plugin_textdomain() call (auto-loaded since WordPress 4.6)
* Updated Japanese translations to follow WordPress translation style guide

= 1.0.6 =
* Added support link (Buy Me a Coffee) in Status tab
* Fixed PHP 7.4 compatibility (removed readonly properties and match expressions)
* Improved security: error_log() only runs when WP_DEBUG is enabled
* Removed flush_rewrite_rules() from activation/deactivation hooks
* Simplified AJAX URL handling using admin_url()
* Added wp_kses_post() sanitization for custom HTML output

= 1.0.5 =
* Removed GhostScript engine support (WordPress.org security requirement)
* Now uses ImageMagick (Imagick PHP extension) exclusively
* Added clear server requirements check in Status tab
* Improved admin notices for missing ImageMagick support
* Simplified settings by removing engine selection

= 1.0.4 =
* Changed namespace to Rapls\PDFImageCreator for uniqueness
* Updated all prefixes to rapls_pic_ for WordPress.org compliance
* Changed shortcode names from pdf_* to rapls_pdf_*
* Removed file path exposure from AJAX error responses
* Updated meta keys to use _rapls_pic_ prefix

= 1.0.3 =
* Renamed plugin to "Rapls PDF Image Creator"
* Updated plugin slug to "rapls-pdf-image-creator"
* Removed deprecated imagedestroy() for PHP 8.0+ compatibility

= 1.0.2 =
* Fixed translators comment placement for WordPress.org compliance

= 1.0.1 =
* Fixed WordPress Plugin Check compatibility issues
* Improved security with proper input sanitization
* Fixed CORS issue with AJAX on non-standard ports
* Updated to WordPress coding standards

= 1.0.0 =
* Initial release
* Auto-generate thumbnails on PDF upload
* ImageMagick engine support
* Bulk thumbnail generation
* Featured image support
* Block editor integration
* Shortcodes and template functions
* Configurable image settings
* Japanese translation included

== Upgrade Notice ==

= 1.3.1 =
Corrects the 1.3.0 detection. On servers whose ImageMagick policy forbids PDFs — the most common cause of missing thumbnails on shared hosting — 1.3.0 wrongly reported everything as working. Install this if you are on 1.3.0.

= 1.3.0 =
Diagnostics. Site Health now reports whether PDF thumbnails can be generated, activation warns when the server cannot render PDFs, and a missing ImageMagick is told apart from an ImageMagick that is forbidden to read PDFs. No change to how thumbnails are produced.

= 1.2.1 =
Translation fix: twelve admin strings that were stuck in English under a translated locale now display correctly. No functional change.

= 1.2.0 =
Adds a Rendering Resolution (DPI) setting for larger thumbnails. The default matches previous behavior, so nothing changes until you raise it.

= 1.1.0 =
Fixes oversaturated colors in thumbnails generated from CMYK PDFs. Existing thumbnails keep their old colors until you regenerate them from the Bulk Generate tab.

= 1.0.9.10 =
Fixes a PHP Fatal error ("Argument #5 ($attr) must be of type array, string given") that could occur on PHP 8 when displaying a PDF as an image. Update strongly recommended.

= 1.0.9.9 =
Fixes "Undefined array key width / height" PHP warnings introduced in 1.0.9.8 when a PDF-derived image is shown as a featured image on post-list pages.

= 1.0.9.8 =
Fixes an "Undefined array key file" PHP warning shown when a PDF embedded as an image is displayed on WordPress 6.9.x and earlier.

= 1.0.9.7 =
Tested up to WordPress 7.0. Compatibility verified.

= 1.0.6 =
PHP 7.4 compatibility fix and security improvements. Translation loading added.

= 1.0.5 =
GhostScript support removed per WordPress.org security requirements. ImageMagick (Imagick) is now required.

= 1.0.4 =
Major prefix changes for WordPress.org compliance. Update may require reconfiguration.

= 1.0.3 =
Plugin renamed with new slug. PHP 8.0+ compatibility improved.

= 1.0.0 =
Initial release.
