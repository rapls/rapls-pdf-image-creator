<?php
/**
 * Settings Page Template
 *
 * @package PDFImageCreator
 * @var array $config Current configuration
 * @var array $capabilities Server capabilities
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap rapls-pic-settings">
    <h1><?php esc_html_e('Rapls PDF Image Creator', 'rapls-pdf-image-creator'); ?></h1>

    <p class="description">
        <?php esc_html_e('Automatically generate thumbnail images from PDF files uploaded to the Media Library.', 'rapls-pdf-image-creator'); ?>
    </p>

    <div class="rapls-pic-tabs">
        <?php // The tab pattern, so a screen reader says which tab is chosen; admin.js adds the roles and keeps the states and the arrow keys (Codex review of 1.4.26, 4). Without it every panel shows and these are links to them (review of 1.4.35). ?>
        <nav class="nav-tab-wrapper">
            <a href="#tab-settings" id="rapls-pic-tab-link-settings" class="nav-tab nav-tab-active" data-tab="settings" aria-controls="tab-settings">
                <?php esc_html_e('Settings', 'rapls-pdf-image-creator'); ?>
            </a>
            <a href="#tab-bulk" id="rapls-pic-tab-link-bulk" class="nav-tab" data-tab="bulk" aria-controls="tab-bulk">
                <?php esc_html_e('Bulk Generate', 'rapls-pdf-image-creator'); ?>
            </a>
            <a href="#tab-status" id="rapls-pic-tab-link-status" class="nav-tab" data-tab="status" aria-controls="tab-status">
                <?php esc_html_e('Status', 'rapls-pdf-image-creator'); ?>
            </a>
        </nav>
    </div>

    <!-- Settings Tab -->
    <div id="tab-settings" class="rapls-pic-tab-content active" aria-labelledby="rapls-pic-tab-link-settings">
        <form method="post" action="options.php">
            <?php settings_fields('rapls_pic_settings_group'); ?>
            <input type="hidden" name="rapls_pic_settings[<?php echo esc_attr(\Rapls\PDFImageCreator\Admin::FORM_MARKER); ?>]" value="1">

            <h2><?php esc_html_e('Image Settings', 'rapls-pdf-image-creator'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="rapls_pic_max_width"><?php esc_html_e('Max Width', 'rapls-pdf-image-creator'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="rapls_pic_max_width" name="rapls_pic_settings[max_width]"
                            value="<?php echo esc_attr((string) $config['max_width']); ?>"
                            min="100" max="4096" class="small-text"> px
                        <p class="description">
                            <?php esc_html_e('Maximum width of generated thumbnail. (100-4096)', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="rapls_pic_max_height"><?php esc_html_e('Max Height', 'rapls-pdf-image-creator'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="rapls_pic_max_height" name="rapls_pic_settings[max_height]"
                            value="<?php echo esc_attr((string) $config['max_height']); ?>"
                            min="100" max="4096" class="small-text"> px
                        <p class="description">
                            <?php esc_html_e('Maximum height of generated thumbnail. (100-4096)', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="rapls_pic_resolution"><?php esc_html_e('Rendering Resolution', 'rapls-pdf-image-creator'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="rapls_pic_resolution" name="rapls_pic_settings[resolution]"
                            value="<?php echo esc_attr((string) $config['resolution']); ?>"
                            min="72" max="600" class="small-text"> DPI
                        <p class="description">
                            <?php esc_html_e('Resolution the PDF page is rendered at. (72-600)', 'rapls-pdf-image-creator'); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Raise this to get a larger thumbnail: the maximum dimensions above only scale the rendered page down, never up. Higher values use more memory during generation.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="rapls_pic_quality"><?php esc_html_e('Quality', 'rapls-pdf-image-creator'); ?></label>
                    </th>
                    <td>
                        <input type="range" id="rapls_pic_quality" name="rapls_pic_settings[quality]"
                            value="<?php echo esc_attr((string) $config['quality']); ?>"
                            min="10" max="100" class="rapls-pic-range">
                        <?php /* The number follows the slider only through admin.js: without it, it stayed at the saved value while the slider moved (review of 1.4.36). */ ?>
                        <output class="rapls-pic-needs-js"><?php echo esc_html((string) $config['quality']); ?></output>
                        <noscript><span><?php
                            /* translators: %d: the quality setting as saved, 10-100. */
                            echo esc_html(sprintf(__('Saved: %d', 'rapls-pdf-image-creator'), (int) $config['quality']));
                        ?></span></noscript>
                        <p class="description">
                            <?php esc_html_e('Image quality for JPEG/WebP output. (10-100)', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Output Format', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Output Format', 'rapls-pdf-image-creator'); ?></legend>
                            <label>
                                <input type="radio" name="rapls_pic_settings[format]" value="jpeg"
                                    <?php checked($config['format'], 'jpeg'); ?>>
                                <?php esc_html_e('JPEG', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="rapls_pic_settings[format]" value="png"
                                    <?php checked($config['format'], 'png'); ?>>
                                <?php esc_html_e('PNG', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="rapls_pic_settings[format]" value="webp"
                                    <?php checked($config['format'], 'webp'); ?>>
                                <?php esc_html_e('WebP', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <?php if (!\Rapls\PDFImageCreator\Admin::webpWorks()) : ?>
                                <p class="description">
                                    <?php esc_html_e('WebP cannot be used on this server: WordPress before 5.8 does not accept WebP images, or ImageMagick here cannot write them. Thumbnails set to WebP fail to generate.', 'rapls-pdf-image-creator'); ?>
                                </p>
                            <?php endif; ?>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Background Color', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Background Color', 'rapls-pdf-image-creator'); ?></legend>
                            <label>
                                <input type="radio" name="rapls_pic_settings[bgcolor]" value="white"
                                    <?php checked($config['bgcolor'], 'white'); ?>>
                                <?php esc_html_e('White', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="rapls_pic_settings[bgcolor]" value="black"
                                    <?php checked($config['bgcolor'], 'black'); ?>>
                                <?php esc_html_e('Black', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="rapls_pic_settings[bgcolor]" value="transparent"
                                    <?php checked($config['bgcolor'], 'transparent'); ?>>
                                <?php esc_html_e('Transparent (PNG and WebP only)', 'rapls-pdf-image-creator'); ?>
                            </label>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Background color for transparent areas in PDFs.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="rapls_pic_page"><?php esc_html_e('Page Number', 'rapls-pdf-image-creator'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="rapls_pic_page" name="rapls_pic_settings[page]"
                            value="<?php echo esc_attr((string) $config['page']); ?>"
                            min="0" class="small-text">
                        <p class="description">
                            <?php esc_html_e('Page to use for thumbnail (0 = first page).', 'rapls-pdf-image-creator'); ?>
                        </p>
                        <?php \Rapls\PDFImageCreator\Upsell::renderPageHint($config['page'] ?? 0); ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Behavior Settings', 'rapls-pdf-image-creator'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Auto Generate', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rapls_pic_settings[auto_generate]" value="1"
                                <?php checked(!empty($config['auto_generate'])); ?>>
                            <?php esc_html_e('Automatically generate thumbnail when PDF is uploaded', 'rapls-pdf-image-creator'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When off, WordPress\'s own PDF preview is not kept either: a PDF uploaded then shows the PDF icon until its thumbnail is generated.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Featured Image', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rapls_pic_settings[set_featured]" value="1"
                                <?php checked(!empty($config['set_featured'])); ?>>
                            <?php esc_html_e('Set generated thumbnail as PDF featured image', 'rapls-pdf-image-creator'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Enables using get_post_thumbnail_id() with PDF attachments.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

            </table>

            <h2><?php esc_html_e('Display Settings', 'rapls-pdf-image-creator'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Display Thumbnail Icon', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rapls_pic_settings[display_thumbnail_icon]" value="1"
                                <?php checked(!empty($config['display_thumbnail_icon'])); ?>>
                            <?php esc_html_e('Display generated thumbnail instead of default PDF mime-type icon', 'rapls-pdf-image-creator'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Where WordPress shows a file-type icon for a PDF, the generated thumbnail is shown instead. The Media Library\'s grid and list show a PDF\'s thumbnail whether or not this is on.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Hide Generated Images', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rapls_pic_settings[hide_generated_images]" value="1"
                                <?php checked(!empty($config['hide_generated_images'])); ?>>
                            <?php esc_html_e('Hide generated thumbnail images in the Media Library', 'rapls-pdf-image-creator'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('This also decides what happens to the image when its PDF is deleted. Checked: the image is deleted with the PDF, unless something else uses it. Another PDF that shares it keeps it as its thumbnail; one a post uses as its featured image, or a post\'s content or excerpt or a widget shows, is kept as an ordinary image -- a post in the trash included, as it can be restored. Featured images, post content and excerpts, and widgets, are checked; other settings are not. Unchecked: the image is kept -- as the thumbnail of another PDF that shares it, otherwise as an ordinary image.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Keep Images on Uninstall', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rapls_pic_settings[keep_on_uninstall]" value="1"
                                <?php checked(!empty($config['keep_on_uninstall'])); ?>>
                            <?php esc_html_e('Keep generated images after the plugin is uninstalled', 'rapls-pdf-image-creator'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('If checked, generated image files will be handled as ordinary image files after plugin removal.', 'rapls-pdf-image-creator'); ?>
                            <?php esc_html_e('Images a post still shows, as its featured image or in its content or excerpt, and images a widget shows, are kept either way, a post in the trash included. A post whose featured image was a PDF is given that PDF\'s image.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Insert Settings', 'rapls-pdf-image-creator'); ?></h2>

            <p class="description">
                <?php esc_html_e('Configure how PDF thumbnails are inserted into content.', 'rapls-pdf-image-creator'); ?>
                <?php esc_html_e('These settings are used by "Insert into post" in the classic editor\'s Add Media window, where Size and Link To start at them for a PDF with a thumbnail; what is chosen there is used instead. The block editor does not use them.', 'rapls-pdf-image-creator'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Default Insert Type', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Default Insert Type', 'rapls-pdf-image-creator'); ?></legend>
                            <label>
                                <input type="radio" name="rapls_pic_settings[insert_type]" value="image"
                                    <?php checked($config['insert_type'] ?? 'image', 'image'); ?>>
                                <?php esc_html_e('Thumbnail Image', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="rapls_pic_settings[insert_type]" value="title"
                                    <?php checked($config['insert_type'] ?? 'image', 'title'); ?>>
                                <?php esc_html_e('Title (text link only)', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="rapls_pic_settings[insert_type]" value="custom"
                                    <?php checked($config['insert_type'] ?? 'image', 'custom'); ?>>
                                <?php esc_html_e('Custom HTML', 'rapls-pdf-image-creator'); ?>
                            </label>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('"Title" inserts only a text link to the PDF. "Custom HTML" allows using document viewer plugins.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="rapls_pic_insert_size"><?php esc_html_e('Default Insert Size', 'rapls-pdf-image-creator'); ?></label>
                    </th>
                    <td>
                        <?php
                        $rapls_pic_image_sizes = get_intermediate_image_sizes();
                        $rapls_pic_image_sizes[] = 'full';
                        // The names WordPress shows for its sizes, translated,
                        // where it has them: ucfirst() of the slug read
                        // "Medium_large" (review after 1.4.31).
                        $rapls_pic_size_names = apply_filters('image_size_names_choose', [
                            'thumbnail' => __('Thumbnail', 'rapls-pdf-image-creator'),
                            'medium' => __('Medium', 'rapls-pdf-image-creator'),
                            'large' => __('Large', 'rapls-pdf-image-creator'),
                            'full' => __('Full Size', 'rapls-pdf-image-creator'),
                        ]);
                        ?>
                        <select id="rapls_pic_insert_size" name="rapls_pic_settings[insert_size]">
                            <?php foreach ($rapls_pic_image_sizes as $rapls_pic_size) : ?>
                            <option value="<?php echo esc_attr($rapls_pic_size); ?>"
                                <?php selected($config['insert_size'] ?? 'medium', $rapls_pic_size); ?>>
                                <?php echo esc_html(is_array($rapls_pic_size_names) && isset($rapls_pic_size_names[$rapls_pic_size]) ? (string) $rapls_pic_size_names[$rapls_pic_size] : $rapls_pic_size); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('In the Add Media window, Size starts at this choice for a PDF with a thumbnail, and a size chosen there is used instead. A size the window does not list is used while the window\'s Size is left as it is.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Link To', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Link To', 'rapls-pdf-image-creator'); ?></legend>
                            <label>
                                <input type="radio" name="rapls_pic_settings[insert_link]" value="file"
                                    <?php checked($config['insert_link'] ?? 'file', 'file'); ?>>
                                <?php esc_html_e('PDF File', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="rapls_pic_settings[insert_link]" value="attachment"
                                    <?php checked($config['insert_link'] ?? 'file', 'attachment'); ?>>
                                <?php esc_html_e('Attachment Page', 'rapls-pdf-image-creator'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="rapls_pic_settings[insert_link]" value="none"
                                    <?php checked($config['insert_link'] ?? 'file', 'none'); ?>>
                                <?php esc_html_e('None (no link)', 'rapls-pdf-image-creator'); ?>
                            </label>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('In the Add Media window, Link To starts at this choice for a PDF with a thumbnail. A link chosen there is used instead.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr id="rapls-pic-custom-html-row">
                    <th scope="row">
                        <label for="rapls_pic_custom_html"><?php esc_html_e('Custom HTML Template', 'rapls-pdf-image-creator'); ?></label>
                    </th>
                    <td>
                        <textarea id="rapls_pic_custom_html" name="rapls_pic_settings[custom_html]" rows="5" cols="60" class="large-text code"><?php echo esc_textarea($config['custom_html'] ?? ''); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Available placeholders:', 'rapls-pdf-image-creator'); ?><br>
                            <code>%PDF_URL%</code> - <?php esc_html_e('URL to the PDF file', 'rapls-pdf-image-creator'); ?><br>
                            <code>%THUMBNAIL_URL%</code> - <?php esc_html_e('URL to the thumbnail image', 'rapls-pdf-image-creator'); ?><br>
                            <code>%TITLE%</code> - <?php esc_html_e('PDF title', 'rapls-pdf-image-creator'); ?><br>
                            <code>%ID%</code> - <?php esc_html_e('PDF attachment ID', 'rapls-pdf-image-creator'); ?><br>
                            <code>%ATTACHMENT_URL%</code> - <?php esc_html_e('URL to the attachment page', 'rapls-pdf-image-creator'); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Example: the thumbnail, linking to the PDF', 'rapls-pdf-image-creator'); ?><br>
                            <code>&lt;a href="%PDF_URL%"&gt;&lt;img src="%THUMBNAIL_URL%" alt="%TITLE%"&gt;&lt;/a&gt;</code>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <?php \Rapls\PDFImageCreator\Upsell::renderSettingsCard(); ?>
    </div>

    <!-- Bulk Generate Tab -->
    <div id="tab-bulk" class="rapls-pic-tab-content" aria-labelledby="rapls-pic-tab-link-bulk">
        <h2><?php esc_html_e('Bulk Generate Thumbnails', 'rapls-pdf-image-creator'); ?></h2>

        <p class="description">
            <?php esc_html_e('Generate thumbnails for existing PDF files in your Media Library.', 'rapls-pdf-image-creator'); ?>
        </p>

        <?php /* Read only by admin.js, so hidden with the buttons when it does not run (review of 1.4.36). */ ?>
        <table class="form-table rapls-pic-needs-js">
            <tr>
                <th scope="row"><?php esc_html_e('Options', 'rapls-pdf-image-creator'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" id="rapls-pic-include-existing" value="1">
                        <?php esc_html_e('Include PDFs that already have thumbnails (regenerate)', 'rapls-pdf-image-creator'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <noscript>
            <p class="description"><?php esc_html_e('Bulk Generate and the statistics need JavaScript. Without it, use the "Generate Thumbnail" link under each PDF in the Media Library\'s list view.', 'rapls-pdf-image-creator'); ?></p>
        </noscript>
        <p class="rapls-pic-needs-js">
            <button type="button" id="rapls-pic-bulk-scan" class="button button-secondary">
                <?php esc_html_e('Scan for PDFs', 'rapls-pdf-image-creator'); ?>
            </button>
            <button type="button" id="rapls-pic-bulk-start" class="button button-primary" disabled>
                <?php esc_html_e('Start Generation', 'rapls-pdf-image-creator'); ?>
            </button>
            <button type="button" id="rapls-pic-bulk-stop" class="button button-secondary" disabled>
                <?php esc_html_e('Stop after the current PDF', 'rapls-pdf-image-creator'); ?>
            </button>
        </p>
        <p id="rapls-pic-bulk-message" role="status" aria-live="polite"></p>

        <div id="rapls-pic-bulk-results" style="display: none;">
            <h3><?php esc_html_e('Scan Results', 'rapls-pdf-image-creator'); ?></h3>
            <table class="widefat rapls-pic-stats-table">
                <tbody>
                    <tr>
                        <td><?php esc_html_e('PDFs found:', 'rapls-pdf-image-creator'); ?></td>
                        <td id="rapls-pic-bulk-total">0</td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('To generate:', 'rapls-pdf-image-creator'); ?></td>
                        <td id="rapls-pic-bulk-todo">0</td>
                    </tr>
                    <tr id="rapls-pic-bulk-rows-row" style="display:none;">
                        <td><?php esc_html_e('PDF attachments in the database:', 'rapls-pdf-image-creator'); ?></td>
                        <td id="rapls-pic-bulk-rows">0</td>
                    </tr>
                    <tr id="rapls-pic-bulk-note-row" style="display:none;">
                        <td colspan="2" id="rapls-pic-bulk-note" role="status"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="rapls-pic-bulk-progress" style="display: none;">
            <h3 id="rapls-pic-progress-heading"><?php esc_html_e('Progress', 'rapls-pdf-image-creator'); ?></h3>

            <div class="rapls-pic-progress-bar" id="rapls-pic-progress" role="progressbar" aria-labelledby="rapls-pic-progress-heading" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div class="rapls-pic-progress-bar-inner" id="rapls-pic-progress-bar" style="width: 0%"></div>
            </div>

            <p id="rapls-pic-bulk-status" role="status" aria-live="polite"><?php esc_html_e('Ready', 'rapls-pdf-image-creator'); ?></p>

            <table class="widefat rapls-pic-stats-table">
                <tbody>
                    <tr>
                        <td><?php esc_html_e('Generated:', 'rapls-pdf-image-creator'); ?></td>
                        <td id="rapls-pic-stat-generated">0</td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Failed:', 'rapls-pdf-image-creator'); ?></td>
                        <td id="rapls-pic-stat-failed">0</td>
                    </tr>
                </tbody>
            </table>

            <div id="rapls-pic-bulk-log" class="rapls-pic-log-container" style="display: none;">
                <h4><?php esc_html_e('Log', 'rapls-pdf-image-creator'); ?></h4>
                <div class="rapls-pic-log-content" role="log" aria-live="polite"></div>
            </div>
        </div>
    </div>

    <!-- Status Tab -->
    <div id="tab-status" class="rapls-pic-tab-content" aria-labelledby="rapls-pic-tab-link-status">
        <h2><?php esc_html_e('Server Status', 'rapls-pdf-image-creator'); ?></h2>

        <p class="description">
            <?php esc_html_e('This plugin requires ImageMagick (Imagick PHP extension) with PDF support to generate thumbnails.', 'rapls-pdf-image-creator'); ?>
        </p>

        <?php if (!empty($_GET['rapls_pic_rechecked'])) : ?>
        <div class="notice notice-success inline">
            <p><?php esc_html_e('The server was checked again just now.', 'rapls-pdf-image-creator'); ?></p>
        </div>
        <?php endif; ?>

        <p>
            <a href="<?php echo esc_url($recheck_url); ?>" class="button">
                <?php esc_html_e('Check this server again', 'rapls-pdf-image-creator'); ?>
            </a>
            <span class="description">
                <?php esc_html_e('Results below are measured, then remembered: most for twelve hours, some for up to a week. Use this after your host changes something.', 'rapls-pdf-image-creator'); ?>
            </span>
        </p>

        <?php
        $rapls_pic_imagick_engine = $capabilities['engines']['imagick'] ?? null;
        $rapls_pic_is_available = $rapls_pic_imagick_engine && $rapls_pic_imagick_engine['available'];
        ?>

        <?php
        // Say which of the two server problems this is. "Not installed" and
        // "installed but forbidden to read PDFs" need different requests to
        // the hosting provider, and the site owner cannot tell them apart.
        $rapls_pic_availability = $capabilities['availability'] ?? null;
        ?>

        <?php if (!$rapls_pic_is_available && $rapls_pic_availability) : ?>
        <div class="notice notice-error inline">
            <p><strong><?php echo esc_html($rapls_pic_availability['label']); ?></strong></p>
            <p><?php echo esc_html($rapls_pic_availability['summary']); ?></p>
            <?php if ('' !== $rapls_pic_availability['action']) : ?>
            <p>
                <strong><?php esc_html_e('What to do:', 'rapls-pdf-image-creator'); ?></strong>
                <?php echo esc_html($rapls_pic_availability['action']); ?>
            </p>
            <?php endif; ?>
            <?php if ('' !== $rapls_pic_availability['detail']) : ?>
            <p><code><?php echo esc_html($rapls_pic_availability['detail']); ?></code></p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url(admin_url('site-health.php')); ?>">
                    <?php esc_html_e('See Site Health for the full report', 'rapls-pdf-image-creator'); ?>
                </a>
            </p>
        </div>
        <?php \Rapls\PDFImageCreator\Upsell::renderStatusOptions('' !== $rapls_pic_availability['action']); ?>
        <?php endif; ?>

        <?php
        // The server can be perfectly capable and a particular file still fail.
        // Before 1.4.0 that produced a PDF with no thumbnail and no explanation
        // anywhere; the reason was thrown away as soon as generate() returned.
        $rapls_pic_failure = $last_failure ?? null;
        $rapls_pic_failure_text = $rapls_pic_failure
            ? \Rapls\PDFImageCreator\FailureCode::describe($rapls_pic_failure['code'])
            : ['label' => '', 'action' => ''];
        ?>

        <?php if ($rapls_pic_failure && '' !== $rapls_pic_failure_text['label']) : ?>
        <div class="notice notice-warning inline">
            <p><strong><?php echo esc_html($rapls_pic_failure_text['label']); ?></strong></p>
            <?php if ('' !== $rapls_pic_failure_text['action']) : ?>
            <p>
                <strong><?php esc_html_e('What to do:', 'rapls-pdf-image-creator'); ?></strong>
                <?php echo esc_html($rapls_pic_failure_text['action']); ?>
            </p>
            <?php endif; ?>
            <p>
                <?php
                printf(
                    /* translators: 1: PDF title, 2: how long ago, e.g. "3 hours" */
                    esc_html__('Last happened with the PDF "%1$s", %2$s ago.', 'rapls-pdf-image-creator'),
                    esc_html(get_the_title($rapls_pic_failure['pdf_id']) ?: '#' . $rapls_pic_failure['pdf_id']),
                    esc_html(human_time_diff($rapls_pic_failure['time']))
                );
                ?>
            </p>
            <?php if ('' !== $rapls_pic_failure['message']) : ?>
            <p><code><?php echo esc_html($rapls_pic_failure['message']); ?></code></p>
            <?php endif; ?>
            <p class="description">
                <?php esc_html_e('This clears itself the next time a thumbnail is generated successfully.', 'rapls-pdf-image-creator'); ?>
            </p>
        </div>
        <?php endif; ?>

        <table class="widefat rapls-pic-capabilities-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Requirement', 'rapls-pdf-image-creator'); ?></th>
                    <th><?php esc_html_e('Status', 'rapls-pdf-image-creator'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rapls_pic_imagick_engine) : ?>
                    <?php foreach ($rapls_pic_imagick_engine['requirements'] as $rapls_pic_req) : ?>
                    <tr>
                        <td><?php echo esc_html($rapls_pic_req['name']); ?></td>
                        <td class="<?php echo $rapls_pic_req['status'] ? 'rapls-pic-status-ok' : 'rapls-pic-status-error'; ?>">
                            <?php echo $rapls_pic_req['status'] ? '✓ ' : '✗ '; ?>
                            <?php echo esc_html($rapls_pic_req['message']); ?>
                            <?php if (!empty($rapls_pic_req['detail'])) : ?>
                                <span class="rapls-pic-status-detail"><?php echo esc_html($rapls_pic_req['detail']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="2"><?php esc_html_e('Unable to check requirements.', 'rapls-pdf-image-creator'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if (\Rapls\PDFImageCreator\Admin::showsCmykWarning($rapls_pic_imagick_engine['requirements']['cmyk_pdf'] ?? null)) : ?>
        <div class="notice notice-warning inline">
            <p>
                <strong><?php esc_html_e('CMYK PDFs may generate a blank white thumbnail on this server.', 'rapls-pdf-image-creator'); ?></strong>
            </p>
            <p>
                <?php esc_html_e('Some ImageMagick 6 builds render CMYK PDFs through Ghostscript\'s bmpsep8 device, which produces a separation BMP that ImageMagick cannot read back. ImageMagick 7 does not have this problem.', 'rapls-pdf-image-creator'); ?>
            </p>
            <p>
                <?php esc_html_e('Ask your hosting provider for either of the following:', 'rapls-pdf-image-creator'); ?>
            </p>
            <ul class="rapls-pic-notice-list">
                <li><?php esc_html_e('Upgrade ImageMagick to version 7.', 'rapls-pdf-image-creator'); ?></li>
                <li>
                    <?php
                    printf(
                        /* translators: 1: delegates.xml, 2: ps:cmyk, 3: bmpsep8, 4: pamcmyk32 */
                        esc_html__('In %1$s, change the %2$s delegate from %3$s to %4$s.', 'rapls-pdf-image-creator'),
                        '<code>delegates.xml</code>',
                        '<code>ps:cmyk</code>',
                        '<code>bmpsep8</code>',
                        '<code>pamcmyk32</code>'
                    );
                    echo ' ';
                    printf(
                        /* translators: 1: gslib, 2: convert -list configure */
                        esc_html__('This has no effect where ImageMagick calls Ghostscript as a library (%1$s in the output of %2$s); then only the upgrade helps.', 'rapls-pdf-image-creator'),
                        '<code>gslib</code>',
                        '<code>convert -list configure</code>'
                    );
                    ?>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <?php
        $rapls_pic_color = $rapls_pic_imagick_engine['requirements']['color_management'] ?? null;
        if ($rapls_pic_color && !$rapls_pic_color['status']) :
            ?>
        <div class="notice notice-warning inline">
            <p>
                <?php esc_html_e('CMYK PDFs may render more vivid than the original. Install a CMYK ICC profile on the server, or point the rapls_pdf_image_creator_icc_paths filter at one, to restore accurate colors.', 'rapls-pdf-image-creator'); ?>
            </p>
        </div>
        <?php endif; ?>

        <h2><?php esc_html_e('Statistics', 'rapls-pdf-image-creator'); ?></h2>

        <noscript>
            <p class="description"><?php esc_html_e('Bulk Generate and the statistics need JavaScript. Without it, use the "Generate Thumbnail" link under each PDF in the Media Library\'s list view.', 'rapls-pdf-image-creator'); ?></p>
        </noscript>
        <p class="rapls-pic-needs-js">
            <button type="button" id="rapls-pic-refresh-stats" class="button button-secondary">
                <?php esc_html_e('Refresh Statistics', 'rapls-pdf-image-creator'); ?>
            </button>
            <span id="rapls-pic-stats-error" class="rapls-pic-stats-error" role="status"></span>
        </p>

        <table class="widefat rapls-pic-stats-table" id="rapls-pic-stats-table">
            <tbody>
                <tr>
                    <td><?php esc_html_e('Total PDFs:', 'rapls-pdf-image-creator'); ?></td>
                    <td id="rapls-pic-stats-total">-</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('With thumbnail:', 'rapls-pdf-image-creator'); ?></td>
                    <td id="rapls-pic-stats-with">-</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Without thumbnail:', 'rapls-pdf-image-creator'); ?></td>
                    <td id="rapls-pic-stats-without">-</td>
                </tr>
            </tbody>
        </table>

        <?php
        if ($rapls_pic_is_available) {
            \Rapls\PDFImageCreator\Upsell::renderStatusNote();
        }
        ?>

        <?php if (\Rapls\PDFImageCreator\Admin::showsSupportBox($capabilities)) : ?>
        <!-- Support -->
        <div class="rapls-pic-support-section">
            <h3><?php esc_html_e('Support This Plugin', 'rapls-pdf-image-creator'); ?></h3>
            <p><?php esc_html_e('If you find this plugin useful, please consider supporting its development.', 'rapls-pdf-image-creator'); ?></p>
            <div class="rapls-pic-support-buttons">
                <a href="https://buymeacoffee.com/rapls" target="_blank" rel="noopener noreferrer" class="rapls-pic-bmc-button">
                    <span class="rapls-pic-bmc-icon">☕</span>
                    <?php esc_html_e('Buy me a coffee', 'rapls-pdf-image-creator'); ?>
                    <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'rapls-pdf-image-creator'); ?></span>
                </a>
                <a href="https://wordpress.org/support/plugin/rapls-pdf-image-creator/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="rapls-pic-review-button">
                    <span class="rapls-pic-review-icon">★</span>
                    <?php esc_html_e('Leave a review', 'rapls-pdf-image-creator'); ?>
                    <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'rapls-pdf-image-creator'); ?></span>
                </a>
            </div>
            <p class="rapls-pic-review-note"><?php esc_html_e('Your reviews help other users discover this plugin. Thank you!', 'rapls-pdf-image-creator'); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
