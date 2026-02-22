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
        <nav class="nav-tab-wrapper">
            <a href="#tab-settings" class="nav-tab nav-tab-active" data-tab="settings">
                <?php esc_html_e('Settings', 'rapls-pdf-image-creator'); ?>
            </a>
            <a href="#tab-bulk" class="nav-tab" data-tab="bulk">
                <?php esc_html_e('Bulk Generate', 'rapls-pdf-image-creator'); ?>
            </a>
            <a href="#tab-status" class="nav-tab" data-tab="status">
                <?php esc_html_e('Status', 'rapls-pdf-image-creator'); ?>
            </a>
        </nav>
    </div>

    <!-- Settings Tab -->
    <div id="tab-settings" class="rapls-pic-tab-content active">
        <form method="post" action="options.php">
            <?php settings_fields('rapls_pic_settings_group'); ?>

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
                        <label for="rapls_pic_quality"><?php esc_html_e('Quality', 'rapls-pdf-image-creator'); ?></label>
                    </th>
                    <td>
                        <input type="range" id="rapls_pic_quality" name="rapls_pic_settings[quality]"
                            value="<?php echo esc_attr((string) $config['quality']); ?>"
                            min="10" max="100" class="rapls-pic-range">
                        <output><?php echo esc_html((string) $config['quality']); ?></output>
                        <p class="description">
                            <?php esc_html_e('Image quality for JPEG/WebP output. (10-100)', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Output Format', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <fieldset>
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
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Background Color', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <fieldset>
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
                                <?php esc_html_e('Transparent (PNG only)', 'rapls-pdf-image-creator'); ?>
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
                            <?php esc_html_e('Shows the generated thumbnail image as the PDF icon in the Media Library.', 'rapls-pdf-image-creator'); ?>
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
                            <?php esc_html_e('When unchecked and a PDF is deleted, the generated image will NOT be deleted together.', 'rapls-pdf-image-creator'); ?>
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
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Insert Settings', 'rapls-pdf-image-creator'); ?></h2>

            <p class="description">
                <?php esc_html_e('Configure how PDF thumbnails are inserted into content.', 'rapls-pdf-image-creator'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Default Insert Type', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <fieldset>
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
                        ?>
                        <select id="rapls_pic_insert_size" name="rapls_pic_settings[insert_size]">
                            <?php foreach ($rapls_pic_image_sizes as $rapls_pic_size) : ?>
                            <option value="<?php echo esc_attr($rapls_pic_size); ?>"
                                <?php selected($config['insert_size'] ?? 'medium', $rapls_pic_size); ?>>
                                <?php echo esc_html(ucfirst($rapls_pic_size)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Image size to use when inserting PDF thumbnails.', 'rapls-pdf-image-creator'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Link To', 'rapls-pdf-image-creator'); ?></th>
                    <td>
                        <fieldset>
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
                            <?php esc_html_e('Example for Google Doc Embedder:', 'rapls-pdf-image-creator'); ?><br>
                            <code>[gview file="%PDF_URL%"]</code>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>

    <!-- Bulk Generate Tab -->
    <div id="tab-bulk" class="rapls-pic-tab-content">
        <h2><?php esc_html_e('Bulk Generate Thumbnails', 'rapls-pdf-image-creator'); ?></h2>

        <p class="description">
            <?php esc_html_e('Generate thumbnails for existing PDF files in your Media Library.', 'rapls-pdf-image-creator'); ?>
        </p>

        <table class="form-table">
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

        <p>
            <button type="button" id="rapls-pic-bulk-scan" class="button button-secondary">
                <?php esc_html_e('Scan for PDFs', 'rapls-pdf-image-creator'); ?>
            </button>
            <button type="button" id="rapls-pic-bulk-start" class="button button-primary" disabled>
                <?php esc_html_e('Start Generation', 'rapls-pdf-image-creator'); ?>
            </button>
            <button type="button" id="rapls-pic-bulk-stop" class="button button-secondary" disabled>
                <?php esc_html_e('Stop', 'rapls-pdf-image-creator'); ?>
            </button>
        </p>

        <div id="rapls-pic-bulk-results" style="display: none;">
            <h3><?php esc_html_e('Scan Results', 'rapls-pdf-image-creator'); ?></h3>
            <table class="widefat rapls-pic-stats-table">
                <tbody>
                    <tr>
                        <td><?php esc_html_e('PDFs found:', 'rapls-pdf-image-creator'); ?></td>
                        <td id="rapls-pic-bulk-total">0</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="rapls-pic-bulk-progress" style="display: none;">
            <h3><?php esc_html_e('Progress', 'rapls-pdf-image-creator'); ?></h3>

            <div class="rapls-pic-progress-bar">
                <div class="rapls-pic-progress-bar-inner" id="rapls-pic-progress-bar" style="width: 0%"></div>
            </div>

            <p id="rapls-pic-bulk-status"><?php esc_html_e('Ready', 'rapls-pdf-image-creator'); ?></p>

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
                <div class="rapls-pic-log-content"></div>
            </div>
        </div>
    </div>

    <!-- Status Tab -->
    <div id="tab-status" class="rapls-pic-tab-content">
        <h2><?php esc_html_e('Server Status', 'rapls-pdf-image-creator'); ?></h2>

        <p class="description">
            <?php esc_html_e('This plugin requires ImageMagick (Imagick PHP extension) with PDF support to generate thumbnails.', 'rapls-pdf-image-creator'); ?>
        </p>

        <?php
        $rapls_pic_imagick_engine = $capabilities['engines']['imagick'] ?? null;
        $rapls_pic_is_available = $rapls_pic_imagick_engine && $rapls_pic_imagick_engine['available'];
        ?>

        <?php if (!$rapls_pic_is_available) : ?>
        <div class="notice notice-error inline">
            <p>
                <strong><?php esc_html_e('ImageMagick is not available.', 'rapls-pdf-image-creator'); ?></strong>
                <?php esc_html_e('Please contact your hosting provider to enable the Imagick PHP extension with PDF support.', 'rapls-pdf-image-creator'); ?>
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

        <h2><?php esc_html_e('Statistics', 'rapls-pdf-image-creator'); ?></h2>

        <p>
            <button type="button" id="rapls-pic-refresh-stats" class="button button-secondary">
                <?php esc_html_e('Refresh Statistics', 'rapls-pdf-image-creator'); ?>
            </button>
        </p>

        <table class="widefat pic-stats-table" id="rapls-pic-stats-table">
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

        <!-- Support -->
        <div class="rapls-pic-support-section">
            <h3><?php esc_html_e('Support This Plugin', 'rapls-pdf-image-creator'); ?></h3>
            <p><?php esc_html_e('If you find this plugin useful, please consider supporting its development.', 'rapls-pdf-image-creator'); ?></p>
            <div class="rapls-pic-support-buttons">
                <a href="https://buymeacoffee.com/rapls" target="_blank" rel="noopener noreferrer" class="rapls-pic-bmc-button">
                    <span class="rapls-pic-bmc-icon">☕</span>
                    <?php esc_html_e('Buy me a coffee', 'rapls-pdf-image-creator'); ?>
                </a>
                <a href="https://wordpress.org/support/plugin/rapls-pdf-image-creator/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="rapls-pic-review-button">
                    <span class="rapls-pic-review-icon">★</span>
                    <?php esc_html_e('Leave a review', 'rapls-pdf-image-creator'); ?>
                </a>
            </div>
            <p class="rapls-pic-review-note"><?php esc_html_e('Your reviews help other users discover this plugin. Thank you!', 'rapls-pdf-image-creator'); ?></p>
        </div>
    </div>
</div>
