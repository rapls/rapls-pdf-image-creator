=== Rapls PDF Image Creator – PDF Thumbnails & Featured Images ===

Contributors: rapls
Donate link: https://buymeacoffee.com/rapls
Tags: pdf, thumbnail, image, featured image, media
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.4.47
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns PDFs into images. Refuses to save a blank thumbnail, works around a Ghostscript fault that makes one, and tells you what to ask your host.

== Description ==

Rapls PDF Image Creator automatically generates thumbnail images when you upload PDF files to your WordPress Media Library. The plugin uses ImageMagick (Imagick PHP extension) to convert a page of a PDF into an image -- the first, unless you choose another for the whole site.

👉 **Setup guide & troubleshooting:** [How to fix CMYK black thumbnails and PDF/X issues](https://raplsworks.com/rapls-pdf-image-creator-guide/)

= Why this one =

On a server with an older Ghostscript, a PDF page can come back completely white -- and Ghostscript does not report it as an error. Anything that hands the page over and stores whatever comes back saves that white image, including WordPress's own PDF preview. It looks like a thumbnail. It looks like the job worked.

Measured on a live shared host running Ghostscript 9.27, in a single upload: the built-in preview came back with a standard deviation of 0, one flat colour across every pixel. This plugin's thumbnail of the same page, in the same request, came back at 0.31.

* **It looks at what came back.** A page that renders as flat white is refused, not stored. WordPress shows its PDF icon and the Status tab says why, which is more use than a white square
* **It works around the cause.** Ghostscript 9.27 and earlier fail on an image inside a transparency group -- the kind of file PowerPoint and Illustrator produce. Retried with transparency switched off, the same page went from 11,018 bytes of white to 624,109 bytes of picture. Only ever as a retry: a page that rendered the first time is never touched
* **Every check is a measurement.** Whether this server can read a PDF, render CMYK, or select a page other than the first is answered by doing it, not by reading a version number. On ImageMagick 6 the Status tab names the Ghostscript device your ImageMagick uses for CMYK PDFs
* **It tells you what to ask your host.** "No PDF support", "the page is too large", "the uploads folder is not writable" and "the file is missing" are four problems with four different answers, and they no longer look the same
* **Real colour management, without the weight.** CMYK is converted through ICC profiles rather than the arithmetic shortcut that turns greens fluorescent. The bundled sRGB profile is 3 KB, not the 64 KB kind that ends up embedded in every registered size
* **Nothing starts a process.** No exec(), no shell. It works on locked-down shared hosting

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
2. The plugin automatically converts the first page (or the one you choose) to an image
3. The image is registered as the PDF's featured image
4. Use shortcodes or template functions to display the thumbnail

= Generated Files =

When you upload `my-document.pdf`, the plugin creates:

* my-document-pdf-thumbnail.jpg (Full size cover image)
* my-document-pdf-thumbnail-1024x768.jpg (Large)
* my-document-pdf-thumbnail-300x225.jpg (Medium)
* my-document-pdf-thumbnail-150x150.jpg (Thumbnail)
* Additional sizes based on your theme settings

A thumbnail made again ("Regenerate Thumbnail", or Bulk Generate with "Include PDFs that already have thumbnails (regenerate)") gets a new name with a suffix, such as `my-document-pdf-thumbnail-6512a3f0.jpg`, so browsers do not keep showing the old image. The old image is deleted, unless a post's content or excerpt or a widget shows it -- by its address, or by its ID in an image or gallery block or a [gallery] shortcode -- or a post uses it as its featured image; then it is kept as an ordinary image, and the screen that regenerated it says so, since that post still shows the old picture. A post in the trash counts, as it can be restored; revisions and drafts saved automatically do not. One a translated copy of the PDF shares is kept as that copy's thumbnail. Block, Text, Custom HTML, Image and Gallery widgets are checked too; other settings that name an image are not. The extension follows the output format you choose.

= Shortcodes =

* `[rapls_pdf_thumbnail id="123"]` - Display thumbnail image
* `[rapls_pdf_thumbnail_url id="123"]` - Output thumbnail URL
* `[rapls_pdf_clickable_thumbnail id="123"]` - Thumbnail linked to PDF
* `[rapls_pdf_download_link id="123"]` - Download link with thumbnail

The thumbnail, clickable thumbnail and download link shortcodes take a `class` attribute, which can hold several classes separated by spaces: `[rapls_pdf_thumbnail id="123" class="alignleft shadow"]`.

The shortcodes take a PDF's ID. Up to 1.4.20 they also took any post's ID and showed its featured image; a site with pages written that way can have it back with `add_filter( 'rapls_pdf_image_creator_shortcode_any_post', '__return_true' );`. A post is then shown only to a reader who may see it.

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

= Will it work on my server? =

PDF thumbnails depend on ImageMagick being able to read PDFs, and not every host allows that. Rapls PDF Image Creator checks by actually rendering a small test PDF, not by reading a configuration value, and tells you on the Status tab whether PDF rendering works on your server and, if not, what to ask your host for. Site Health flags it too when it doesn't.

= Optional add-on =

Everything described above is free and stays free; nothing in this plugin is limited to make room for anything else. A separate, paid add-on, Rapls PDF Image Creator Pro, adds: cropping (white margins trimmed, the top of the page kept, or a fixed shape), a page and a crop for each PDF on its own edit screen, a background queue, replacing a PDF in place with the previous file kept, and rendering with Ghostscript directly where ImageMagick cannot read PDFs. That last one needs a host that lets PHP start programs (exec or proc_open) and has Ghostscript installed; this plugin cannot do it itself, because plugins in the WordPress.org directory may not start other programs. The plugin links to the add-on in five places only: its row on the Plugins screen, a card at the bottom of its Settings tab, the Status tab (as one of three options when this server cannot render PDFs, or a small box when it can), and under the page-number field after you change it, until you dismiss it. The filter `rapls_pdf_image_creator_show_upsell`, returning false, removes all of them.

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

Check Settings > Rapls PDF Image Creator > Status. If the CMYK PDF Rendering row says "Broken on this server (tested)", that is the cause. If it says the test could not be run, it may be.

ImageMagick 6 can render a PDF it judges to be CMYK through Ghostscript's `bmpsep8` device, which writes a separation BMP that ImageMagick's own BMP reader cannot decode. Depending on the exact ImageMagick and Ghostscript versions, the read either fails outright or returns an empty raster — which becomes a blank white thumbnail. ImageMagick 7 uses a different device and is unaffected.

Not every ImageMagick 6 build is affected: on Xserver's 6.9.13-25 a CMYK page renders correctly. Since 1.3.2 the Status tab renders a test page and reports what this server actually does, rather than warning on the version number.

The plugin cannot work around this, because ImageMagick picks the device from the PDF's own content and no Imagick API call overrides it. Ask your hosting provider to upgrade to ImageMagick 7, or to change the `ps:cmyk` delegate in `delegates.xml` from `bmpsep8` to `pamcmyk32`. The second has no effect where ImageMagick calls Ghostscript as a library (`gslib` in the output of `convert -list configure`); then only the upgrade helps.

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

For one PDF, use the "Generate Thumbnail" or "Regenerate Thumbnail" link under it in the Media Library. The link is in the list view only; in the grid view, switch to the list view first.

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

JPEG, PNG, and WebP. Choose one under Image Settings on the Settings tab. WebP needs WordPress 5.8 or later and an ImageMagick that can write it; the setting says so when this server cannot.

= Will thumbnails be deleted when I uninstall the plugin? =

By default, yes. To keep generated images as regular attachments, enable "Keep Images on Uninstall" in Display Settings before uninstalling.

Even with that setting off, uninstalling keeps a generated image that a post uses as its featured image, or that a post's content or excerpt or a widget shows (its address, or its ID or the PDF's in an image or gallery block, a [gallery] shortcode or this plugin's own shortcodes), and makes it an ordinary image. A post in the trash counts, as it can be restored; revisions and drafts saved automatically do not. It also keeps one that a translated copy of the PDF shares. Featured images, post content and excerpts, and block, Text, Custom HTML, Image and Gallery widgets are checked -- in them, also any number where an ID could stand, as page builders keep an image by ID: an image used in a theme or page-builder setting, or anywhere else, is not found, so turn on "Keep Images on Uninstall" if you use them there. A post whose featured image was a PDF is given that PDF's image instead, with the setting on or off, so it looks as it did. If any of this cannot be checked, no image is deleted. On a very large site, reading every post can take longer than the server allows; if uninstalling stops there, no image has been deleted.

= What happens to the thumbnail when I delete a PDF? =

With "Hide Generated Images" on, the PDF's thumbnail is deleted with it, unless a post or a widget shows it, a post uses it as its featured image, or a translated copy of the PDF shares it. One a post or widget shows is kept as an ordinary image, attached where the PDF was; one a translated copy shares stays that copy's thumbnail. With the setting off, every thumbnail is kept as an ordinary image, except one a translated copy shares, which stays that copy's thumbnail.

= Why can't I pick a PDF in my page builder's or field plugin's image picker? =

Since 1.4.46, PDFs with a thumbnail are offered only in WordPress's own image pickers: the image, gallery and featured image blocks, and the classic editor's featured image. An image picker of another plugin may take only images, and a PDF handed to it broke there. If yours takes them, as every picker did up to 1.4.20, turn it back on: `add_filter( 'rapls_pdf_image_creator_pdfs_in_every_image_picker', '__return_true' );`.

= Can I use a different page for the thumbnail? =

Yes. Use the `rapls_pdf_image_creator_thumbnail_page` filter:

`
add_filter( 'rapls_pdf_image_creator_thumbnail_page', function( $page, $pdf_id ) {
    return 1; // Use second page (0-indexed)
}, 10, 2 );
`

Without code, you can set the page for the whole site in Settings > Rapls PDF Image Creator > Page Number (0 is the first page).

= How do I customize the insert output? =

In Settings > Rapls PDF Image Creator, under Insert Settings, choose Thumbnail Image, Title (text link only) or Custom HTML. A custom template can use `%PDF_URL%`, `%THUMBNAIL_URL%`, `%TITLE%`, `%ID%` and `%ATTACHMENT_URL%`. These settings apply to "Insert into post" in the classic editor's Add Media window; the block editor does not use them.

= Does it need Ghostscript? =

The plugin itself never runs Ghostscript. ImageMagick does, though: it passes PDFs to Ghostscript to read them, so ImageMagick's PDF support only works if Ghostscript is installed on the server. You don't have to check this by hand. The Status tab (Settings > Rapls PDF Image Creator > Status) tells you whether PDFs can be rendered on your server.

= Is this plugin affected by the Ghostscript vulnerability CVE-2026-39919? =

It is not a fault in this plugin, but PDFs reach Ghostscript through it. CVE-2026-39919 is a buffer overflow in Ghostscript before 10.08.0, set off by a crafted PDF. This plugin never runs Ghostscript itself; ImageMagick does, to draw a PDF, and WordPress's own PDF preview takes the same path, so turning the plugin off does not take a site out of it. The fix is on the server: ask your hosting provider whether its Ghostscript is 10.08.0 or later, or a distribution package that includes the fix. The version number alone cannot tell, because distributions add the fix to older versions, so the plugin does not check it; where this server renders PDFs, the Status tab and Site Health say what to ask. Until the host confirms it, denying the PDF coder in ImageMagick's policy.xml keeps PDFs away from Ghostscript, and stops both this plugin's thumbnails and WordPress's PDF previews. As of October 17, 2026, no attack using it had been reported, and whether one succeeds depends on the server.

= My thumbnails are black. =

Two causes of black thumbnails have been fixed: PDF/X-1:2001 files (1.0.9), and transparent areas (1.1.0), which are now flattened onto the background colour you choose before the image is saved. If you choose a transparent background with JPEG output, white is used instead, because JPEG cannot store transparency. Thumbnails made before an update are not changed automatically, so regenerate an old black thumbnail before assuming the problem is still there.

= Can I change the resolution? =

Yes. Settings > Rapls PDF Image Creator > Rendering Resolution sets the DPI the page is rendered at, from 72 to 600 (150 by default). Raising it gives a larger, sharper thumbnail: Max Width and Max Height only scale the rendered page down, never up. Higher values use more memory while the thumbnail is being made.

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
* `rapls_pdf_image_creator_max_render_pixels` - Most pixels the chosen page is drawn at before resizing (default: 40000000 -- a little above an A4 page at 600 DPI). A larger page is drawn at a lower resolution instead. The size is read from the PDF's own page boxes, so this costs nothing for an ordinary PDF; a quick extra Ghostscript run measures the page only when the boxes cannot say, or say a page in the document may be over the limit
* `rapls_pdf_image_creator_thumbnail_quality` - Image quality, 1-100 (the setting itself starts at 10)
* `rapls_pdf_image_creator_thumbnail_format` - Output format
* `rapls_pdf_image_creator_thumbnail_bgcolor` - Background color
* `rapls_pdf_image_creator_thumbnail_image_attributes` - Image tag attributes
* `rapls_pdf_image_creator_custom_insert_html` - Custom insert HTML
* `rapls_pdf_image_creator_hide_thumbnails_in_library` - Hide in Media Library
* `rapls_pdf_image_creator_policy_paths` - ImageMagick policy.xml paths to search when diagnosing blocked PDF support
* `rapls_pdf_image_creator_delegate_paths` - ImageMagick delegates.xml paths to search when diagnosing which Ghostscript device CMYK PDFs are drawn with
* `rapls_pdf_image_creator_imagick_result` - The conversion's result, before it is returned; anything but a result object is ignored
* `rapls_pdf_image_creator_refuse_blank_page` - Return false to keep a page that comes out all white instead of refusing it (default: true). Its second argument is the conversion's options
* `rapls_pdf_image_creator_ghostscript_options` - Ghostscript switches for the blank-page retry (default: `-dNOTRANSPARENCY`); switches that loosen its sandbox, point at files or change what is drawn are left out
* `rapls_pdf_image_creator_before_resize` - The page as drawn, before it is resized; return it, changed or not
* `rapls_pdf_image_creator_engines` - The conversion engines to choose from, in order; an entry that is not an engine is left out
* `rapls_pdf_image_creator_show_review_prompt` - Return false to never show the request for a review
* `rapls_pdf_image_creator_pdfs_in_every_image_picker` - Return true (exactly `true`: 1 or "1" is not taken) to offer PDFs with a thumbnail in every media picker that asks for images, as up to 1.4.20 (default: false, WordPress's own pickers only). Its second argument is the media query; the block editor asks it once more with an empty array for its pickers
* `rapls_pdf_image_creator_shortcode_any_post` - Return true (exactly `true`) for the shortcodes to take any post's ID and show its featured image, as up to 1.4.20 (default: false, a PDF's ID only)

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

== Bundled third-party assets ==

= sRGB2014.icc =

An ICC colour profile from the International Color Consortium's profile
registry (https://www.color.org/srgbprofiles.xalter), used as the destination
profile when converting CMYK PDF pages to sRGB, and attached to the generated
image.

Licence, from https://registry.color.org/profile-library/ :

"This profile is made available by the International Color Consortium, and may
be copied, distributed, embedded, made, used, and sold without restriction.
Altered versions of this profile shall have the original identification and
copyright information removed and shall not be misrepresented as the original
profile."

Shipped unaltered. Compatible with GPL-2.0-or-later.

No other profile is bundled. CMYK profiles are read from the host when one is
present and are never redistributed.

== Changelog ==
= 1.4.47 =
* Added: where this server renders PDFs, the Status tab and Site Health's Info tab say what to ask your hosting provider about the Ghostscript vulnerability CVE-2026-39919. The plugin cannot tell whether the server's Ghostscript is fixed, so it does not judge it
* Added: a FAQ entry about CVE-2026-39919: what it is, why PDFs reach Ghostscript, what to ask the host, and what denying PDFs in policy.xml stops

= 1.4.46 =
The optional add-on
* Added: the optional paid add-on, Rapls PDF Image Creator Pro, is mentioned in five places only (the Plugins screen row, a card at the bottom of the Settings tab, the Status tab as one of three options when this server cannot render PDFs or a small box when it can, and under the page-number field after it is changed, until dismissed); nothing in this plugin is limited, and the filter rapls_pdf_image_creator_show_upsell, returning false, removes all of them
Choosing a PDF as an image
* Changed: PDFs with a thumbnail are offered in WordPress's own image pickers only -- the image, gallery and Post Featured Image blocks, the block editor's featured image, and the classic editor's "Set featured image" and the featured image view of "Add Media". Other plugins' image pickers, core pickers that also take other media such as Cover and Media & Text, and the classic editor's "Create Gallery" (whose [gallery] shortcode shows images only) are not given PDFs. The filter rapls_pdf_image_creator_pdfs_in_every_image_picker, returning true, offers them in every picker that asks for images, as up to 1.4.20
* Fixed: a PDF in a gallery block, or chosen at a size its thumbnail has no file for, shows the thumbnail instead of a broken image
* Fixed: a PDF is offered only when it has a thumbnail; one whose thumbnail could not be made, or was deleted from the Media Library, is not
* Changed: a PDF dropped or pasted into the block editor is no longer turned into an image block. WordPress's image block cannot upload a PDF, so the upload failed; it now becomes a File block, as without this plugin. To show a PDF as an image, choose it in the image block's Media Library tab
* Changed: with Auto Generate off, the image block's upload tab says beforehand that a PDF uploaded there gets no thumbnail, and one uploaded there by mistake can be deleted again from the notice that says so
* Changed: in the REST API, a generated thumbnail's source_url and link are its own, as WordPress defines them; the PDF is given in rapls_pic_source_pdf_id and rapls_pic_source_pdf_url. Each size's URL is now built from the image's own URL, so it follows a CDN or offload plugin that rewrote it
* Fixed: an image that another plugin attached to a PDF is no longer taken for this plugin's thumbnail
* Fixed: hiding generated images applies only to the Media Library's list and to the media window (wherever it is opened), and no longer changes other queries in the admin or their search conditions
* Fixed: another plugin or theme asking for a PDF's image with an empty or missing value, or handing WordPress's media filters something unexpected, no longer stops the page with a PHP error
* Changed: the Media Library's list view reads the PDFs' images in one query, instead of one for each row
* Changed: after "Regenerate Thumbnail", the Media Library comes back to the page, search and filter it was on, and a failure says why, with a link to the Status tab for administrators

Bulk Generate
* Fixed: the Media Library is read 200 PDFs per request, for the scan, the statistics and finding the next PDF, so large libraries no longer run out of memory or time. The statistics are read only when the Status tab is opened, and no longer on every visit to the Media Library
* Changed: Stop says it stops after the current PDF, and Continue carries on from the next one. Stop pressed while the next PDF is being looked for no longer starts drawing it
* Changed: the settings tabs and the progress are announced to screen readers, and the tabs move with the arrow keys, Home and End
* Fixed: a library whose PDFs are all in the trash says so, instead of suggesting another plugin is filtering the list
* Changed: with "Include existing", finding the next PDF reads one row instead of 200

Making the thumbnail
* Fixed: a blank page drawn as transparent is now recognised as blank, so the retry for blank pages runs for it too, and a page that is blank is reported as such
* New: a page number past the end of the PDF fails with its own reason, which says how many pages the file has, and is not retried. The pages are counted from the PDF's own page tree, which is not trusted for anything else: having Ghostscript count them draws every page, and nothing bounds how many a file holds. A PDF that claims more pages than it has, had pages deleted when it was last saved, or keeps its page tree in compressed object streams, as many recent PDFs do, gets the ordinary failure reason instead
* Fixed: an error from ImageMagick while drawing a page is reported as a drawing failure, not as a server that cannot render PDFs
* Removed: the retry that read the whole document when the chosen page came back blank. It never rescued a page, and a PDF that understates its page count could make it draw every page -- 954 MB for one 60-page file. A blank page is still tried once more with Ghostscript's transparency handling switched off
* Fixed: a page is drawn at a lower resolution when it would come out larger than 40 million pixels, or larger than this server's own ImageMagick limits on width, height, memory and disk. Its size is read from the PDF's page boxes, compressed object streams included, with the file read token by token as a PDF reader reads it, so that no key, string or comment can hide a page from it, and with limits on how deep, how long and how many of anything it reads, so that no file can exhaust PHP's memory or stack while it is read; a page they cannot vouch for -- a box whose numbers are too large to hold, or /UserUnit, among them -- or one in a document with a page over the limit, is drawn small to measure it first. Where the page sizes can be read from the file, nothing in a PDF is drawn when a page in it is too large even at the lowest resolution, since that page would be drawn before any limit could refuse it: an ordinary page beside it is refused with it. A PDF portfolio, which holds other files that Ghostscript draws in place of its own pages, is not drawn either, and says so. A PostScript or EPS file, which Ghostscript draws as it does a PDF, is bounded the same way -- found by its first bytes or by its name, XPS and PCL included -- and so is any file that does not start like an image ImageMagick reads by itself; images are read as they are, as before, even one named .pdf. A page too thin to measure, in a PDF with a page far too large to measure it more finely or whose largest page size cannot be read, is not drawn, and says so, with the resolution it was measured at. A small PDF with a 200-inch page used to take 76 seconds and 2.9 GB; it now takes about two, and a 100,000-point page hidden in an object stream about one and a half. A page too large even at the lowest resolution is refused with its own reason. The filter rapls_pdf_image_creator_max_render_pixels changes the limit
* Fixed: the Page Selection test on the Status tab now asks for a page by number, as making a thumbnail does
* Fixed: numbers that a filter hands back as text are made whole numbers, and an error raised during conversion is a failed conversion, not a broken upload
* Fixed: the list of functions a host disables is read the way PHP reads it, so the blank-page retry is no longer turned off by a name PHP does not disable
* Fixed: an image handed over through a filter to be finished is no longer read again as a PDF
* Fixed: a thumbnail larger than 2560 pixels on either side, which WordPress saves as a smaller "-scaled" copy, no longer fails every time; nor does a thumbnail on a site that has WordPress convert uploaded images to another format
* Fixed: GS_OPTIONS that the site already sets are kept, with -dNOTRANSPARENCY added to them for the retry, and are put back even when the request stops part way, at a time limit
* Changed: the rapls_pdf_image_creator_ghostscript_options filter takes only -q, -dSAFER and switches that set a value (-dNAME, -dNAME=value, -sNAME=value), and none that loosens Ghostscript's SAFER mode, sends its output elsewhere, points it at files on the server, or changes the page drawn, its size in pixels, how much is drawn to make it, how many copies, which of Ghostscript's PDF interpreters draws it or how much memory Ghostscript holds for it. A switch it does not take is left out of the retry, which runs with the rest, and the error log names it; a value with characters other than those switches are made of, or one with nothing left but -q and -dSAFER, turns the retry off
* Changed: the page count is read from the PDF itself where it can be, instead of having Ghostscript go through the whole document
* Fixed: a request that stops while drawing, at a time limit, no longer leaves an empty image file behind
* Fixed: when the server check fails for a reason of the moment -- Ghostscript stopped by a memory or time limit -- it is tried again after ten minutes and the Status tab says so, instead of every thumbnail stopping for twelve hours
* Fixed: on ImageMagick 6, a PDF that Ghostscript cannot draw is reported as a drawing failure, not as a server that cannot render PDFs

Settings and Status
* Changed: the CMYK warning on the Status tab is shown only when this server's own test did not render CMYK correctly
* Changed: where this server or this WordPress cannot make WebP thumbnails, the WebP setting says so
* Fixed: placeholders in a Custom HTML template are escaped, so a title with a quote in it no longer breaks the markup
* Fixed: the thumbnail, clickable thumbnail and download link shortcodes keep several classes in their class attribute
* Changed: the shortcodes take a PDF's ID only. The ID of a post, or of another kind of attachment, now gives nothing, where it could show that post's featured image. The filter rapls_pdf_image_creator_shortcode_any_post, returning true, takes them again as up to 1.4.20, for readers who may see that post; a post of a type that is not public, such as a reusable block, only for people who can edit it
* Changed: the shortcodes show a PDF to visitors only when it is public. A PDF attached to a private or draft post is shown only to people who can read that post, and one attached to a password-protected post only to people who have entered its password or can edit it -- including on another, public page
* Fixed: a shortcode with no class attribute keeps its pic-thumbnail class
* Changed: Insert into post, as a thumbnail or a title, follows the Link To and Size chosen in the media window (a Custom HTML template decides its own links). The plugin's Link To and Insert Size settings are now where those choices start for a PDF, including one just uploaded in that window. An Insert Size the window does not list, such as medium_large or a theme's size, is still used as long as the window's Size is not changed
* Fixed: dismissing the notice about this server's PDF support stays on the screen it was on
* Changed: the regeneration notices and Bulk Generate's log put their sentences together the way the language does, not always with a space or a colon
* Changed: clearer wording on the settings screen and in the readme, and the Media Library column's marks are read out to screen readers; the settings screen's groups of options are named for screen readers and its colours are easier to read
* Changed: the settings screen's script is loaded on the settings screen only
* Fixed: without JavaScript, every tab of the settings screen is shown, so the Status tab can be read, and the buttons that need JavaScript are replaced by a note saying what to use instead
* Changed: the review request, and the Support box on the Status tab, are not shown while this server cannot make thumbnails
* Fixed: Bulk Generate shows how many PDFs there are and how many are still to do separately, and a failure says why
* Fixed: saving the settings without some of the options -- from code or WP-CLI -- no longer stores empty values for them or turns the checkboxes it left out off; they keep what was saved
* Changed: a few queries fewer on every page load, and the Status tab's "working" colour is easier to read
* Fixed: replacing the plugin's folder with a new version and activating it again runs the update's own work, as an update from the Plugins screen does
* Fixed: without the settings screen's script -- blocked, or broken by another script -- every tab stays in view

Keeping and deleting images
* Fixed: regenerating a thumbnail, deleting a PDF and uninstalling no longer delete a generated image that a post's content shows -- by its address in any size, in a retina (@2x) or WebP copy's name, or under the name it had before it was edited, or by its ID in an image or gallery block or a [gallery] shortcode -- which left a broken image in the post. The image is kept as an ordinary one, attached where the PDF was when the PDF is deleted. Names are compared whole, and in any letter case for the letters A to Z, whatever the database's collation, so a post showing "annual-report" does not keep "report" and one showing "Report" keeps "report". A post's excerpt counts too (a product's short description), and so does a post in the trash, which can be restored; revisions and drafts saved automatically do not. An image a post uses as its featured image is kept too, and uninstalling gives a post whose featured image was a PDF that PDF's image. An image a page builder keeps in the content by its ID alone, such as [vc_single_image image="555"], is kept as well: any number where an ID could stand -- an attribute's value, a JSON value, an item of a list divided by commas or by spaces -- counts, which can keep an image a post does not show, and never deletes one it does. A name written in JSON with \u escapes is found too, a name with "--" in it among them, which the block editor writes as \u002d\u002d. The database is searched with LIKE alone, not regular expressions, which MySQL 8 could stop on a long post and MariaDB could skip it. If this cannot be checked, nothing is deleted. Widgets are checked as well -- block, Text, Custom HTML, Image and Gallery widgets; other settings and a page builder's data kept outside the post are not
* Fixed: an image a post uses as its featured image is kept on uninstall even when the record of which PDF it was made from is missing
* Fixed: a thumbnail shared with a translated copy of its PDF is handed to that copy, and attached to it, when this PDF lets go of it -- also when the PDF is deleted with "Hide Generated Images" off -- so the copy's own regeneration or deletion still finds it
* Fixed: when another plugin refuses to let a regeneration delete the old thumbnail, it is kept as an ordinary image, instead of staying hidden with nothing naming it
* Fixed: deleting a generated image from the Media Library no longer leaves its PDF naming an image that is gone
* Fixed: when a PDF is deleted, an old thumbnail a regeneration kept is attached where the PDF was, and its image is kept as an ordinary one if another plugin refuses to delete it, instead of staying hidden and attached to nothing
* Fixed: when a PDF is deleted, a thumbnail of it that an earlier version left hidden, with nothing naming it, is handed to a PDF that names it or kept as an ordinary image, instead of staying hidden
* Fixed: an image is kept when a post shows its file name with one character written as an HTML entity, percent-encoded or as a JSON escape, or names its ID after a JSON escape
* Fixed: an image that an audio or video file uses as its cover is no longer taken for another PDF's thumbnail, which left it marked and hidden
* New: a regeneration that kept the old thumbnail says so on the screen that ran it, and why: a post shows it (and still shows the old picture until it is replaced there), a post uses it as its featured image, another copy of the PDF -- a translation's -- uses it, or whether anything uses it could not be told
* Changed: "Insert into post" gives the image the "wp-image-" class with its ID, as WordPress's own insert does, so WordPress adds srcset to it
* Removed: the AJAX action rapls_pic_regenerate_thumbnail, unused since the Media Library's own Regenerate link took over; and the insert keys the plugin added to wp_prepare_attachment_for_js, which nothing read
* Changed: the methods behind those two -- MediaLibrary::ajaxRegenerateThumbnail() and Inserter::addInsertSettingsToJs() -- and BulkProcessor::getPDFs() and getStats(), which Bulk Generate no longer uses, are kept, deprecated and hooked to nothing, for code that calls them

= 1.4.20 =
* Fixed: deleting the plugin from a multisite network now cleans up every site, each by its own "Keep Images on Uninstall" setting. Before, only the site it was deleted from was cleaned up, and the other sites kept the plugin's settings, its markers on PDFs and images, and -- with that setting off -- the generated images

= 1.4.19 =
* Fixed: whether an old or unused thumbnail was deleted is now judged by whether it is still there afterwards, not by what the deletion returned. Another plugin answering pre_delete_attachment with the attachment itself stops the deletion while looking like success, and the PDF then lost its link to an image that stayed in the Media Library. An attachment already deleted by something else is now counted as gone

= 1.4.18 =
* Fixed: Generator::outcomeFor() now forgets the previous attempt's result as soon as a new generate() begins. An error thrown during the first checks of a new attempt left the previous result in place, where it could be taken for the new one

= 1.4.17 =
* Changed: an error thrown by code listening to rapls_pdf_image_creator_after_generate or rapls_pdf_image_creator_generation_failed is no longer caught, as it was in 1.4.15 and 1.4.16; it reaches the caller, as errors in WordPress hooks normally do. WordPress does not tidy up a hook after an error inside it, so carrying on quietly left that hook in an unfinished state for the rest of the request
* Added: Generator::outcomeFor( $pdf_id ) says what the last attempt for a PDF came to in the current request -- made or not, the thumbnail ID, and the failure code and message. It is recorded before the actions run, so it does not depend on which listeners run first or whether one throws
* Fixed: when a new thumbnail did not come out as drawn and another plugin refused to let it be deleted, nothing said so. This is now written to the PHP error log

= 1.4.16 =
* Fixed: an error thrown by code listening to rapls_pdf_image_creator_generation_failed no longer replaces the failure it was told about. The failure is still recorded and returned as it was, and the error is written to the PHP error log
* Fixed: when a new thumbnail could not be recorded for its PDF and another plugin refused to let it be deleted, nothing said so. This is now written to the PHP error log
* Fixed: the Settings class's save() now reports success when the stored settings hold the values given, including when they already did, and saves onto the settings as they are stored now rather than a copy read earlier

= 1.4.15 =
* Fixed: when a new thumbnail had to be removed again and another plugin refused the deletion, its image file was deleted anyway and the attachment was left pointing at nothing. The file now stays with the attachment
* Fixed: a new thumbnail is now checked to point at the image file that was drawn. When a filter made it point at another file, it was reported as made; it is now pointed back and removed, and if it cannot be pointed back nothing is deleted, since that other file may belong to something else
* Fixed: the rapls_pdf_image_creator_thumbnail_format filter now also decides the file extension and MIME type. Before, it changed only the image data, so a PNG could be saved as .jpg and registered as a JPEG
* Fixed: an error thrown by another plugin while a thumbnail was being drawn no longer leaves an empty image file behind, and an error thrown after a thumbnail was made no longer makes it look as if it had failed
* Changed: when an old thumbnail cannot be deleted after it has been replaced, this is now written to the PHP error log

= 1.4.14 =
* Fixed: when a regeneration could not save the new featured image, the PDF's own thumbnail record is now put back exactly as it was. A featured image chosen by hand was written into it instead, and was then taken for a thumbnail this plugin had made
* Fixed: a regenerated thumbnail whose image metadata could not be made is no longer reported as made; the previous thumbnail is kept. The Media Library did not show such a thumbnail for the PDF
* Fixed: two regenerations of the same PDF in the same second could write the same image file and register it twice, so deleting one removed the other's image. Each now takes its file name before drawing into it

= 1.4.13 =
* Fixed: a regenerated thumbnail is now kept only once the marks that tell the plugin it made the image, and the image's metadata, have been saved. When they were not, the image was reported as made but could never be removed by a later regeneration
* Fixed: with "Set as featured image" on, a regeneration whose featured image could not be saved now fails and keeps the previous thumbnail. Before, it reported success and the PDF was left with no featured image
* Fixed: when an old thumbnail could not be deleted, the PDF now keeps its link to it, so the image is not left behind with nothing pointing at it

= 1.4.12 =
* Security: the Media Library's "Regenerate Thumbnail" request now checks that the user may edit that PDF, as the link itself already did. Before, any user who could upload files could regenerate -- and so replace the thumbnail of -- a PDF they could not edit. The Bulk Generate requests now require the same permission as the settings page they are on
* Fixed: regenerating a thumbnail no longer deletes the old one first. The new thumbnail is made and recorded, and only then is the old one removed, so a PDF whose new thumbnail cannot be made keeps the one it had
* Fixed: regenerating or deleting a thumbnail no longer removes a featured image the PDF was given by hand. Only a link that names the generated thumbnail is removed
* Fixed: a few messages on the Bulk Generate tab and the Media Library were shown in English whatever the admin language

= 1.4.11 =
* Changed: the FAQ says which generated images uninstalling keeps -- those used as a featured image or shared with a translated copy of the PDF -- and that an image placed inside a post's content is not detected, so "Keep Images on Uninstall" should be turned on first in that case

= 1.4.10 =
* Fixed: uninstalling the plugin (with "Keep Images on Uninstall" off) no longer deletes a generated thumbnail that another post uses as its featured image, or that a translated copy of the PDF shares. Those are kept as ordinary images, as regenerating and deleting a PDF already did since 1.4.8. When this cannot be checked, no image is deleted. An image placed inside a post's content is not detected; turn on "Keep Images on Uninstall" to keep those

= Earlier versions =
The changes in 1.4.9 and earlier are listed on the plugin's page: https://raplsworks.com/plugins/rapls-pdf-image-creator/

== Upgrade Notice ==

= 1.4.47 =
The Status tab and Site Health now say what to ask your host about the Ghostscript vulnerability CVE-2026-39919, which affects how PDFs are drawn on the server. See the FAQ.

= 1.4.46 =
PDFs appear only in core's image pickers, shortcodes take a PDF's ID only, and a dropped PDF becomes a File block; two filters restore the old pickers and IDs (see FAQ). Bulk Generate handles large libraries, huge pages cannot exhaust the server, and regenerating keeps images posts show.

= 1.4.20 =
Includes the 1.4.12 security fix. Deleting the plugin from a multisite network now cleans up every site.

= 1.4.19 =
Includes the 1.4.12 security fix and the 1.4.13-1.4.18 regeneration fixes.

= 1.4.18 =
Includes the 1.4.12 security fix and the 1.4.13-1.4.17 regeneration fixes.

= 1.4.17 =
Includes the 1.4.12 security fix. Errors in code listening to this plugin's hooks are no longer hidden; the result of each regeneration can be read with Generator::outcomeFor().

= 1.4.16 =
Includes the 1.4.12 security fix. Errors in code listening to this plugin's hooks no longer change what a regeneration reports.

= 1.4.15 =
Includes the 1.4.12 security fix. A failed or interrupted regeneration no longer leaves an attachment without its file, or an empty image file behind.

= 1.4.14 =
Includes the 1.4.12 security fix and the 1.4.13 regeneration fixes. A failed regeneration now leaves the PDF's records exactly as they were.

= 1.4.13 =
Includes the 1.4.12 security fix. A regeneration that cannot save the new thumbnail completely now fails and keeps the previous one.

= 1.4.12 =
Security fix: regenerating a PDF's thumbnail now requires permission to edit that PDF. A failed regeneration also keeps the old thumbnail.

= 1.4.11 =
The FAQ now says which generated images uninstalling keeps.

= 1.4.10 =
Uninstalling no longer deletes a generated thumbnail that a post or a translated PDF still uses.

= 1.4.9 =
A thumbnail is kept, not deleted, when the plugin cannot check whether something else still uses it.

= 1.4.8 =
Regenerating a thumbnail, or deleting a PDF, no longer deletes a thumbnail that a translated copy of the PDF or a post still uses.

= 1.4.7 =
Regenerating a thumbnail, or deleting a PDF, no longer deletes an image that is not that PDF's own thumbnail, such as a featured image set by another plugin.

= 1.4.6 =
A PDF whose chosen page is blank is no longer read in full, every page, each time its thumbnail is made.

= 1.4.5 =
Fixes a fatal error in the Media Library on WordPress 5.0 to 5.2.

= 1.4.4 =
With automatic generation off, WordPress's own PDF preview is now deleted from the right folder, instead of left on disk.

= 1.4.3 =
The Edit Media screen now shows a PDF's generated thumbnail instead of WordPress's own preview, which is blank for the PDFs the Ghostscript workaround exists for.

= 1.4.2 =
Asks for a review once, a week after activation, and only if the plugin has actually made a thumbnail here. One notice, on this plugin's own screen, and it does not come back.

= 1.4.1 =
A PDF that came back blank is now retried with a workaround for a known Ghostscript fault, and a regenerated thumbnail gets a filename of its own so browsers stop showing the old one.

= 1.4.0 =
When a thumbnail fails to generate, the Status tab now tells you why instead of leaving you with a PDF and no picture. A page that renders as a blank white image is refused rather than saved. Also checks whether picking a page other than the first actually works on your server.

= 1.3.2 =
The ImageMagick 6 CMYK warning is now based on rendering a test page instead of the version number, so servers where CMYK works are no longer warned.

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
