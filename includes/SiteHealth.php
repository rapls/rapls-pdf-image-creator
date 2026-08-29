<?php
/**
 * Site Health integration
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * Reports the plugin's server requirements to Tools > Site Health
 *
 * This is where a site owner looks first when something does not work, and
 * where a support request usually gets copied from. Everything the Status tab
 * knows is repeated here so that neither the owner nor whoever helps them has
 * to find the plugin's own settings page.
 */
final class SiteHealth
{
    /**
     * Settings manager
     */
    private Settings $settings;

    /**
     * Thumbnail generator
     */
    private Generator $generator;

    /**
     * Constructor
     *
     * @param Settings  $settings  Settings manager.
     * @param Generator $generator Thumbnail generator.
     */
    public function __construct(Settings $settings, Generator $generator)
    {
        $this->settings = $settings;
        $this->generator = $generator;
    }

    /**
     * Register hooks
     */
    public function init(): void
    {
        add_filter('site_status_tests', [$this, 'registerTest']);
        add_filter('debug_information', [$this, 'registerDebugInformation']);
    }

    /**
     * Add the PDF rendering test
     *
     * @param array<string, mixed> $tests Registered tests.
     * @return array<string, mixed>
     */
    public function registerTest(array $tests): array
    {
        $tests['direct']['rapls_pic_pdf_rendering'] = [
            'label' => __('PDF thumbnail generation', 'rapls-pdf-image-creator'),
            'test' => [$this, 'runTest'],
        ];

        return $tests;
    }

    /**
     * Run the PDF rendering test
     *
     * @return array<string, mixed>
     */
    public function runTest(): array
    {
        $status = $this->generator->getAvailabilityStatus();

        $result = [
            'label' => __('PDF thumbnails can be generated', 'rapls-pdf-image-creator'),
            'status' => 'good',
            'badge' => [
                'label' => __('Media', 'rapls-pdf-image-creator'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html__('Rapls PDF Image Creator can render PDF files, so thumbnails are generated when a PDF is uploaded.', 'rapls-pdf-image-creator') . '</p>',
            'actions' => '',
            'test' => 'rapls_pic_pdf_rendering',
        ];

        if ('ok' === $status['code']) {
            return $result;
        }

        // Without a working engine the plugin cannot do the one thing it is
        // installed for, so this is critical rather than a recommendation.
        $result['status'] = 'critical';
        $result['label'] = __('PDF thumbnails cannot be generated', 'rapls-pdf-image-creator');

        $description = '<p>' . esc_html($status['summary']) . '</p>';

        if ('' !== $status['action']) {
            $description .= '<p><strong>' . esc_html__('What to do:', 'rapls-pdf-image-creator') . '</strong> '
                . esc_html($status['action']) . '</p>';
        }

        if ('' !== $status['detail']) {
            $description .= '<p><code>' . esc_html($status['detail']) . '</code></p>';
        }

        $result['description'] = $description;

        $result['actions'] = sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url(admin_url('options-general.php?page=' . Admin::PAGE_SLUG . '#tab-status')),
            esc_html__('Open the plugin status page', 'rapls-pdf-image-creator')
        );

        return $result;
    }

    /**
     * Add a debug information section
     *
     * @param array<string, mixed> $info Debug sections.
     * @return array<string, mixed>
     */
    public function registerDebugInformation(array $info): array
    {
        $status = $this->generator->getAvailabilityStatus();
        $config = $this->settings->get();

        $fields = [
            'version' => [
                'label' => __('Plugin version', 'rapls-pdf-image-creator'),
                'value' => RAPLS_PIC_VERSION,
            ],
            'availability' => [
                'label' => __('PDF rendering', 'rapls-pdf-image-creator'),
                'value' => $status['label'],
                'debug' => $status['code'],
            ],
        ];

        if ('' !== $status['detail']) {
            $fields['availability_detail'] = [
                'label' => __('PDF rendering detail', 'rapls-pdf-image-creator'),
                'value' => $status['detail'],
            ];
        }

        // Report requirements even when nothing is available -- that is
        // precisely when someone needs to read them.
        $capabilities = $this->generator->checkCapabilities();
        foreach ($capabilities['engines'] as $engineName => $engineInfo) {
            foreach ($engineInfo['requirements'] as $key => $requirement) {
                if ('pdf_support' === $key) {
                    continue;
                }
                $fields[$engineName . '_' . $key] = [
                    'label' => $requirement['name'],
                    'value' => $requirement['message'],
                ];
            }
        }

        $fields['resolution'] = [
            'label' => __('Rendering Resolution', 'rapls-pdf-image-creator'),
            /* translators: %d: resolution in dots per inch */
            'value' => sprintf(__('%d DPI', 'rapls-pdf-image-creator'), (int) $this->settings->getResolution()),
            'debug' => (int) $this->settings->getResolution(),
        ];

        $fields['max_dimensions'] = [
            'label' => __('Maximum dimensions', 'rapls-pdf-image-creator'),
            'value' => sprintf('%d x %d', (int) $config['max_width'], (int) $config['max_height']),
        ];

        $fields['format'] = [
            'label' => __('Output format', 'rapls-pdf-image-creator'),
            'value' => (string) $config['format'],
        ];

        $fields['auto_generate'] = [
            'label' => __('Generate on upload', 'rapls-pdf-image-creator'),
            'value' => !empty($config['auto_generate']) ? __('Enabled', 'rapls-pdf-image-creator') : __('Disabled', 'rapls-pdf-image-creator'),
            'debug' => !empty($config['auto_generate']),
        ];

        $info['rapls-pdf-image-creator'] = [
            'label' => __('Rapls PDF Image Creator', 'rapls-pdf-image-creator'),
            'description' => __('Server requirements and settings for PDF thumbnail generation.', 'rapls-pdf-image-creator'),
            'fields' => $fields,
        ];

        return $info;
    }
}
