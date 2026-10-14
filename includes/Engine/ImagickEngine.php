<?php
/**
 * Imagick Engine
 *
 * @package PDFImageCreator\Engine
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator\Engine;

/**
 * PDF to image conversion using PHP Imagick extension
 */
final class ImagickEngine implements EngineInterface
{
    /**
     * Option holding what the last conversion actually did, for the Status tab.
     */
    public const DIAGNOSTICS_OPTION = 'rapls_pic_color_diagnostics';

    /**
     * Colour profile resolver
     */
    private ColorProfile $colorProfile;

    /**
     * Constructor
     *
     * @param ColorProfile|null $colorProfile Profile resolver, for testing.
     */
    public function __construct(?ColorProfile $colorProfile = null)
    {
        $this->colorProfile = $colorProfile ?? new ColorProfile();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'imagick';
    }

    /**
     * {@inheritdoc}
     */
    public function getDisplayName(): string
    {
        return 'Imagick (ImageMagick)';
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        // Deliberately the same answer the Status tab and Site Health give.
        // This used to ask queryFormats() on its own and say yes on servers
        // whose policy.xml forbids PDFs, which made every surface claim the
        // plugin worked right up until the first upload silently failed.
        return 'ok' === $this->getAvailabilityStatus()['code'];
    }

    /**
     * Transient caching the policy.xml lookup
     */
    private const POLICY_TRANSIENT = 'rapls_pic_pdf_policy';

    /**
     * Transient caching the actual PDF read probe
     */
    private const READ_PROBE_TRANSIENT = 'rapls_pic_pdf_read';

    /**
     * Transient caching the CMYK render probe
     */
    private const CMYK_PROBE_TRANSIENT = 'rapls_pic_cmyk_render';

    /**
     * Transient caching the page-selection probe
     */
    private const PAGE_PROBE_TRANSIENT = 'rapls_pic_page_select';

    /**
     * Transient caching the delegates.xml lookup
     */
    private const DELEGATE_TRANSIENT = 'rapls_pic_cmyk_delegate';

    /**
     * Every transient this class caches a measurement in
     *
     * The Status tab's re-check button deletes these, and so does uninstall.
     * Keeping the list here means neither has to guess, and a probe added
     * without a matching entry shows up as a failing test rather than as an
     * answer nobody can clear.
     *
     * @return array<int, string>
     */
    public static function probeTransients(): array
    {
        return [
            self::POLICY_TRANSIENT,
            self::READ_PROBE_TRANSIENT,
            self::CMYK_PROBE_TRANSIENT,
            self::PAGE_PROBE_TRANSIENT,
            self::DELEGATE_TRANSIENT,
            self::PAGE_SUFFIX_TRANSIENT,
            self::GS_OPTIONS_TRANSIENT,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Nothing here may throw: this runs on every admin page load through the
     * notice, and on the Site Health screen.
     */
    public function getAvailabilityStatus(): array
    {
        if (!extension_loaded('imagick') || !class_exists('\Imagick')) {
            return $this->statusFor('no_extension');
        }

        try {
            $formats = \Imagick::queryFormats('PDF');
        } catch (\Exception $e) {
            return $this->statusFor('error', $e->getMessage());
        }

        if (empty($formats)) {
            // No PDF coder compiled in at all.
            return $this->statusFor('pdf_unsupported');
        }

        // queryFormats() lists the coders that were built in. It does NOT
        // apply the security policy -- verified on ImageMagick 7.1.1, where a
        // policy.xml denying the PDF coder still leaves PDF in queryFormats
        // while readImage throws NotAuthorized. Asking it is therefore not an
        // answer; the only way to know is to hand ImageMagick a PDF.
        return $this->statusFromProbe($this->probePdfRead());
    }

    /**
     * What a read check's answer means for this server
     *
     * Apart from getAvailabilityStatus() so that it can be tested where the
     * Imagick extension is not loaded (review of 1.4.32).
     *
     * @param array{ok: bool, error: string} $probe probePdfRead()'s answer.
     */
    private function statusFromProbe(array $probe): array
    {
        if ($probe['ok']) {
            return $this->statusFor('ok');
        }

        // The same reading probePdfRead() caches by: read differently here,
        // a Ghostscript exit of 1 was cached for ten minutes as a passing
        // failure and shown for those ten minutes as "no PDF support"
        // (review after 1.4.32).
        $code = \Rapls\PDFImageCreator\FailureCode::fromProbeMessage($probe['error']);

        if (\Rapls\PDFImageCreator\FailureCode::PDF_BLOCKED_BY_POLICY === $code) {
            $policyFile = $this->findPdfPolicyBlock();

            return $this->statusFor(
                'pdf_blocked_by_policy',
                null === $policyFile
                    ? $probe['error']
                    /* translators: %s: absolute path to the policy.xml file */
                    : sprintf(__('Policy file: %s', 'rapls-pdf-image-creator'), $policyFile)
            );
        }

        if (\Rapls\PDFImageCreator\FailureCode::PDF_UNSUPPORTED === $code) {
            return $this->statusFor('pdf_unsupported', $probe['error']);
        }

        return $this->statusFor('pdf_read_failed', $probe['error']);
    }

    /**
     * Ask ImageMagick to actually read a PDF
     *
     * The only reliable test. Renders a blank 1-inch page held in memory --
     * no temporary file of ours, no process started by us. ImageMagick calls
     * its own PDF delegate internally, exactly as it does for a real upload.
     *
     * Cached: this is the one expensive check, and the admin notice runs on
     * every page. The key carries the ImageMagick version so that a server
     * upgrade re-tests instead of serving a stale answer.
     *
     * @return array{ok: bool, error: string}
     */
    private function probePdfRead(): array
    {
        // The version lives inside the value rather than in the key, so that
        // uninstall.php has one fixed name to delete.
        $version = $this->getVersionString();

        $cached = get_transient(self::READ_PROBE_TRANSIENT);
        if (is_array($cached) && isset($cached['ok'], $cached['error'], $cached['version'])
            && $cached['version'] === $version) {
            return ['ok' => (bool) $cached['ok'], 'error' => (string) $cached['error']];
        }

        $result = ['ok' => false, 'error' => ''];

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(72, 72);
            $imagick->readImageBlob(self::minimalPdf(), 'rapls-pic-probe.pdf');
            $imagick->clear();
            $result['ok'] = true;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        // Twelve hours for an answer about the server -- it reads PDFs, or a
        // policy or a missing coder stops it. Ten minutes for anything else:
        // a full /tmp or a resource limit at the moment of the one check
        // stopped every thumbnail for twelve hours (review after 1.4.31).
        // Ghostscript found and run, but exiting 1 or killed by a signal, is
        // this moment's, not the server's -- taken as "no PDF support", it
        // too stopped every thumbnail for twelve hours (review of 1.4.32).
        // Not found (127) still lasts. See FailureCode::fromProbeMessage().
        $code = $result['ok'] ? '' : \Rapls\PDFImageCreator\FailureCode::fromProbeMessage($result['error']);
        $lasting = $result['ok'] || in_array($code, [\Rapls\PDFImageCreator\FailureCode::PDF_BLOCKED_BY_POLICY, \Rapls\PDFImageCreator\FailureCode::PDF_UNSUPPORTED], true);

        set_transient(
            self::READ_PROBE_TRANSIENT,
            $result + ['version' => $version],
            $lasting ? 12 * HOUR_IN_SECONDS : self::PASSING_FAILURE_SECONDS
        );

        return $result;
    }

    /**
     * The ImageMagick version string, or '' when it cannot be read
     */
    private function getVersionString(): string
    {
        try {
            $version = \Imagick::getVersion();
            return (string) ($version['versionString'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * A minimal, structurally valid one-page PDF
     *
     * Built rather than bundled so there is no file to ship, and no question
     * about the licence of a sample document. One empty 72x72pt page.
     */
    private static function minimalPdf(): string
    {
        return self::buildPdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 72 72] /Resources << >> >>',
        ]);
    }

    /**
     * Assemble a PDF from a list of object bodies, with a correct xref table
     *
     * @param array<int, string> $objects Object bodies, in order from 1.
     */
    private static function buildPdf(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $startxref = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= 'startxref' . "\n" . $startxref . "\n" . '%%EOF' . "\n";

        return $pdf;
    }

    /**
     * Build the status array for one code
     *
     * Every branch of getAvailabilityStatus() comes through here, so the
     * shape cannot drift between them and the wording stays in one place.
     *
     * @param string $code   One of ok, no_extension, pdf_blocked_by_policy,
     *                       pdf_unsupported, pdf_read_failed, error.
     *                       pdf_read_failed is reported as error, with
     *                       its own wording.
     * @param string $detail Extra machine-ish detail, already translated.
     * @return array{code: string, label: string, summary: string, action: string, detail: string}
     */
    private function statusFor(string $code, string $detail = ''): array
    {
        switch ($code) {
            case 'ok':
                $label = __('Ready', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick is installed and PDF rendering is permitted.', 'rapls-pdf-image-creator');
                $action = '';
                break;

            case 'no_extension':
                $label = __('Imagick extension not installed', 'rapls-pdf-image-creator');
                $summary = __('The Imagick PHP extension is not loaded, so no PDF can be rendered.', 'rapls-pdf-image-creator');
                $action = __('Ask your hosting provider to install and enable the Imagick PHP extension (the ImageMagick binding for PHP).', 'rapls-pdf-image-creator');
                break;

            case 'pdf_blocked_by_policy':
                $label = __('PDF blocked by ImageMagick policy', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick is installed, but its security policy forbids reading PDF files. This is a server setting, not a missing component.', 'rapls-pdf-image-creator');
                $action = __('Ask your hosting provider to allow the PDF coder in policy.xml. The rule currently denies it, and it needs read rights.', 'rapls-pdf-image-creator');
                break;

            case 'pdf_unsupported':
                $label = __('PDF support missing', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick is installed, but it reports no PDF support. Usually the PDF delegate is absent from the build; a security policy elsewhere on the server can also cause this.', 'rapls-pdf-image-creator');
                $action = __('Ask your hosting provider to enable PDF support in ImageMagick, and to confirm that policy.xml does not deny the PDF coder.', 'rapls-pdf-image-creator');
                break;

            case 'pdf_read_failed':
                // The read check failed for a reason of the moment, not a
                // policy or missing support: "could not be asked which
                // formats" was not what happened (review of 1.4.32).
                $code = 'error';
                $label = __('Unable to check', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick could not read the small test PDF this plugin uses to check the server. It is tried again in ten minutes.', 'rapls-pdf-image-creator');
                $action = __('If this stays, ask your hosting provider to check ImageMagick and Ghostscript, with the message below.', 'rapls-pdf-image-creator');
                break;

            default:
                $code = 'error';
                $label = __('Unable to check', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick could not be asked which formats it supports.', 'rapls-pdf-image-creator');
                $action = __('Ask your hosting provider to check the ImageMagick installation.', 'rapls-pdf-image-creator');
                break;
        }

        return [
            'code' => $code,
            'label' => $label,
            'summary' => $summary,
            'action' => $action,
            'detail' => $detail,
        ];
    }

    /**
     * Find a policy.xml that denies the PDF coder
     *
     * Read-only, and deliberately so: the plugin may not run a process, so
     * `identify -list policy` is not an option. Parsing the file ourselves is
     * the only way to separate "policy says no" from "delegate is missing",
     * and that distinction changes what the site owner has to ask for.
     *
     * @return string|null Path of the offending file, or null if none found.
     */
    private function findPdfPolicyBlock(): ?string
    {
        $cached = get_transient(self::POLICY_TRANSIENT);
        if (is_array($cached) && array_key_exists('file', $cached)) {
            return is_string($cached['file']) ? $cached['file'] : null;
        }

        $found = null;

        foreach ($this->getPolicyFileCandidates() as $file) {
            // @: under open_basedir, a folder outside it warns (review after 1.4.31).
            if (!@is_readable($file)) {
                continue;
            }

            $contents = @file_get_contents($file);
            if (false === $contents || '' === $contents) {
                continue;
            }

            if ($this->policyDeniesPdf($contents)) {
                $found = $file;
                break;
            }
        }

        set_transient(self::POLICY_TRANSIENT, ['file' => $found], DAY_IN_SECONDS);

        return $found;
    }

    /**
     * Candidate policy.xml paths, most authoritative first
     *
     * @return array<int, string>
     */
    private function getPolicyFileCandidates(): array
    {
        $files = [];
        foreach ($this->getConfigDirectories() as $dir) {
            $files[] = $dir . '/policy.xml';
        }

        /**
         * Filter the policy.xml paths searched
         *
         * Builds in unusual locations, and the test suite, need to point this
         * somewhere else.
         *
         * @param array<int, string> $files Candidate paths, most authoritative first.
         */
        $files = apply_filters('rapls_pdf_image_creator_policy_paths', array_values(array_unique($files)));

        return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
    }

    /**
     * Which Ghostscript device this build uses for CMYK PDFs
     *
     * ImageMagick picks the device from delegates.xml, and on ImageMagick 6
     * the historical choice for ps:cmyk is bmpsep8 -- a separation BMP that
     * ImageMagick's own reader cannot decode, which is where blank white
     * thumbnails come from. Newer builds and distribution patches use
     * pamcmyk32 instead and are fine.
     *
     * Reading the file is the only way to tell from PHP: Imagick exposes the
     * format list and the resource limits, but nothing about delegates, and
     * `identify -list delegate` is a process this plugin may not start.
     *
     * @return string|null Device name, or null when it could not be read.
     */
    private function getCmykDelegateDevice(): ?string
    {
        $cached = get_transient(self::DELEGATE_TRANSIENT);
        if (is_array($cached) && array_key_exists('device', $cached)) {
            return is_string($cached['device']) ? $cached['device'] : null;
        }

        $device = null;

        foreach ($this->getDelegateFileCandidates() as $file) {
            // @: under open_basedir, a folder outside it warns (review after 1.4.31).
            if (!@is_readable($file)) {
                continue;
            }

            $contents = @file_get_contents($file);
            if (false === $contents || '' === $contents) {
                continue;
            }

            $device = $this->cmykDeviceFromDelegates($contents);

            if (null !== $device) {
                break;
            }
        }

        set_transient(self::DELEGATE_TRANSIENT, ['device' => $device], DAY_IN_SECONDS);

        return $device;
    }

    /**
     * Pull the -sDEVICE out of the ps:cmyk delegate
     *
     * @param string $xml Contents of a delegates.xml.
     */
    private function cmykDeviceFromDelegates(string $xml): ?string
    {
        // Same lesson as policy.xml: a delegate inside <!-- --> is not in use.
        $xml = (string) preg_replace('/<!--.*?-->/s', '', $xml);

        if (!preg_match('/<delegate\b[^>]*decode\s*=\s*"ps:cmyk"[^>]*>/is', $xml, $tag)) {
            return null;
        }

        // The command attribute is XML-escaped, so the device may be wrapped
        // in &quot; rather than a literal quote.
        if (!preg_match('/-sDEVICE=(?:&quot;|"|\')?([A-Za-z0-9_]+)/i', $tag[0], $device)) {
            return null;
        }

        return strtolower($device[1]);
    }

    /**
     * Candidate delegates.xml paths, most authoritative first
     *
     * @return array<int, string>
     */
    private function getDelegateFileCandidates(): array
    {
        $files = [];
        foreach ($this->getConfigDirectories() as $dir) {
            $files[] = $dir . '/delegates.xml';
        }

        /**
         * Filter the delegates.xml paths searched
         *
         * @param array<int, string> $files Candidate paths, most authoritative first.
         */
        $files = apply_filters('rapls_pdf_image_creator_delegate_paths', array_values(array_unique($files)));

        return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
    }

    /**
     * Where ImageMagick keeps its configuration, most authoritative first
     *
     * policy.xml and delegates.xml live side by side, so both readers want the
     * same list.
     *
     * @return array<int, string>
     */
    private function getConfigDirectories(): array
    {
        $dirs = [];

        // Runtime overrides win over anything compiled in, and ImageMagick
        // honours them ahead of its own configure path. Verified on
        // ImageMagick 7.1.1: with MAGICK_CONFIGURE_PATH pointing at a
        // policy.xml that denies the PDF coder, reading a PDF throws
        // NotAuthorized while getConfigureOptions() still reports the build
        // directory -- so consulting only the latter finds no policy file and
        // the plugin blames a missing delegate instead.
        $env = getenv('MAGICK_CONFIGURE_PATH');
        if (is_string($env) && '' !== $env) {
            $dirs = array_merge($dirs, explode(':', $env));
        }

        $home = getenv('MAGICK_HOME');
        if (is_string($home) && '' !== $home) {
            $dirs[] = rtrim($home, '/') . '/etc/ImageMagick-7';
            $dirs[] = rtrim($home, '/') . '/etc/ImageMagick-6';
            $dirs[] = rtrim($home, '/') . '/config-Q16';
        }

        // ImageMagick tells us where it looks, when the build supports it.
        if (method_exists('\Imagick', 'getConfigureOptions')) {
            try {
                $options = \Imagick::getConfigureOptions('CONFIGURE_PATH');
                if (is_array($options)) {
                    foreach ($options as $value) {
                        if (is_string($value) && '' !== $value) {
                            $dirs = array_merge($dirs, explode(':', $value));
                        }
                    }
                }
            } catch (\Exception $e) {
                // Older builds throw rather than return an empty set. The
                // folders found above stay: emptied here, the MAGICK_HOME and
                // MAGICK_CONFIGURE_PATH ones went too, and the policy file's
                // path was not shown (review after 1.4.31).
            }
        }

        // The usual distribution locations, for builds that report nothing.
        $dirs = array_merge($dirs, [
            '/etc/ImageMagick-7',
            '/etc/ImageMagick-6',
            '/etc/ImageMagick',
            '/usr/local/etc/ImageMagick-7',
            '/usr/local/etc/ImageMagick-6',
            '/opt/homebrew/etc/ImageMagick-7',
        ]);

        $clean = [];
        foreach ($dirs as $dir) {
            $dir = rtrim(trim((string) $dir), '/');
            if ('' !== $dir) {
                $clean[] = $dir;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Does this policy.xml text deny reading PDFs?
     *
     * Matched with a regex rather than an XML parser: the file is small, the
     * shape is fixed, and a malformed policy.xml must not turn into a fatal.
     *
     * @param string $xml Raw file contents.
     */
    private function policyDeniesPdf(string $xml): bool
    {
        // Strip comments first. A rule inside <!-- --> is switched off, and
        // hosting providers unblock PDF by commenting the deny rule out far
        // more often than by deleting it -- the upstream policy.xml is mostly
        // examples inside comments to begin with. Counting those as active
        // reports an already-fixed server as still blocked.
        //
        // This was written for tools/probe-imagemagick.php in 1.3.1 and the
        // changelog said the reader was fixed. It was fixed in the tool only;
        // the copy that ships never had it.
        $xml = (string) preg_replace('/<!--.*?-->/s', '', $xml);

        if (!preg_match_all('/<policy\b[^>]*>/i', $xml, $matches)) {
            return false;
        }

        foreach ($matches[0] as $tag) {
            if (!preg_match('/\bdomain\s*=\s*"([^"]*)"/i', $tag, $domain)) {
                continue;
            }
            if ('coder' !== strtolower(trim($domain[1]))) {
                continue;
            }

            if (!preg_match('/\brights\s*=\s*"([^"]*)"/i', $tag, $rights)) {
                continue;
            }
            $granted = strtolower(trim($rights[1]));
            if (false !== strpos($granted, 'read')) {
                continue;
            }

            if (!preg_match('/\bpattern\s*=\s*"([^"]*)"/i', $tag, $pattern)) {
                continue;
            }

            if ($this->patternCoversPdf(trim($pattern[1]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a policy pattern cover the PDF coder?
     *
     * Patterns seen in the wild: PDF, PDF*, {PS,PS2,PS3,EPS,PDF,XPS}, *.
     *
     * @param string $pattern Raw pattern attribute.
     */
    private function patternCoversPdf(string $pattern): bool
    {
        if ('' === $pattern) {
            return false;
        }

        if ('*' === $pattern) {
            return true;
        }

        $items = [$pattern];
        if ('{' === $pattern[0] && '}' === substr($pattern, -1)) {
            $items = explode(',', substr($pattern, 1, -1));
        }

        foreach ($items as $item) {
            $item = strtoupper(trim($item));
            if ('PDF' === $item || 'PDF*' === $item) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getRequirements(): array
    {
        $requirements = [
            'extension' => [
                'name' => __('Imagick Extension', 'rapls-pdf-image-creator'),
                'status' => extension_loaded('imagick'),
                'message' => extension_loaded('imagick')
                    ? __('Installed', 'rapls-pdf-image-creator')
                    : __('Not installed', 'rapls-pdf-image-creator'),
            ],
        ];

        if (extension_loaded('imagick') && class_exists('\Imagick')) {
            try {
                $version = \Imagick::getVersion();
                $requirements['version'] = [
                    'name' => __('ImageMagick Version', 'rapls-pdf-image-creator'),
                    'status' => true,
                    'message' => $version['versionString'] ?? __('Unknown', 'rapls-pdf-image-creator'),
                ];

                // Report the reason, not just the bool: "policy forbids it"
                // and "the build has no PDF delegate" send the site owner to
                // their host with different requests.
                $availability = $this->getAvailabilityStatus();
                $requirements['pdf_support'] = [
                    'name' => __('PDF Support', 'rapls-pdf-image-creator'),
                    'status' => 'ok' === $availability['code'],
                    'message' => 'ok' === $availability['code']
                        ? __('Available', 'rapls-pdf-image-creator')
                        : $availability['label'],
                    // When it works, "Available" is the whole story; repeating
                    // the summary next to it is just noise in the table.
                    'detail' => 'ok' === $availability['code']
                        ? ''
                        : trim($availability['summary'] . ' ' . $availability['detail']),
                ];

                $pageSelection = $this->getPageSelectionStatus();
                if (null !== $pageSelection) {
                    $requirements['page_selection'] = $pageSelection;
                }

                $requirements['color_management'] = $this->getColorManagementStatus();

                $cmykPdf = $this->getCmykPdfStatus();
                if (null !== $cmykPdf) {
                    $requirements['cmyk_pdf'] = $cmykPdf;
                }

                $ghostscript = $this->getGhostscriptWorkaroundStatus();
                if (null !== $ghostscript) {
                    $requirements['ghostscript'] = $ghostscript;
                }
            } catch (\Exception $e) {
                $requirements['error'] = [
                    'name' => __('Error', 'rapls-pdf-image-creator'),
                    'status' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $requirements;
    }

    /**
     * {@inheritdoc}
     */
    public function convert(string $pdfPath, string $outputPath, array $options = []): ConversionResult
    {
        $startTime = microtime(true);

        // Default options
        $defaults = [
            'page' => 0,
            'max_width' => 1024,
            'max_height' => 1024,
            'quality' => 90,
            'format' => 'jpeg',
            'bgcolor' => 'white',
            'resolution' => 150,
        ];
        $options = array_merge($defaults, $options);

        // Whole numbers, whoever called: Imagick's parameters are ints, and a
        // string or a float from a caller or a filter was a TypeError
        // (review after 1.4.31).
        foreach (['page', 'max_width', 'max_height', 'quality', 'resolution'] as $number) {
            $options[$number] = is_numeric($options[$number]) ? (int) $options[$number] : $defaults[$number];
        }

        // The size limits run through filters too, and 0 or a negative made
        // the resize ImageMagick's "Invalid image geometry" -- no thumbnail,
        // and a message about ImageMagick (review after 1.4.33).
        foreach (['max_width', 'max_height'] as $side) {
            if ($options[$side] < 1) {
                $options[$side] = $defaults[$side];
            }
        }

        // Validate PDF file
        if (!file_exists($pdfPath)) {
            return ConversionResult::failure(
                __('PDF file not found.', 'rapls-pdf-image-creator'),
                \Rapls\PDFImageCreator\FailureCode::SOURCE_MISSING
            );
        }

        if (!is_readable($pdfPath)) {
            return ConversionResult::failure(
                __('PDF file is not readable.', 'rapls-pdf-image-creator'),
                \Rapls\PDFImageCreator\FailureCode::SOURCE_MISSING
            );
        }

        $imagick = null;

        try {
            $imagick = new \Imagick();

            // Set resolution before reading (important for quality). This runs
            // through a filter, so a caller can hand back 0 or a negative --
            // either makes Imagick render nothing. Fall back to the default.
            $resolution = (int) $options['resolution'];
            if ($resolution < 1) {
                $resolution = $defaults['resolution'];
            }

            $page = max(0, (int) $options['page']);

            // Lowered for a page too large to draw at it. Whatever
            // Ghostscript draws is bounded -- a PDF, and PostScript or EPS,
            // which were drawn unbounded for not starting like a PDF (review
            // after 1.4.37). Anything else is read as it is, as up to 1.4.37:
            // a list of the rasters let past turned away the ones it did not
            // name -- BigTIFF, HEIC, AVIF, JPEG 2000 -- once a side was past
            // the measuring read's bound (review after 1.4.38).
            if (self::drawnByGhostscript($pdfPath)) {
                $resolution = $this->boundedResolution($pdfPath, $page, $resolution, $options);

                if (null === $resolution) {
                    $imagick->clear();

                    return ConversionResult::failure(
                        __('This page is too large to draw, even at the lowest resolution.', 'rapls-pdf-image-creator'),
                        \Rapls\PDFImageCreator\FailureCode::RESOURCE_LIMIT
                    );
                }
            }

            $imagick->setResolution($resolution, $resolution);

            // Read specific page from PDF
            $empty = false;
            $imagick = $this->readPage($imagick, $pdfPath, $page, $resolution, $empty);

            // A page with nothing on it, when nothing threw.
            //
            // Ghostscript can fail to parse a content stream, report it on its
            // own stderr where nobody sees it, and hand back a correctly sized
            // white page. ImageMagick raises nothing, the conversion succeeds,
            // and a white thumbnail is stored — the worst outcome, because it
            // looks like an answer. Measured: Ghostscript 9.27 does this to a
            // PowerPoint export that Ghostscript 10.04 renders correctly.
            //
            // Refusing is better than pretending. WordPress then shows its PDF
            // icon, which at least says "PDF" rather than "this document is
            // blank", and 1.4.0's failure reporting says what to ask the host.
            if ($empty && $this->looksWhite($imagick)) {
                /**
                 * Filter whether an all-white page counts as a failure.
                 *
                 * Return false to keep the blank image. For the rare document
                 * whose first page really is empty and whose owner wants a
                 * white thumbnail anyway.
                 *
                 * @since 1.4.0
                 *
                 * @param bool                 $refuse  Whether to fail.
                 * @param array<string, mixed> $options Conversion options.
                 */
                if (apply_filters('rapls_pdf_image_creator_refuse_blank_page', true, $options)) {
                    $imagick->clear();

                    return ConversionResult::failure(
                        __('The page rendered as a blank white image.', 'rapls-pdf-image-creator'),
                        \Rapls\PDFImageCreator\FailureCode::BLANK_RENDER
                    );
                }
            }

            $sourceColorspace = $imagick->getImageColorspace();

            // Colour conversion runs before any resizing: downsampling can
            // discard the embedded profile the conversion depends on.
            $colorMode = $this->colorProfile->convertToSrgb($imagick);
            $this->recordDiagnostics($sourceColorspace, $colorMode);

            $format = $this->normalizeFormat((string) $options['format']);

            // Transparent regions come back from the PDF delegate as alpha, and
            // flattening them without an explicit background is what produced
            // the all-black thumbnails. JPEG has no alpha at all, so a
            // transparent background there can only mean white.
            $bgColorName = $this->resolveBackground((string) $options['bgcolor'], $format);
            $keepAlpha = 'transparent' === $bgColorName;

            $imagick->setImageBackgroundColor($this->getBgColor($bgColorName));
            $imagick = $this->flatten($imagick);

            if (!$keepAlpha) {
                $this->removeAlphaChannel($imagick);
            }

            /**
             * Filter the rendered page before it is resized.
             *
             * Here rather than anywhere else for two reasons. After the alpha
             * channel is gone, because anything measuring the page against its
             * background -- cropping to the content, for instance -- reads
             * nonsense while transparency is still present. Before the resize,
             * because a measurement taken on a downsampled page is a blurred
             * measurement.
             *
             * A listener may return a different Imagick instance; anything else
             * is ignored and the page carries on untouched. With no listener
             * this is a no-op, and it has to stay one: the plugin's own output
             * may not depend on someone being hooked here.
             *
             * @since 1.4.0
             *
             * @param \Imagick             $imagick Rendered page.
             * @param array<string, mixed> $options Conversion options, including
             *                                      attachment_id and source_path.
             */
            $filtered = apply_filters('rapls_pdf_image_creator_before_resize', $imagick, $options);

            if ($filtered instanceof \Imagick) {
                $imagick = $filtered;
            }

            // Resize if necessary
            $this->resizeImage($imagick, $options['max_width'], $options['max_height']);

            // Set output format
            $imagick->setImageFormat($format);
            if ($format === 'JPEG') {
                $imagick->setImageCompression(\Imagick::COMPRESSION_JPEG);
            }
            $imagick->setImageCompressionQuality($options['quality']);

            // Strip metadata
            $imagick->stripImage();

            // stripImage() also drops the sRGB profile the ICC conversion
            // wrote, so put it back on colour-managed output.
            //
            // Measured on ImageMagick 7.1.1: this reaches the file for JPEG
            // and WebP, and does not for PNG -- PNG output carries no iCCP and
            // no sRGB chunk, so it ships untagged. That is a limitation rather
            // than a defect in what people see: the pixels are sRGB and an
            // untagged image is read as sRGB everywhere, so PNG thumbnails
            // look the same as the other two.
            //
            // It can be forced with png:include-chunk=all, and the price is
            // not worth paying: this build then writes the profile twice, once
            // as iCCP and again as a "Raw profile type icc" zTXt chunk, taking
            // a 5.7 KB file where an untagged one is 87 bytes. Narrower values
            // for that option are ignored. Since the plugin writes a thumbnail
            // in every registered size, doubling the metadata on all of them
            // buys nothing a viewer could see.
            if (ColorProfile::MODE_ICC === $colorMode) {
                $this->colorProfile->attachSrgb($imagick);
            }

            // Get dimensions before writing
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            // Ensure output directory exists
            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                if (!wp_mkdir_p($outputDir)) {
                    return ConversionResult::failure(
                        __('Failed to create output directory.', 'rapls-pdf-image-creator'),
                        \Rapls\PDFImageCreator\FailureCode::WRITE_FAILED
                    );
                }
            }

            // Write image
            $imagick->writeImage($outputPath);

            // Get file size
            $fileSize = filesize($outputPath) ?: 0;

            // Clean up
            $imagick->clear();
            $imagick->destroy();

            $conversionTime = microtime(true) - $startTime;

            /**
             * Filter the Imagick result before returning
             *
             * @param ConversionResult $result The conversion result
             * @param string $pdfPath Original PDF path
             * @param array $options Conversion options
             */
            $result = ConversionResult::success(
                $outputPath,
                $width,
                $height,
                $fileSize,
                $conversionTime
            );

            $filtered = apply_filters('rapls_pdf_image_creator_imagick_result', $result, $pdfPath, $options);

            // A listener answering anything else was a TypeError at this
            // return, taking the upload with it (review after 1.4.31).
            return $filtered instanceof ConversionResult ? $filtered : $result;

        } catch (\ImagickException $e) {
            self::release($imagick);

            // A page past the end of the file: Ghostscript says so only on a
            // stderr nobody sees, and ImageMagick's own words were "Failed to
            // read the file" -- with a site-wide page set, every shorter PDF
            // got no thumbnail and a message about ImageMagick (review after
            // 1.4.31). Counted only when a page past the first was asked for,
            // and only for a read that failed as a read does: a policy, no
            // Ghostscript or a page too large is that, whatever the count
            // (review after 1.4.34; a policy on page 1 of a one-page file was
            // reported as a missing page).
            $code = \Rapls\PDFImageCreator\FailureCode::fromExceptionMessage($e->getMessage(), true);
            $pages = null;

            // ImageMagick's count draws every page, at 2 dpi and under no
            // limit of ours: not asked where the file's boxes say a page in it
            // is too large to draw small, or cannot say (review after 1.4.37).
            if ((int) $options['page'] > 0 && \Rapls\PDFImageCreator\FailureCode::RENDER_ERROR === $code) {
                // ImageMagick's count first: the file's own figure is the
                // largest /Count it states, which a file can overstate and an
                // update that deletes pages leaves behind -- and the page then
                // came back as a render error (review after 1.4.34). One
                // Ghostscript pass over the document, on a failure only; the
                // file's figure where ImageMagick cannot count.
                $pages = self::smallEnoughToCount($pdfPath, $this->renderPixelLimit($pdfPath, $options)) ? self::pagesPinged($pdfPath) : null;
                $pages = null !== $pages ? $pages : self::pagesFromBytes($pdfPath);
            }

            if (null !== $pages && $pages <= (int) $options['page']) {
                return ConversionResult::failure(
                    sprintf(
                        /* translators: 1: the page asked for, counted from 0; 2: how many pages the PDF has */
                        _n(
                            'Page %1$d was asked for (counting from 0), but this PDF has %2$d page.',
                            'Page %1$d was asked for (counting from 0), but this PDF has %2$d pages.',
                            $pages,
                            'rapls-pdf-image-creator'
                        ),
                        (int) $options['page'],
                        $pages
                    ),
                    \Rapls\PDFImageCreator\FailureCode::PAGE_OUT_OF_RANGE
                );
            }

            return ConversionResult::failure(
                sprintf(
                    /* translators: %s: error message */
                    __('Imagick error: %s', 'rapls-pdf-image-creator'),
                    $e->getMessage()
                ),
                $code
            );
        } catch (\Throwable $e) {
            self::release($imagick);

            // Not only Exception: a TypeError or an Error from the conversion
            // or a listener is a failed conversion too, and escaping, it took
            // the upload request down (review after 1.4.31).
            return ConversionResult::failure(
                $e->getMessage(),
                \Rapls\PDFImageCreator\FailureCode::fromExceptionMessage($e->getMessage(), true)
            );
        }
    }

    /**
     * Let go of a page a failed conversion was holding
     *
     * It held its pixels until the request ended: in a Bulk Generate batch,
     * one failed page's raster per PDF that failed after reading (review
     * after 1.4.34).
     *
     * @param mixed $imagick The conversion's instance, or null before one.
     */
    private static function release($imagick): void
    {
        if ($imagick instanceof \Imagick) {
            try {
                $imagick->clear();
            } catch (\Throwable $e) {
                // Nothing more to do.
            }
        }
    }

    /**
     * Transient an earlier version kept about reading single pages
     *
     * Nothing writes or reads it since 1.4.34, when the whole-document read it
     * steered was taken out (readPage()). Still in probeTransients(), so the
     * Status tab's re-check and uninstall clear what is left of it.
     */
    private const PAGE_SUFFIX_TRANSIENT = 'rapls_pic_page_suffix';

    /**
     * How many pages ImageMagick finds, by pingImage() -- a Ghostscript pass
     * over the whole document
     *
     * Not free: Ghostscript draws every page at 2 dpi for it (measured, IM
     * 7.1.1 and gs 10.04: 2.7 s for a 100-page document, against 0.13 s to
     * draw one page; review of 1.4.32), so it is asked once, on a failure
     * only. Not under the measuring read's limits: the memory is
     * Ghostscript's, which they do not reach (291 MB for a document holding
     * a 300,000-point page, with them or without), and a page past them is
     * left out of the count -- that document was counted as one page
     * (review after 1.4.34).
     */
    private static function pagesPinged(string $pdfPath): ?int
    {
        $count = null;

        try {
            $count = new \Imagick();
            $count->pingImage($pdfPath);
            $pages = (int) $count->getNumberImages();

            return $pages > 0 ? $pages : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            if (null !== $count) {
                $count->clear();
            }
        }
    }

    /** The most page-tree dictionaries read from one file's bytes (pagesFromBytes()). */
    private const PAGE_TREE_MAX_NODES = 10000;

    /** The largest file whose page tree is looked for in its bytes. */
    private const PAGE_TREE_MAX_BYTES = 33554432;

    /**
     * The page count the file's page tree states, read from its bytes
     *
     * The largest /Count among the /Type /Pages dictionaries: the root's,
     * and, in a file saved over itself, never less than the newest root's.
     * Null when the tree cannot be read plainly -- in object streams (a
     * compressed tree), in a dictionary with another inside it, past
     * PAGE_TREE_MAX_BYTES -- and the caller asks ImageMagick instead.
     * The figure is the file's word, and Ghostscript does not go by it: it
     * draws the pages it finds by walking the tree. A damaged file can state
     * fewer, a file can simply lie, and an update that deletes pages leaves
     * the old /Count behind -- so convert() asks ImageMagick first and falls
     * back on this only where ImageMagick cannot count, and nothing is ever
     * sized on it (readPage()).
     */
    public static function pagesFromBytes(string $pdfPath): ?int
    {
        $size = @filesize($pdfPath);

        if (!is_int($size) || $size < 1 || $size > self::PAGE_TREE_MAX_BYTES || !self::memoryFor($size)) {
            return null;
        }

        $bytes = @file_get_contents($pdfPath);

        if (!is_string($bytes) || false !== strpos($bytes, '/ObjStm')) {
            return null;
        }

        $largest = 0;
        $seen = 0;
        $from = 0;
        $spans = 0;
        $length = strlen($bytes);

        // One match at a time, not all of them collected first: half a
        // million page-tree dictionaries in an 11 MB file made the list of
        // matches alone run past a 128 MB limit, a fatal error no catch
        // reaches (review after 1.4.32). A real document has a page tree of
        // some hundreds of nodes at most; one with more is left to
        // ImageMagick.
        while (1 === preg_match('#/Type\s*/Pages(?![A-Za-z0-9])#', $bytes, $match, PREG_OFFSET_CAPTURE, $from)) {
            if (++$seen > self::PAGE_TREE_MAX_NODES) {
                return null;
            }

            $at = (int) $match[0][1];
            $from = $at + strlen($match[0][0]);

            // Searched back from the match in place: a copy of everything
            // before it, per match, held up to 32 MB again each time and
            // took 1.5 s over 2,000 page-tree nodes (review after 1.4.32).
            $open = $at > 1 ? strrpos($bytes, '<<', $at - 2 - strlen($bytes)) : false;
            $close = strpos($bytes, '>>', $at);

            if (false === $open || false === $close) {
                return null;
            }

            // The dictionaries read, together, are within the file once
            // over: in a real file they do not overlap. Ten thousand matches
            // inside one dictionary the size of the file were each searched
            // and copied whole -- 75 s of CPU for a 32 MB file (review after
            // 1.4.32).
            $spans += $close - $open;

            if ($spans > $length) {
                return null;
            }

            $dictionary = substr($bytes, $open + 2, $close - $open - 2);

            if (false !== strpos($dictionary, '<<') || !preg_match('#/Count\s+(\d+)#', $dictionary, $count)) {
                return null;
            }

            $largest = max($largest, (int) $count[1]);
        }

        if (0 === $seen || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $largest > 0 ? $largest : null;
    }

    /**
     * Whether a file this size can be read into memory with room to spare
     *
     * Twice its size, for the file and the work on it: on a 64 MB limit, a
     * 32 MB file read whole was a fatal error no catch reaches, in a request
     * that ImageMagick could have answered by its own route.
     */
    private static function memoryFor(int $size): bool
    {
        $limit = (string) ini_get('memory_limit');

        if ('' === $limit || '-1' === $limit) {
            return true;
        }

        if (function_exists('wp_convert_hr_to_bytes')) {
            $bytes = (int) wp_convert_hr_to_bytes($limit);
        } else {
            $bytes = (int) $limit;
            $unit = strtolower(substr($limit, -1));
            $bytes *= 'g' === $unit ? 1073741824 : ('m' === $unit ? 1048576 : ('k' === $unit ? 1024 : 1));
        }

        return $bytes <= 0 || $bytes - memory_get_usage() > 2 * $size + 8388608;
    }

    /** How long a PDF read check that failed for no lasting reason is kept (probePdfRead()). */
    public const PASSING_FAILURE_SECONDS = 600;

    /**
     * The most pixels a page is drawn at, before any filter (boundedResolution())
     *
     * An A4 page at 600 dpi, the highest resolution the Settings tab offers,
     * is 35 megapixels. 40 megapixels are more than four times the largest
     * thumbnail the Settings tab allows (4096 pixels) each way, on any page
     * that is not extremely narrow.
     *
     * Not lowered to the thumbnail's size: that drew an A4 page at 416 dpi
     * when 600 was asked for, and the resolution is the site's choice. The
     * add-on's crops and the before_resize filter get the page as drawn
     * (review after 1.4.34).
     */
    public const RENDER_MAX_PIXELS = 40000000;

    /**
     * Transient remembering whether Ghostscript here needed a nudge
     */
    private const GS_OPTIONS_TRANSIENT = 'rapls_pic_gs_options';

    /**
     * The lowest resolution Ghostscript draws at through ImageMagick
     *
     * Measured on ImageMagick 7.1.1 and Ghostscript 10.04: at 1 dpi and below
     * the read fails ("rangecheck in setscreen"); 2 dpi draws.
     */
    private const MIN_RESOLUTION = 2.0;

    /**
     * The most pixels the read that measures one page is drawn at, when its size is known to be no more than a bound
     *
     * The file's largest box, or a first measurement at MIN_RESOLUTION.
     */
    private const MEASURE_MAX_PIXELS = 4000000;

    /**
     * The resolution a page that failed to draw at MIN_RESOLUTION is measured again at
     *
     * Enough for a page under a pixel at 2 dpi -- under 36 points -- and no
     * more: Ghostscript draws the whole page before ImageMagick's limits can
     * refuse it, and a page that failed at 2 dpi for another reason is not
     * drawn at the resolution asked to find that out. Measured on
     * Ghostscript 10.04: a 500,000-point page ran past 30 s at 150 dpi.
     */
    private const SMALL_PAGE_RESOLUTION = 36.0;

    /**
     * The most bytes of object streams inflated to find a file's page boxes (largestPageBox())
     */
    private const BOX_MAX_INFLATED = 16777216;

    /**
     * The most page boxes read from one file (largestPageBox())
     */
    private const BOX_MAX_COUNT = 100000;

    /**
     * Keys that, spelt with #xx escapes, would hide a page's size from largestPageBox()
     */
    private const BOX_KEYS = ['MediaBox', 'CropBox', 'BleedBox', 'TrimBox', 'ArtBox', 'UserUnit', 'First', 'Filter', 'DecodeParms', 'Length'];

    /**
     * The resolution this page can be drawn at
     *
     * Nothing bounded the read before 1.4.34: a 470-byte PDF declaring one
     * page of 200 by 200 inches took 76 seconds and 2.9 GB at 150 dpi
     * (ImageMagick 7.1.1 and Ghostscript 10.04). A page that would come out
     * larger than the limit (renderPixelLimit()) is drawn at a lower
     * resolution.
     *
     * 1. The largest box in the file, read from its bytes -- object streams
     *    inflated -- with nothing run (largestPageBox()). Within the limit,
     *    the page is drawn as asked, and that is every ordinary PDF.
     * 2. Otherwise, or when the bytes cannot answer, page n itself is drawn
     *    small enough to be cheap and measured (measurePage()), under
     *    limits that make ImageMagick refuse a page too large before its
     *    pixels are held. Ghostscript decides that size, so /Rotate,
     *    inherited boxes and UserUnit come out as they will in the real read.
     *
     * 1.4.34 asked ImageMagick by a ping instead. ImageMagick 7 answers a
     * ping from its own scan of the bytes, for the largest page: a page in a
     * compressed object stream came back as Letter, and a page with
     * /UserUnit at its box -- either way, a 100,000-point page was drawn at
     * 150 dpi for over two minutes, with width and height limits set or not
     * (review after 1.4.34). The ping also had Ghostscript draw every page
     * of the document at 2 dpi, for every conversion.
     *
     * Only ever lowered. A page that cannot be measured is not drawn: the
     * measuring read's error is the failure, classified as a read's -- a
     * policy, no Ghostscript, a page past the end. A page refused by the
     * limits even at MIN_RESOLUTION, the least Ghostscript draws at, is too
     * large, and is not drawn again to find that out.
     *
     * A page so thin that it is under one pixel across at MIN_RESOLUTION
     * and too long to be drawn at SMALL_PAGE_RESOLUTION -- 300,000 by 12
     * points -- fails the measuring read. Where that read's error is
     * libpng's "Invalid IHDR data" it is reported as too large, as before
     * 1.4.34; a build that fails it otherwise (a delegates error was seen on
     * one) reports a render error. Either way it is not drawn. Left so: drawing
     * it wider to get one pixel would draw its length past the limit, and
     * such a page has nothing a thumbnail could show (review after 1.4.36).
     *
     * @param array<string, mixed> $options Conversion options, for the filter.
     * @return float|null The resolution, or null when even MIN_RESOLUTION is
     *                    over the limit.
     * @throws \Throwable The measuring read's error, when it failed for any
     *                    reason but the page's size.
     */
    private function boundedResolution(string $pdfPath, int $page, float $resolution, array $options): ?float
    {
        $limit = $this->renderPixelLimit($pdfPath, $options);
        $scan = self::scanPageBoxes($pdfPath);
        $box = null === $scan ? null : $scan['largest'];

        // Within the limit even as the largest box in the file, either way
        // round: drawn as asked, and nothing else run.
        if (null !== $box) {
            $short = $box[0] / $box[1];

            if ($this->fittedResolution($short, $box[1], $resolution, $limit) === $resolution
                && $this->fittedResolution($box[1], $short, $resolution, $limit) === $resolution) {
                return $resolution;
            }
        }

        // The measuring read holds no more than an ordinary page at the
        // default limit, or this site's lower one. A site that raised the
        // limit past the default still has its pages measured within the
        // default, and one past it even at MIN_RESOLUTION is refused, as
        // since 1.4.34: the measuring read is what keeps that page small.
        $pixels = min($limit, (float) self::RENDER_MAX_PIXELS);
        $side = (int) ceil(sqrt($pixels));
        $measureAt = self::MIN_RESOLUTION;

        // Where the boxes give no upper bound but do give a lower one -- every
        // page in the file at least so large, /UserUnit counted -- a page
        // past the measuring read's bound even at MIN_RESOLUTION is not read
        // to find that out. The limits refuse such a page only after
        // Ghostscript has drawn it, and that drawing is bounded by nothing of
        // ours (review after 1.4.37). A lower bound only ever stops a page
        // that is too large: a box smaller than the page makes it lower. Asked
        // before the Imagick that cannot limit a read is let through below:
        // there it was drawn as asked (review after 1.4.38).
        //
        // A page with no box of its own has Ghostscript's default paper, and
        // a file holding one beside large boxes, /UserUnit somewhere, would
        // be refused here though that page is small. Not floored at Letter
        // and A4 as the largest is: every page has a box by the standard,
        // inherited from its /Pages where not its own, and the floor would
        // leave the bound nothing to stop.
        if (null === $box && null !== $scan && null !== $scan['least']
            && $scan['least'] * (self::MIN_RESOLUTION / 72) ** 2 > $pixels) {
            return null;
        }

        // An Imagick with no resource limit to set cannot hold a read down
        // (none is known; every release has the memory and disk limits).
        // The file's boxes are then the only measure, and without them the
        // page is drawn as asked, as before 1.4.34.
        if (!self::canLimitReads()) {
            return null === $box ? $resolution : $this->fittedResolution($box[0] / $box[1], $box[1], $resolution, $limit);
        }

        if (null !== $box) {
            // The page asked is no larger than the largest box, so drawing it
            // where that box would be MEASURE_MAX_PIXELS keeps this read that
            // small, and measures an ordinary page to the pixel.
            $measureAt = max(self::MIN_RESOLUTION, min($resolution, 72 * sqrt(self::MEASURE_MAX_PIXELS / $box[0])));

            // The boxes hold its area down, so a side may be as long as the
            // longest of them: under a square limit, a 300,000-point strip
            // an inch wide was refused at 2 dpi though it draws at 63, and a
            // page just past a site's lower limit was measured at 2 dpi,
            // coarsely, instead of at the density asked.
            if ($box[0] * ($measureAt / 72) ** 2 <= max((float) self::MEASURE_MAX_PIXELS, $pixels)) {
                // Bounded below what an int holds: the area is small, the
                // side may still be a 400-digit number, and casting a float
                // past PHP_INT_MAX warns on PHP 8.5 (review after 1.4.38).
                $side = max($side, (int) min(ceil($box[1] * $measureAt / 72) + 2, (float) self::SIDE_MAX));
            }
        }

        try {
            $measured = $this->measurePage($pdfPath . '[' . $page . ']', $measureAt, $side, $pixels);
        } catch (\Throwable $e) {
            if (self::refusedAsTooLarge($e)) {
                if ($measureAt <= self::MIN_RESOLUTION) {
                    return null;
                }

                // Refused at more than the least: perhaps not at the least.
                $measureAt = self::MIN_RESOLUTION;

                try {
                    $measured = $this->measurePage($pdfPath . '[' . $page . ']', self::MIN_RESOLUTION, $side, $pixels);
                } catch (\Throwable $again) {
                    if (self::refusedAsTooLarge($again)) {
                        return null;
                    }

                    throw $again;
                }
            } elseif ($measureAt < min($resolution, self::SMALL_PAGE_RESOLUTION)
                && self::smallEnoughToMeasureAt($box, min($resolution, self::SMALL_PAGE_RESOLUTION), $pixels)) {
                // A page under a pixel at the measuring density fails to
                // draw at all: measured on Ghostscript 10.04, a 5-point page
                // at 2 dpi, which draws at 150. Measured again at
                // SMALL_PAGE_RESOLUTION, under the same limits; a page that
                // fails there too fails as the read would.
                $measureAt = min($resolution, self::SMALL_PAGE_RESOLUTION);
                $measured = $this->measurePage($pdfPath . '[' . $page . ']', $measureAt, (int) ceil(sqrt($pixels)), $pixels);
            } else {
                throw $e;
            }
        }

        $fitted = $this->fittedResolution($measured[0], $measured[1], $resolution, $limit);

        // Measured at MIN_RESOLUTION, a pixel over each way is up to 36
        // points over: an A4 page at 600 dpi came out at 596 (review after
        // 1.4.37). Where that lowered the page, it is measured once more at
        // the density that keeps it to MEASURE_MAX_PIXELS -- the first
        // measurement is an upper bound, so that read is bounded. Whatever
        // put it at MIN_RESOLUTION: no box, a far larger page elsewhere in
        // the file, or a refusal at more (review after 1.4.38: only the
        // first was measured again).
        if ($measureAt <= self::MIN_RESOLUTION && null !== $fitted && $fitted < $resolution) {
            $fine = min($resolution, 72 * sqrt(self::MEASURE_MAX_PIXELS / ($measured[0] * $measured[1])));

            if ($fine >= 2 * self::MIN_RESOLUTION) {
                try {
                    $again = $this->measurePage($pdfPath . '[' . $page . ']', $fine, max($side, (int) min(ceil(max($measured[0], $measured[1]) * $fine / 72) + 2, (float) self::SIDE_MAX)), $pixels);
                    $finer = $this->fittedResolution($again[0], $again[1], $resolution, $limit);
                    $fitted = null !== $finer ? max($fitted, $finer) : $fitted;
                } catch (\Throwable $e) {
                    // The first measurement stands.
                }
            }
        }

        return $fitted;
    }

    /**
     * Whether a page no larger than this box may be measured at this density
     *
     * The measuring read's limits refuse a page past them only after
     * Ghostscript has drawn it: drawing every page at 36 dpi to find a
     * small one was bounded by nothing when the file held a large one
     * (review after 1.4.37). With no box, the page may be one under a pixel
     * at 2 dpi, and is measured.
     *
     * @param array{0: float, 1: float}|null $box The largest box, or null.
     */
    private static function smallEnoughToMeasureAt(?array $box, float $density, float $pixels): bool
    {
        if (null === $box) {
            return true;
        }

        return $box[0] * ($density / 72) ** 2 <= $pixels && $box[1] * $density / 72 <= ceil(sqrt($pixels));
    }

    /**
     * Whether ImageMagick may be asked to count this file's pages
     *
     * The count draws every page at 2 dpi, under no limit of ours, on a
     * failure only (pagesPinged()). Asked only where the file's boxes say
     * PAGES_ASSUMED pages of the largest size fit this site's limit even so
     * -- not the pages the file states: its /Count is its word, a file can
     * state 1 and hold thousands, and Ghostscript draws what it finds by
     * walking the tree. Sizing the count on /Count was the bound this file
     * forbids everywhere else (review of 1.4.38's fixes; the bound was one
     * page's before that, review after 1.4.38). Under the default limit
     * that is a file whose largest page is up to about 2,280 points square
     * (A0 is 2,384 by 3,370): a page past the end of a document with larger
     * pages is reported from the file's own figure, as a message only --
     * where the page tree is in an object stream, as a render error.
     * Accepted: a better reason for a missing page is not worth an
     * unbounded Ghostscript pass.
     */
    private static function smallEnoughToCount(string $pdfPath, float $limit): bool
    {
        $box = self::largestPageBox($pdfPath);

        if (null === $box) {
            return false;
        }

        return $box[0] * (self::MIN_RESOLUTION / 72) ** 2 * self::PAGES_ASSUMED
            <= min($limit, (float) self::RENDER_MAX_PIXELS);
    }

    /** The pages a file is taken to hold, for smallEnoughToCount(): never the figure it states. */
    private const PAGES_ASSUMED = 10000;

    /** The longest side a measuring read is given, in pixels: an int, whatever the box says. */
    private const SIDE_MAX = 1000000000;

    /**
     * The most pixels this page may be drawn at
     *
     * @param array<string, mixed> $options Conversion options, for the filter.
     */
    private function renderPixelLimit(string $pdfPath, array $options): float
    {
        /**
         * Filter the most pixels one page of a PDF is drawn at.
         *
         * Past this, the page is drawn at a lower resolution. A thumbnail is
         * cut down to the Settings tab's maximum size afterwards, so this
         * costs nothing a visitor sees unless the page is cropped first.
         *
         * @since 1.4.34
         *
         * @param int                  $pixels  Pixels. Default 40,000,000.
         * @param string               $pdfPath Absolute path to the PDF.
         * @param array<string, mixed> $options Conversion options.
         */
        $limit = apply_filters('rapls_pdf_image_creator_max_render_pixels', self::RENDER_MAX_PIXELS, $pdfPath, $options);

        return is_numeric($limit) && (float) $limit >= 1 ? (float) $limit : (float) self::RENDER_MAX_PIXELS;
    }

    /**
     * Whether an ImageMagick refusal was about the page's size
     */
    private static function refusedAsTooLarge(\Throwable $e): bool
    {
        return \Rapls\PDFImageCreator\FailureCode::RESOURCE_LIMIT === \Rapls\PDFImageCreator\FailureCode::fromExceptionMessage($e->getMessage(), true);
    }

    /**
     * The resolution a page of this size in points is drawn at within the limit
     *
     * A size of 0 or less is a guard, not a case: the boxes are at least
     * Letter and A4 (largestPageBox()) and a measured page a pixel more each
     * way (measurePage()), so none reaches here. A page whose box really is
     * 0 by 0 fails in Ghostscript before anything is measured -- measured on
     * Ghostscript 10.04: "/undefined in --runpdf--", "[/PageSize [0 0]]", a
     * render error -- so the "too large" convert() would report for the null
     * is never what a site sees for one.
     *
     * @return float|null Null when even MIN_RESOLUTION is over the limit, or
     *                    the size is not one: a page of no width is not one
     *                    to draw as asked, whatever said so.
     */
    private function fittedResolution(float $width, float $height, float $resolution, float $limit): ?float
    {
        // Nor a size past what a float holds: a box of 400 digits came out
        // as INF, INF / INF as NaN, and NaN compares false either way, so it
        // was handed back as the resolution (review after 1.4.37).
        if (!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0) {
            return null;
        }

        // And within what this server's pixel cache can hold, where its disk
        // is limited: past that, ImageMagick refuses the page after
        // Ghostscript has drawn it ("CacheResourcesExhausted"). Measured on
        // ImageMagick 7.1.1 with 64 MiB of memory and of disk: an A0 page
        // was refused at 80 dpi and drawn at 60 (review after 1.4.34).
        $cache = self::cachePixels();

        $fits = 72 * sqrt(min($limit, null === $cache ? $limit : $cache) / ($width * $height));

        // And within the server's own width and height limits, where it has
        // stricter ones: past them ImageMagick refuses the page after
        // Ghostscript has drawn it ("Invalid IHDR data"). A pixel short of
        // each, for Ghostscript's rounding.
        foreach (self::siteSideLimits() as $axis => $most) {
            $fits = min($fits, 72 * ($most - 1) / (0 === $axis ? $width : $height));
        }

        if ($resolution <= $fits) {
            return $resolution;
        }

        // Two decimals, rounded down, so the page stays within the limit.
        $fits = floor(100 * $fits) / 100;

        return $fits < self::MIN_RESOLUTION ? null : $fits;
    }

    /**
     * The width and height limits this server sets, where it sets any
     *
     * @return array<int, float> 0 for the width, 1 for the height; a limit
     *         that is unreadable, absent or under 2 is left out. Under 2 is
     *         not "none": measured on ImageMagick 7.1.1, a width limit of 0
     *         refuses every read ("Invalid IHDR data"), and the read reports
     *         that itself.
     */
    private static function siteSideLimits(): array
    {
        $limits = [];

        foreach (self::$limitTypeNames as $axis => $name) {
            $most = self::resourceLimit($name);

            // "Unlimited" is 2^64, which no page reaches; it needs no case of its own.
            if (null !== $most && $most >= 2) {
                $limits[$axis] = $most;
            }
        }

        return $limits;
    }

    /**
     * The most pixels this server's pixel cache holds for one page, where its disk is limited
     *
     * A page's pixels go to memory, and past the memory limit to disk; past
     * both, the read is refused. The page read and the copy flattening makes
     * of it are held together, so both are counted.
     *
     * @return float|null Null when the disk is not limited.
     */
    private static function cachePixels(): ?float
    {
        $memory = self::resourceLimit('RESOURCETYPE_MEMORY');
        $disk = self::resourceLimit('RESOURCETYPE_DISK');

        // ImageMagick reports no limit as 2^64, which no page reaches.
        if (null === $memory || null === $disk || $disk <= 0) {
            return null;
        }

        // One copy in memory and one on disk, or both in the larger.
        return max(min($memory, $disk), max($memory, $disk) / 2) / self::bytesPerPixel();
    }

    /**
     * Bytes a pixel takes in this build's pixel cache, with room for CMYK and alpha
     */
    private static function bytesPerPixel(): float
    {
        $depth = 16;

        try {
            $quantum = \Imagick::getQuantumDepth();

            if (is_array($quantum) && isset($quantum['quantumDepthLong']) && (int) $quantum['quantumDepthLong'] >= 8) {
                $depth = (int) $quantum['quantumDepthLong'];
            }
        } catch (\Throwable $e) {
            // The common build, Q16.
        }

        // HDRI builds keep each value as a float, twice a Q16 value.
        try {
            $features = \Imagick::getConfigureOptions('FEATURES');
            $hdri = is_array($features) && false !== stripos(implode(' ', $features), 'HDRI');
        } catch (\Throwable $e) {
            $hdri = false;
        }

        // Five channels: CMYK with alpha, the most a PDF page comes back with.
        return 5 * ($hdri ? max(4, $depth / 8) : $depth / 8);
    }

    /**
     * One of ImageMagick's resource limits, by constant name
     *
     * @return float|null Null when this build has no such limit or will not say.
     */
    private static function resourceLimit(string $name): ?float
    {
        if (!defined('\Imagick::' . $name)) {
            return null;
        }

        try {
            $most = \Imagick::getResourceLimit(constant('\Imagick::' . $name));
        } catch (\Throwable $e) {
            return null;
        }

        return is_numeric($most) ? (float) $most : null;
    }

    /**
     * The largest page box anywhere in the file, from its bytes, with nothing run
     *
     * Every /MediaBox, /CropBox, /BleedBox, /TrimBox and /ArtBox in the file
     * and in its object streams, inflated: stale objects an update replaced,
     * boxes of pages not asked for, a box written in a comment or a content
     * stream and all. An upper bound for any page -- which is all
     * boundedResolution() needs of it; a box counted that is no page's only
     * ever makes the answer larger. At least Letter and A4 together, the
     * sizes Ghostscript gives a page with no box.
     *
     * The answer is only as good as the claim that no page box was missed,
     * so the file is first read token by token (objectStreams()) to find the
     * object streams Ghostscript would open: a dictionary found by searching
     * back from its /First was cut short by a key ending in "obj" or by a
     * value past a 4 KB window, its /Filter went unseen, the stream was
     * taken as plain -- and a 14,400-point page inside it, beside a small box
     * anywhere else, was drawn at 150 dpi in 97 s and 3.2 GB (review after
     * 1.4.35).
     *
     * Null wherever the bytes could hide a larger page: a file not starting
     * with "%PDF-" (ImageMagick would read it as something else), a file the
     * token reading cannot follow to its end, a /First anywhere that reading
     * did not see as a key (inside a stream, a string, a comment or where a
     * value belongs, where a repaired file could still find it),
     * /UserUnit, a box that is not four plain numbers, a key spelt with #xx
     * escapes, more names with them than BOX_MAX_COUNT, arrays or
     * dictionaries nested past NESTING_MAX, an object stream filtered
     * otherwise, too large, or encrypted (there is no test for encryption
     * as such: encrypted data does not inflate), no box at all, a file past
     * PAGE_TREE_MAX_BYTES. The page is then measured by drawing it.
     *
     * What it holds is bounded as well as what it reads: the file, the
     * object streams it inflates (BOX_MAX_INFLATED in all), and nothing per
     * token. 200 KB of "[" crashed PHP and a 24 KB file of "/#41" took
     * 346 MB, past any catch (review after 1.4.36).
     *
     * @return array{0: float, 1: float}|null The largest area in square
     *         points, and the longest side in points.
     */
    public static function largestPageBox(string $pdfPath): ?array
    {
        $scan = self::scanPageBoxes($pdfPath);

        return null === $scan ? null : $scan['largest'];
    }

    /**
     * The file's boxes, read as largestPageBox() reads them: the largest, and a lower bound for every page
     *
     * The lower bound is the smallest box in the file, its area in square
     * points, times the smallest /UserUnit where one is given and less than
     * 1: no page is smaller than that, so a page past a bound even at that
     * size is past it (boundedResolution()). Given only where every box was
     * read -- every object stream opened, no box key spelt with escapes, no
     * box that is not four numbers -- since one box unread could be the
     * small one. /UserUnit, which leaves the largest unknown, leaves this
     * known.
     *
     * @return array{largest: array{0: float, 1: float}|null, least: float|null}|null
     *         Null when the bytes say nothing of the page sizes.
     */
    private static function scanPageBoxes(string $pdfPath): ?array
    {
        $size = @filesize($pdfPath);

        if (!is_int($size) || $size < 1 || $size > self::PAGE_TREE_MAX_BYTES || !self::memoryFor($size + self::BOX_MAX_INFLATED)) {
            return null;
        }

        $bytes = @file_get_contents($pdfPath);

        if (!is_string($bytes) || 0 !== strncmp($bytes, '%PDF-', 5)) {
            return null;
        }

        $streams = self::objectStreams($bytes);

        if (null === $streams) {
            return null;
        }

        $texts = [$bytes];
        $inflated = 0;

        foreach ($streams as $place) {
            // Read from the file where it lies: a copy of each stream's data,
            // and a list of its kilobytes, came on top of the file (review
            // after 1.4.37).
            $text = self::inflateWithin($bytes, self::BOX_MAX_INFLATED - $inflated, $place[0], $place[1]);

            if (null === $text) {
                return null;
            }

            $inflated += strlen($text);
            $texts[] = $text;
        }

        $area = 0.0;
        $side = 0.0;
        $least = INF;
        $units = INF;
        $userUnit = false;
        $count = 0;
        $space = '[' . self::PDF_WHITESPACE . ']';
        $number = '([+-]?(?:\d+\.?\d*|\.\d+))';
        $nameChar = '[^' . self::PDF_WHITESPACE . '/\[\]<>(){}%\#]';
        // Bounded, as readTokens() bounds a name: a key is short, and a
        // capture of a 31 MB name was a 31 MB copy (review after 1.4.36).
        $escapedName = '#/((?:' . $nameChar . '|\#[0-9A-Fa-f]{2}){0,256}\#[0-9A-Fa-f]{2}(?:' . $nameChar . '|\#[0-9A-Fa-f]{2}){0,256})#';
        $box = '#\G' . $space . '*\[' . $space . '*' . $number . $space . '+' . $number . $space . '+' . $number . $space . '+' . $number . $space . '*\]#';
        $end = '(?![^' . self::PDF_WHITESPACE . '/\[\]<>(){}%])';
        // A number, and not the start of a reference ("5 0 R"), whose value
        // is somewhere else.
        $unit = '#\G' . $space . '+' . $number . $end . '(?!' . $space . '+\d+' . $space . '+R' . $end . ')#';

        foreach ($texts as $text) {
            // "/Media#42ox" is /MediaBox to a reader. One name at a time, and
            // so many at most: collected all at once, 3.3 million "/#41" in a
            // 24 KB file held 346 MB (review after 1.4.36). Searched on from
            // where the whole match ends, its "/" included: from a byte later
            // a name right after it ("/A#41/Media#42ox") was stepped over.
            $from = 0;
            $escaped = 0;

            while (1 === ($result = preg_match($escapedName, $text, $name, PREG_OFFSET_CAPTURE, $from))) {
                $from = (int) $name[0][1] + strlen($name[0][0]);

                if (++$escaped > self::BOX_MAX_COUNT || in_array(self::decodeName($name[1][0]), self::BOX_KEYS, true)) {
                    return null;
                }
            }

            if (false === $result) {
                return null;
            }

            // /UserUnit makes a page larger than its box says, by a factor
            // only Ghostscript applies, page by page: the largest is not
            // known, the least is -- by the smallest factor, where one is
            // under 1.
            $offset = 0;

            while (1 === ($result = preg_match('#/UserUnit' . $end . '#', $text, $key, PREG_OFFSET_CAPTURE, $offset))) {
                $offset = (int) $key[0][1] + strlen($key[0][0]);
                $userUnit = true;

                if (++$count > self::BOX_MAX_COUNT || 1 !== preg_match($unit, $text, $numbers, 0, $offset)
                    || !is_finite((float) $numbers[1]) || (float) $numbers[1] <= 0) {
                    return null;
                }

                $units = min($units, (float) $numbers[1]);
            }

            if (false === $result) {
                return null;
            }

            $offset = 0;

            while (1 === ($result = preg_match('#/(?:Media|Crop|Bleed|Trim|Art)Box' . $end . '#', $text, $key, PREG_OFFSET_CAPTURE, $offset))) {
                $offset = (int) $key[0][1] + strlen($key[0][0]);

                if (++$count > self::BOX_MAX_COUNT || 1 !== preg_match($box, $text, $numbers, 0, $offset)) {
                    return null;
                }

                // x1 - x0 and y1 - y0: [1] to [4] are the four numbers.
                $width = abs((float) $numbers[3] - (float) $numbers[1]);
                $height = abs((float) $numbers[4] - (float) $numbers[2]);

                // A number past what a float holds is INF, and INF - INF is
                // NaN. Where max() and min() meet a NaN, PHP 8.2 and earlier
                // answer by the order of the arguments: a box of 400-digit
                // numbers after a 14,400-point one made the largest Letter
                // and A4, and the page was drawn at 150 dpi, unmeasured
                // (review after 1.4.38). A size that is not one is no answer.
                if (!is_finite($width) || !is_finite($height) || !is_finite($width * $height)) {
                    return null;
                }

                $area = max($area, $width * $height);
                $side = max($side, $width, $height);
                $least = min($least, $width * $height);
            }

            // A search that failed is not one that found nothing.
            if (false === $result) {
                return null;
            }
        }

        if (!is_finite($least)) {
            return null;
        }

        return [
            // At least Letter and A4 together: the sizes Ghostscript gives a
            // page with no box, where its default paper is one of those. A
            // build defaulting to another, or GS_OPTIONS naming a paper size,
            // gives such a page another size -- a page with no box at all,
            // which the standard does not allow.
            'largest' => $userUnit ? null : [max($area, 612.0 * 842.0), max($side, 842.0)],
            // An area: /UserUnit scales both sides (review of 1.4.37's fixes:
            // by the factor once, a 10,000,000-point box at 0.001 -- a
            // 10,000-point page -- was refused unread).
            'least' => $least * min(1.0, $units) ** 2,
        ];
    }

    /** The characters PDF counts as white space, for a character class: NUL, tab, LF, FF, CR, space. */
    private const PDF_WHITESPACE = '\x00\t\n\x0C\r ';

    /** The most tokens read from one file for its object streams (objectStreams()). */
    private const TOKENS_MAX = 2000000;

    /**
     * The longest name (without its "/") or word read as one token (readTokens()), in bytes
     *
     * A name is 127 bytes at most by the PDF standard. One of 31 MB was
     * copied whole into each of the match, its capture and its decoding, and
     * a 128 MB limit ended in a fatal error no catch reaches (review after
     * 1.4.36). Past this, the page is measured. Under PCRE's largest
     * repeat count, 65,535, since the match is bounded by one.
     */
    private const TOKEN_BYTES_MAX = 32768;

    /**
     * The deepest arrays and dictionaries are nested in a file read for its object streams (readTokens())
     *
     * A real file nests a handful deep. Past this, the page is measured.
     */
    private const NESTING_MAX = 64;

    /** The keys of a stream's dictionary takeStream() reads; no other value is kept. */
    private const STREAM_KEYS = ['Type', 'First', 'Filter', 'DecodeParms', 'Length'];

    /** A name with its #xx escapes decoded, as a reader sees it. */
    private static function decodeName(string $name): string
    {
        return (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static function (array $hex): string {
            return chr((int) hexdec($hex[1]));
        }, $name);
    }

    /**
     * The compressed object streams in a file, found by reading it token by token
     *
     * Strings, comments and the data of every stream are stepped over as a
     * reader steps over them, so nothing in them is taken for a key -- and a
     * dictionary is read from its "<<", wherever that is, whatever its keys
     * are called. An object stream (a stream whose dictionary has /First,
     * whatever its /Type says -- Ghostscript 10.04 reads none without /Type
     * /ObjStm, but opening one more only makes the answer larger) that is
     * not filtered is read on, as part of the file, for the /First keys of
     * the objects inside it.
     *
     * A dictionary is read key, value, key, value: a value is a number, a
     * string, a name, an array, a dictionary, "true", "false", "null" or a
     * reference ("12 0 R"), and anything else where a value belongs -- a
     * brace, any other word -- or a value where a key belongs ends the
     * reading. A value it did not take as one, "true" among them, left the
     * dictionary waiting for a key, every key after it was read one place
     * out, /Type and /First as values, and the stream was not opened: a
     * 100,000-point page in it was drawn at 150 dpi (review after 1.4.35).
     *
     * @return array<int, array{0: int, 1: int}>|null Where the data of each
     *         one compressed with /FlateDecode alone starts, and its length;
     *         null when the file cannot be followed to its end, a stream is
     *         filtered otherwise, has parameters or is an object stream with
     *         no /First, a /First sits anywhere this reading did not see it
     *         as a key, or past TOKENS_MAX tokens or PAGE_TREE_MAX_NODES
     *         object streams.
     */
    private static function objectStreams(string $bytes): ?array
    {
        $state = ['firsts' => 0, 'tokens' => 0, 'streams' => []];

        if (!self::readTokens($bytes, false, $state)) {
            return null;
        }

        // Every /First in the bytes must be one this reading met as a key: one
        // inside a stream's data, a string or a comment is where a file
        // repaired by Ghostscript, its objects found by scanning, could still
        // hold an object stream this reading stepped over.
        $firsts = preg_match_all('#/First(?![^' . self::PDF_WHITESPACE . '/\[\]<>(){}%])#', $bytes);

        if (false === $firsts || $firsts !== $state['firsts']) {
            return null;
        }

        return $state['streams'];
    }

    /**
     * Read PDF tokens over some bytes, collecting what objectStreams() needs
     *
     * @param bool                 $inside Whether these bytes are an object
     *                                     stream's own: no stream may open
     *                                     there.
     * @param array<string, mixed> $state  Counts and streams so far.
     * @return bool False where the bytes cannot be followed.
     */
    private static function readTokens(string $bytes, bool $inside, array &$state): bool
    {
        $length = strlen($bytes);
        $at = 0;
        // Frames: ['dict', key pending, place in a reference] or
        // ['array', names collected]. Only the outermost dictionary keeps
        // anything ($entries), and only the keys takeStream() reads: every
        // value inside was once kept whole, and a file of nested brackets
        // freed them recursively past the C stack -- 200 KB of "[" crashed
        // PHP -- while 2 million numbers in one array held 278 MB (review
        // after 1.4.36).
        $stack = [];
        $entries = [];
        $last = null;
        // The top-level dictionary just closed, while nothing but white space
        // and comments has followed it: what a "stream" keyword belongs to.
        $closed = null;
        $space = self::PDF_WHITESPACE;
        $most = self::TOKEN_BYTES_MAX + 1;
        // White space and comments are stepped over without a match, which
        // would copy them; a name or a word is matched up to one byte past
        // TOKEN_BYTES_MAX, and one that long ends the reading.
        $pattern = '~\G(?:(<<)|(>>)|(\[)|(\])|(\()|(<)|/([^' . $space . '/\[\]<>(){}%]{0,' . $most . '})|([{}])|([^' . $space . '/\[\]<>(){}%]{1,' . $most . '}))~';
        $blank = "\0\t\n\x0C\r ";

        while ($at < $length) {
            if (++$state['tokens'] > self::TOKENS_MAX) {
                return false;
            }

            // White space or a comment: what came before still stands.
            $skip = strspn($bytes, $blank, $at);

            if ($skip > 0) {
                $at += $skip;
                continue;
            }

            if ('%' === $bytes[$at]) {
                $at += strcspn($bytes, "\r\n", $at);
                continue;
            }

            if (1 !== preg_match($pattern, $bytes, $token, 0, $at) || strlen($token[0]) - ('/' === $token[0][0] ? 1 : 0) > self::TOKEN_BYTES_MAX) {
                return false;
            }

            $text = $token[0];
            $next = $at + strlen($text);
            $value = null;

            $was = $closed;
            $closed = null;
            $depth = count($stack);

            if ('<<' === $text || '[' === $text) {
                if ($depth >= self::NESTING_MAX) {
                    return false;
                }

                if ('<<' === $text) {
                    $stack[] = ['dict', null, 0];

                    if (0 === $depth) {
                        $entries = [];
                        $last = null;
                    }
                } else {
                    // A list of names, kept only where takeStream() can read
                    // it: an array straight inside the outermost dictionary.
                    $stack[] = ['array', 1 === $depth && 'dict' === $stack[0][0] ? [] : null];
                }

                $at = $next;
                continue;
            }

            if ('>>' === $text) {
                $frame = array_pop($stack);

                // A key left without its value is a dictionary read wrong.
                if (!is_array($frame) || 'dict' !== $frame[0] || null !== $frame[1] || 2 === $frame[2]) {
                    return false;
                }

                $value = ['dict'];

                if ([] === $stack) {
                    $closed = $entries;
                }
            } elseif (']' === $text) {
                $frame = array_pop($stack);

                if (!is_array($frame) || 'array' !== $frame[0]) {
                    return false;
                }

                $value = ['array', is_array($frame[1]) ? $frame[1] : false];
            } elseif ('(' === $text) {
                $nest = 1;
                $next = $at + 1;

                while ($nest > 0) {
                    if ($next >= $length) {
                        return false;
                    }

                    $next += strcspn($bytes, '()\\', $next);

                    if ($next >= $length) {
                        return false;
                    }

                    $char = $bytes[$next];
                    $next += '\\' === $char ? 2 : 1;
                    $nest += '(' === $char ? 1 : ('\\' === $char ? 0 : -1);
                }

                $value = ['string'];
            } elseif ('<' === $text) {
                $end = strpos($bytes, '>', $at);

                // Checked in place: a copy of a 30 MB hex string was a copy.
                if (false === $end || strspn($bytes, '0123456789ABCDEFabcdef' . $blank, $at + 1, $end - $at - 1) !== $end - $at - 1) {
                    return false;
                }

                $next = $end + 1;
                $value = ['string'];
            } elseif (isset($token[7]) && '' !== $token[7] && '/' === $text[0]) {
                $name = self::decodeName($token[7]);

                // A key this reading depends on, spelt with #xx escapes, is
                // spelt so for no reason but to be missed.
                if ($name !== $token[7] && in_array($name, self::BOX_KEYS, true)) {
                    return false;
                }

                $top = $depth - 1;

                // A name where a dictionary waits for a key is that key. Only
                // a /First there is counted: one where a value belongs is not
                // an object stream's, and leaves the count short of the
                // bytes' (objectStreams()).
                if ($top >= 0 && 'dict' === $stack[$top][0] && null === $stack[$top][1]) {
                    // Two numbers with no "R" after them are no value.
                    if (2 === $stack[$top][2]) {
                        return false;
                    }

                    if ('First' === $name) {
                        ++$state['firsts'];
                    }

                    $stack[$top][1] = $name;
                    $stack[$top][2] = 0;
                    $at = $next;
                    continue;
                }

                $value = ['name', $name];
            } elseif ('/' === $text) {
                // The empty name.
                $value = ['name', ''];
            } elseif ('stream' === $text) {
                if ($inside || null === $was || [] !== $stack) {
                    return false;
                }

                $next = self::streamEnd($bytes, $next, $was, $start, $size);

                if (null === $next || !self::takeStream($was, $bytes, $start, $size, $state)) {
                    return false;
                }

                $at = $next;
                continue;
            } elseif ('endstream' === $text) {
                return false;
            } elseif (1 === preg_match('~^[+-]?(?:\d+\.?\d*|\.\d+)$~', $text)) {
                $value = ['number', $text];
            } elseif ('true' === $text || 'false' === $text || 'null' === $text) {
                $value = ['keyword'];
            } elseif ('R' === $text) {
                // Makes the number before it, and the one before that, a
                // reference -- which none of the keys read here may be.
                $top = $depth - 1;

                if ($top >= 0 && 'dict' === $stack[$top][0]) {
                    if (null !== $stack[$top][1] || 2 !== $stack[$top][2]) {
                        return false;
                    }

                    if (0 === $top && null !== $last && isset($entries[$last])) {
                        $entries[$last] = ['ref'];
                    }

                    $stack[$top][2] = 0;
                }

                $at = $next;
                continue;
            } elseif ([] !== $stack) {
                // Any other word -- a brace, a keyword no value is spelt with,
                // something unknown -- inside a dictionary or an array is not
                // something this reading can follow.
                return false;
            }

            if (null !== $value && [] !== $stack) {
                $top = count($stack) - 1;

                if ('dict' === $stack[$top][0]) {
                    if (null !== $stack[$top][1]) {
                        if (0 === $top) {
                            $last = $stack[$top][1];

                            if (in_array($last, self::STREAM_KEYS, true)) {
                                $entries[$last] = $value;
                            } else {
                                unset($entries[$last]);
                            }
                        }

                        $stack[$top][1] = null;
                        // A number may be the start of "12 0 R".
                        $stack[$top][2] = 'number' === $value[0] ? 1 : 0;
                    } elseif ('number' === $value[0] && 1 === $stack[$top][2]) {
                        // The "0" of "12 0 R".
                        $stack[$top][2] = 2;
                    } else {
                        // A value where a key belongs: the keys after it
                        // would be read one place out.
                        return false;
                    }
                } elseif (is_array($stack[$top][1])) {
                    // Names, a few, are what a /Filter array holds; anything
                    // else makes the array one takeStream() does not open.
                    if ('name' === $value[0] && count($stack[$top][1]) < 8) {
                        $stack[$top][1][] = $value;
                    } else {
                        $stack[$top][1] = false;
                    }
                }
            }

            $at = $next;
        }

        return [] === $stack;
    }

    /**
     * Where a stream's data ends, and where reading goes on after it
     *
     * By its /Length when that lands on "endstream", as a reader takes it;
     * otherwise at the first "endstream" after it, as one repairing the file
     * would.
     *
     * @param array<string, array<int, mixed>> $dictionary The stream's dictionary.
     * @param int|null                         $start      Set to where its data starts.
     * @param int|null                         $size       Set to its length.
     * @return int|null The offset after "endstream", or null when there is none.
     */
    private static function streamEnd(string $bytes, int $at, array $dictionary, ?int &$start = null, ?int &$size = null): ?int
    {
        // The end of line after "stream": CRLF or LF, a lone CR at worst.
        if ("\r\n" === substr($bytes, $at, 2)) {
            $at += 2;
        } elseif (isset($bytes[$at]) && ("\n" === $bytes[$at] || "\r" === $bytes[$at])) {
            ++$at;
        }

        $start = $at;
        $length = $dictionary['Length'] ?? null;

        if (is_array($length) && 'number' === $length[0] && 1 === preg_match('~^\d+$~', $length[1])) {
            $end = $at + (int) $length[1];

            if ($end <= strlen($bytes) && 1 === preg_match('~\G[' . self::PDF_WHITESPACE . ']*endstream~', $bytes, $close, 0, $end)) {
                $size = $end - $at;

                return $end + strlen($close[0]);
            }
        }

        $end = strpos($bytes, 'endstream', $at);

        if (false === $end) {
            return null;
        }

        $size = $end - $at;

        return $end + 9;
    }

    /**
     * Keep a stream objectStreams() has to open, if it is one
     *
     * Kept as where it is in the file, not as a copy: the copies, with the
     * file, came to twice its size before anything was inflated.
     *
     * @param array<string, array<int, mixed>> $dictionary The stream's dictionary.
     * @param array<string, mixed>             $state      As readTokens()'s.
     * @return bool False where the stream cannot be opened here.
     */
    private static function takeStream(array $dictionary, string $bytes, int $start, int $size, array &$state): bool
    {
        $type = $dictionary['Type'] ?? null;

        if (!isset($dictionary['First'])) {
            // An object stream that cannot be read by its /First is not read
            // here either; any other stream is not one.
            return ['name', 'ObjStm'] !== $type;
        }

        if (count($state['streams']) >= self::PAGE_TREE_MAX_NODES || isset($dictionary['DecodeParms'])) {
            return false;
        }

        $filter = $dictionary['Filter'] ?? null;

        // Not filtered: in the file as it is, and read on as part of it.
        if (null === $filter) {
            return self::readTokens(substr($bytes, $start, $size), true, $state);
        }

        if (['name', 'FlateDecode'] === $filter || ['array', [['name', 'FlateDecode']]] === $filter) {
            $state['streams'][] = [$start, $size];

            return true;
        }

        return false;
    }

    /**
     * Inflate zlib data, giving up past so many bytes
     *
     * A kilobyte at a time: gzuncompress() does not hold to its own maximum
     * length -- asked for 16 MB, it handed back all 17 MB of a 17 KB stream
     * (PHP 8.5, review after 1.4.34) -- and a kilobyte inflates to a
     * megabyte at most.
     *
     * @param string   $data   The bytes the data is in.
     * @param int      $most   Most bytes to inflate to.
     * @param int      $start  Where the data starts in $data.
     * @param int|null $length How long it is; to the end of $data when null.
     * @return string|null Null when it is not zlib data, is cut short, or
     *                     comes to more than $most bytes.
     */
    private static function inflateWithin(string $data, int $most, int $start = 0, ?int $length = null): ?string
    {
        if ($most < 1 || !function_exists('inflate_init') || !function_exists('inflate_get_status')) {
            return null;
        }

        $context = @inflate_init(ZLIB_ENCODING_DEFLATE);

        if (false === $context) {
            return null;
        }

        $text = '';
        $length = null === $length ? strlen($data) - $start : $length;

        for ($done = 0; $done < $length; $done += 1024) {
            $piece = @inflate_add($context, substr($data, $start + $done, min(1024, $length - $done)), ZLIB_SYNC_FLUSH);

            if (!is_string($piece)) {
                return null;
            }

            $text .= $piece;

            if (strlen($text) > $most) {
                return null;
            }

            if (ZLIB_STREAM_END === inflate_get_status($context)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The size of one page in points, drawn small to find out
     *
     * Ghostscript decides that size, so /Rotate, inherited boxes and UserUnit
     * come out as they will in the real read.
     *
     * @param string $target  The PDF with its "[n]".
     * @param float  $density Resolution to draw at.
     * @param int    $side    Most pixels either side may have.
     * @param float  $pixels  Most pixels it may have, where a side cannot
     *                        be limited (lowerLimits()).
     * @return array{0: float, 1: float} Width and height, a pixel more each way.
     * @throws \Throwable The read's error; a page refused by the limits says
     *                    so in ImageMagick's words (refusedAsTooLarge()).
     */
    private function measurePage(string $target, float $density, int $side, float $pixels): array
    {
        $one = null;

        try {
            self::lowerLimits($side, $pixels);
            $one = new \Imagick();
            $one->setResolution($density, $density);
            $one->readImage($target);
            $one->setIteratorIndex(0);

            // A pixel more each way: Ghostscript rounds the page to whole
            // pixels, and this must not come out smaller than the page.
            return [
                ($one->getImageWidth() + 1) * 72 / $density,
                ($one->getImageHeight() + 1) * 72 / $density,
            ];
        } finally {
            if (null !== $one) {
                $one->clear();
            }

            self::restoreLimits();
        }
    }

    /**
     * Whether a read can be held down by ImageMagick's own limits here
     */
    private static function canLimitReads(): bool
    {
        return self::hasLimits(self::$limitTypeNames) || self::hasLimits(self::$cacheTypeNames);
    }

    /**
     * Whether this build has every one of these resource limits
     *
     * @param string[] $names Imagick constant names.
     */
    private static function hasLimits(array $names): bool
    {
        foreach ($names as $name) {
            if (!defined('\Imagick::' . $name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lower ImageMagick's limits for one read, so that a page too large is refused before its pixels are held
     *
     * By width and height where this build has those limits (ImageMagick 7):
     * the page Ghostscript wrote is then refused as its header is read --
     * measured, a 300,000-point page at 2 dpi held 23 MB, against 951 MB
     * read without. Otherwise by memory and disk (ImageMagick 6), which
     * refuse the pixel cache for it. A stricter limit the site set stays in
     * force. Put back by restoreLimits(), in a finally and at shutdown.
     *
     * The limits are the process's, and so is what is kept to put them
     * back: one request at a time, as PHP-FPM, mod_php's prefork and the
     * CLI run. Under a threaded server two reads at once would lower and
     * restore each other's -- as GS_OPTIONS would (readWithGhostscriptOptions()).
     */
    private static function lowerLimits(int $side, float $pixels): void
    {
        $lower = [];

        if (self::hasLimits(self::$limitTypeNames)) {
            foreach (self::$limitTypeNames as $name) {
                $lower[$name] = $side;
            }
        } else {
            foreach (self::$cacheTypeNames as $name) {
                $lower[$name] = (int) min((float) PHP_INT_MAX, ceil($pixels * self::bytesPerPixel()));
            }
        }

        // A request that dies inside the read runs no finally, and the
        // limits would stay with this PHP worker for every request after it,
        // as GS_OPTIONS would have (see readWithGhostscriptOptions()).
        if (!self::$limitsGuarded) {
            self::$limitsGuarded = true;
            register_shutdown_function([self::class, 'restoreLimits']);
        }

        foreach ($lower as $name => $most) {
            if (!defined('\Imagick::' . $name)) {
                continue;
            }

            $type = constant('\Imagick::' . $name);
            $was = \Imagick::getResourceLimit($type);
            $was = is_numeric($was) ? (float) $was : 0.0;

            // A stricter limit the site set stays in force. For width and
            // height, 0 is the strictest, not none: measured on ImageMagick
            // 7.1.1, a width or height limit of 0 refuses every read
            // ("Invalid IHDR data"), and it was taken for none and raised for
            // the measuring read (review after 1.4.37). Memory, map and disk
            // at 0 read a page as without them, measured the same way: none.
            if (($was > 0 || (0.0 === $was && in_array($name, self::$limitTypeNames, true))) && $was <= $most) {
                continue;
            }

            // As an int: ImageMagick reports "unlimited" as 2^64, which
            // setResourceLimit() on PHP 7.4 refuses as a float past
            // PHP_INT_MAX -- and then the limit was never put back.
            self::$limitsBefore[$type] = $was >= (float) PHP_INT_MAX ? PHP_INT_MAX : (int) $was;
            \Imagick::setResourceLimit($type, $most);
        }
    }

    /** The Imagick constants a read is limited by first, by name: width and height. */
    private static array $limitTypeNames = ['RESOURCETYPE_WIDTH', 'RESOURCETYPE_HEIGHT'];

    /** The Imagick constants a read is limited by without those: the pixel cache. */
    private static array $cacheTypeNames = ['RESOURCETYPE_MEMORY', 'RESOURCETYPE_MAP', 'RESOURCETYPE_DISK'];

    /** @var array<int, int> Limits lowerLimits() lowered, as they were. */
    private static array $limitsBefore = [];

    /** Whether restoreLimits() is registered for shutdown (once a request). */
    private static bool $limitsGuarded = false;

    /** Whether a limit that could not be put back has been logged (once a request). */
    private static bool $limitsUnrestoredSaid = false;

    /**
     * Put back the limits lowerLimits() lowered, if it did
     *
     * @internal Public only for register_shutdown_function().
     */
    public static function restoreLimits(): void
    {
        foreach (self::$limitsBefore as $type => $was) {
            try {
                \Imagick::setResourceLimit($type, $was);
            } catch (\Throwable $e) {
                // A limit left lowered stays with this PHP worker for every
                // request after it: said once in the error log (review after
                // 1.4.37).
                if (!self::$limitsUnrestoredSaid) {
                    self::$limitsUnrestoredSaid = true;
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log('Rapls PDF Image Creator: an ImageMagick resource limit lowered for measuring a page could not be put back: ' . $e->getMessage());
                }
            }
        }

        self::$limitsBefore = [];
    }

    /**
     * Read one page, and read it again with Ghostscript configured differently if it comes back as one flat colour
     *
     * Until 1.4.34 there was a second rescue before this one: reading the
     * whole document and taking the page out of the sequence. It had never
     * been seen to help -- it was written after a measurement that composited
     * every page before counting colours, and turned out to be wrong -- and
     * its own docblock said to delete it if it got in the way. It did: its
     * limits came from the page count the file states, while Ghostscript
     * draws every page it finds, so a 60-page file stating one page took
     * 954 MB to read, against 70 MB for the same file stating 60 (review
     * after 1.4.33).
     *
     * @param \Imagick $imagick    Fresh instance with the resolution set.
     * @param string   $pdfPath    Absolute path.
     * @param int      $page       Page index, 0-based.
     * @param float    $resolution DPI.
     * @param bool     $empty      Set to whether the page handed back is one flat colour.
     * @return \Imagick The instance to carry on with.
     */
    private function readPage(\Imagick $imagick, string $pdfPath, int $page, float $resolution, bool &$empty = false): \Imagick
    {
        $imagick->readImage($pdfPath . '[' . $page . ']');
        $empty = $this->looksEmpty($imagick);

        // Not a PDF: a raster an add-on drew with Ghostscript and hands over
        // to be finished. The retry below is about how Ghostscript renders a
        // PDF, and run on a raster it read it again for nothing -- and wrote
        // what it found into this server's answers, so a black cover finished
        // this way told the Status tab that Ghostscript needs no
        // -dNOTRANSPARENCY here when it had been measured to.
        if (!$empty || !self::looksLikePdf($pdfPath)) {
            return $imagick;
        }

        $rescued = $this->readWithGhostscriptOptions($pdfPath, $page, $resolution);

        if (null === $rescued) {
            return $imagick;
        }

        $imagick->clear();
        $empty = false;

        return $rescued;
    }

    /**
     * Does the file start like a PDF?
     *
     * The header may sit anywhere in the first 1024 bytes, as readers allow.
     * A file that cannot be read is treated as a PDF: the read that follows
     * reports the real problem.
     */
    private static function looksLikePdf(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if (false === $handle) {
            return true;
        }

        $head = (string) fread($handle, 1024);
        fclose($handle);

        return false !== strpos($head, '%PDF-');
    }

    /**
     * Does the file start like something Ghostscript draws -- PDF, PostScript, EPS?
     *
     * A PDF's header may sit anywhere in the first 1024 bytes, as readers
     * allow; so may PostScript's "%!", after a Ctrl-D, white space or a
     * printer's job header. A DOS EPS starts with its binary header. And a
     * file named as one of these is taken as one, whatever its bytes. A file
     * that cannot be read is taken as one: bounded, and the read that
     * follows reports the real problem.
     *
     * Measuring bounds a PDF page. A PostScript program can ask its page
     * size after the resolution (HWResolution, setpagedevice) and so be
     * small when measured and large when drawn; measured all the same, as
     * that is no worse than unbounded, which it was up to 1.4.37.
     */
    private static function drawnByGhostscript(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if (false === $handle) {
            return true;
        }

        $head = (string) fread($handle, 1024);
        fclose($handle);

        // By the name first: ImageMagick goes by it as well as by the bytes,
        // so a file named .pdf whose header comes after the first 1024 bytes,
        // or PostScript with no header at all, is drawn by Ghostscript too,
        // and each was read unbounded (review of 1.4.38's fixes).
        if (1 === preg_match('/\.(?:pdf|ps|eps|epsf|epsi|ai)\z/i', $path)) {
            return true;
        }

        // PostScript and PDF begin with "%": at the start, or after a byte
        // order mark, white space, a Ctrl-D or a printer's job header (PJL's
        // "\x1B%-12345X" and its "@PJL" lines), as a file printed to disk
        // opens. Asked before the rasters' magic: "%!PSftyp" is PostScript,
        // not ISO media.
        if (1 === preg_match('/\A(?:\xEF\xBB\xBF|[\s\x04]|\x1B%-12345X|@PJL[^\n]*\n)*%/', $head)) {
            return true;
        }

        // Every other file is bounded unless it starts like a raster
        // ImageMagick reads by itself: drawn by Ghostscript or not, a file
        // that could be is measured first, as one that cannot be read is
        // (review of 1.4.38's fixes: "%!" after a comment line, a PDF header
        // past the first 1024 bytes). The rasters are let past, as up to
        // 1.4.37 -- bounded as a page, one past 6,325 pixels a side was
        // refused for good, which a list of a few let past did to BigTIFF,
        // HEIC, AVIF and JPEG 2000 (review after 1.4.38) -- and a PNG's text
        // chunk holding "%!" no longer makes one look like PostScript.
        return !self::startsLikeRaster($head, $path);
    }

    /**
     * Does the file start like a raster ImageMagick reads without Ghostscript?
     *
     * PNG, PNM and PAM, PFM, JPEG, JPEG 2000 (file or codestream), JPEG XL
     * (file or codestream), TIFF and BigTIFF, GIF, BMP, WebP, ISO media's
     * "ftyp" (HEIC, AVIF), Photoshop, Windows icons, and TGA, which has no
     * magic: by its footer, or a header of the shape every TGA has. And the
     * other rasters ImageMagick reads by itself, each by a magic its format
     * fixes: PCX, OpenEXR, MIFF, DPX, DDS, FITS, Radiance HDR, SGI, XPM, XBM,
     * GIMP's XCF, QOI, and DICOM ("DICM" after its 128-byte preamble). Left
     * out of the list, any of them was bounded as a page and refused for
     * good past 6,325 pixels a side, which 1.4.20 read (review of 1.4.38's
     * fixes). PICT (a 512-byte preamble, then nothing fixed) and WBMP (no
     * magic) are not told apart: bounded, the safe side.
     */
    private static function startsLikeRaster(string $head, string $path): bool
    {
        if (1 === preg_match('/\A(?:\x89PNG\r\n\x1A\n|P[1-7Ff]\s|\xFF\xD8\xFF|\x00\x00\x00\x0CjP  \r\n\x87\n|\xFF\x4F\xFF\x51|\xFF\x0A|\x00\x00\x00\x0CJXL \r\n\x87\n|II\*\x00|MM\x00\*|II\+\x00|MM\x00\+|GIF8[79]a|BM|RIFF....WEBP|....ftyp|8BPS|\x00\x00[\x01\x02]\x00)/s', $head)) {
            return true;
        }
        if (1 === preg_match('/\A(?:\x0A[\x00\x02-\x05]\x01[\x01\x02\x04\x08]|\x76\x2F\x31\x01|id=ImageMagick|SDPX|XPDS|DDS \x7C\x00\x00\x00|SIMPLE  = {20}T|#\?(?:RADIANCE|RGBE)\n|\x01\xDA[\x00\x01][\x01\x02]|\/\* XPM \*\/|#define [^\s]+_width |gimp xcf |qoif)/', $head)) {
            return true;
        }
        if (strlen($head) >= 132 && 'DICM' === substr($head, 128, 4)) {
            return true;
        }

        // TGA: "TRUEVISION-XFILE.\0" in its last 18 bytes (TGA 2.0), or an
        // 18-byte header of TGA's shape -- a colour map type of 0 or 1, an
        // image type TGA defines, a colour map entry and a pixel depth of the
        // sizes it allows. Looser, a run of 0x01 bytes before a PDF header
        // read as a TGA and went unbounded.
        if (strlen($head) >= 18) {
            $map = ord($head[1]);
            $type = ord($head[2]);
            $depth = ord($head[16]);
            if (($map <= 1) && in_array($type, [1, 2, 3, 9, 10, 11], true) && in_array($depth, [8, 15, 16, 24, 32], true)
                && (0 === $map || in_array(ord($head[7]), [15, 16, 24, 32], true))) {
                return true;
            }
        }
        $size = @filesize($path);
        if (is_int($size) && $size >= 18) {
            $tail = @file_get_contents($path, false, null, $size - 18, 18);
            if (is_string($tail) && 'TRUEVISION-XFILE.' === substr($tail, 0, 17)) {
                return true;
            }
        }

        return false;
    }

    /** GS_OPTIONS as it was before the retry set it; null when it was unset. */
    private static ?string $gsOptionsBefore = null;

    /** Whether the retry's GS_OPTIONS is in the environment now. */
    private static bool $gsOptionsSet = false;

    /** Whether restoreGsOptions() is registered for shutdown (once a request). */
    private static bool $gsOptionsGuarded = false;

    /**
     * Put GS_OPTIONS back as it was before the retry, if the retry set it
     *
     * Restore rather than clear: this environment outlives the request in a
     * persistent worker, and something else may be relying on a value the
     * site set deliberately.
     *
     * @internal Public only for register_shutdown_function().
     */
    public static function restoreGsOptions(): void
    {
        if (!self::$gsOptionsSet) {
            return;
        }

        self::$gsOptionsSet = false;

        if (null !== self::$gsOptionsBefore) {
            putenv('GS_OPTIONS=' . self::$gsOptionsBefore);
        } else {
            putenv('GS_OPTIONS');
        }
    }

    /**
     * Read the page again with GS_OPTIONS set
     *
     * Ghostscript 9.27 throws `/typecheck in /--.pdfexectoken--` on an image
     * XObject inside a transparency group, reports it on a stderr nobody
     * reads, and hands back a correctly sized white page. `-dNOTRANSPARENCY`
     * stops it. Measured on Xserver sv17007 with a PowerPoint export: 11,018
     * bytes of white without the flag, 624,109 bytes of picture with it, on
     * every Ghostscript device tried (png16m, pngalpha, pamcmyk32).
     *
     * There is no way to hand ImageMagick an extra Ghostscript switch. That
     * build is compiled `--with-gslib`, so it calls Ghostscript as a linked
     * library and assembles the argument list in its own C code: editing
     * `delegates.xml` changed the output of `convert -list delegate` and
     * changed nothing about the render, and `-debug delegate` logged no
     * external command at all. What is left is `GS_OPTIONS`, which
     * Ghostscript reads from the environment while initialising, and which
     * therefore reaches the library call as readily as the command line.
     * Measured through PHP-FPM on that host: blank without it, drawn with it.
     *
     * **`putenv()` is not `exec()`.** Nothing here starts a process. A string
     * is left in this process's own environment for the length of one read
     * and put back afterwards, and whether Ghostscript runs at all stays
     * ImageMagick's decision, as the constraints in CLAUDE.md require.
     *
     * It is a retry and never the first attempt, because `-dNOTRANSPARENCY`
     * costs something real: a PDF that uses transparency correctly renders
     * differently with it. A page that already has content never gets here.
     *
     * @return \Imagick|null Null when the retry is unavailable or no better.
     */
    private function readWithGhostscriptOptions(string $pdfPath, int $page, float $resolution): ?\Imagick
    {
        if (!$this->canSetEnvironment()) {
            return null;
        }

        /**
         * Filter the Ghostscript options a blank page is retried with.
         *
         * Return an empty string to skip the retry entirely.
         *
         * @since 1.4.0
         *
         * @param string $flags   Value for the GS_OPTIONS environment variable.
         * @param string $pdfPath Absolute path to the PDF being read.
         */
        $flags = apply_filters(
            'rapls_pdf_image_creator_ghostscript_options',
            '-dNOTRANSPARENCY',
            $pdfPath
        );

        if (!is_string($flags)) {
            return null;
        }

        $flags = trim($flags);

        // Only what a Ghostscript switch is made of, as since 1.4.0. A newline
        // in here would be a second environment entry.
        if ('' === $flags || !preg_match('#\A[-A-Za-z0-9_=.,:/ ]+\z#', $flags)) {
            return null;
        }

        // Then only switches that set a value (-dNAME, -dNAME=value,
        // -sNAME=value), and none that loosens Ghostscript's sandbox, sends
        // its output elsewhere or draws past the bound on the page.
        // -dNOSAFER passed straight through, and the retry ran a file
        // uploaded by anyone who can add media with Ghostscript's file access
        // open (review after 1.4.34). One such switch is dropped and the rest
        // kept: refusing the whole value turned the retry off for a site that
        // had returned "-dNOTRANSPARENCY -dMaxBitmap=500000000", which 1.4.20
        // took, and its blank pages stayed blank without a word (review after
        // 1.4.36). Said in the error log, once per value in a request.
        $kept = self::safeGhostscriptOptions($flags);

        if ($kept !== $flags) {
            static $said = [];
            if (!isset($said[$flags])) {
                $said[$flags] = true;
                // The switches' names, never their values: a value can be a
                // path on this server, and debug.log can be read from the web.
                // Only a -dNAME or -sNAME is named; anything else -- "-I",
                // "/home/u/fonts", "-c", "quit" -- is counted as other, since
                // a value can come as a word of its own (review after 1.4.36).
                $names = [];
                foreach (array_diff(explode(' ', $flags), explode(' ', $kept)) as $dropped) {
                    if ('' === $dropped) {
                        continue;
                    }
                    $names[] = 1 === preg_match('/\A-[dDsS][A-Za-z][A-Za-z0-9_]*/', $dropped, $name) ? $name[0] : '(other)';
                }
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('Rapls PDF Image Creator: switches left out of the blank-page retry\'s Ghostscript options, as unsafe: ' . implode(' ', array_unique($names)));
            }
        }

        $flags = $kept;

        // Nothing left but -q and SAFER, in any case, as the switch is read
        // in any case above: no retry (review after 1.4.37).
        if ('' === trim((string) preg_replace('/(?:^| )(?:-q|-[dD](?i:SAFER))(?= |$)/', ' ', $flags))) {
            return null;
        }

        // The process's own environment, not the server's request variables:
        // under FPM a value given only as a FastCGI parameter was read here
        // and then written into the worker's environment as "the previous
        // one", where it stayed for every later request (review of 1.4.32).
        $previous = getenv('GS_OPTIONS', true);
        $one = null;

        try {
            // Added to what the site set, not in its place: a value set on
            // purpose -- a font path, a device option -- went missing from
            // the retry (review after 1.4.31). In a threaded server the
            // environment is the process's, shared by every thread for the
            // length of this read; PHP's usual servers run one request a
            // process.
            self::$gsOptionsBefore = is_string($previous) && '' !== $previous ? $previous : null;
            self::$gsOptionsSet = true;

            // A request that dies inside the read -- max_execution_time on a
            // long render -- runs no finally, and the switch stayed in this
            // worker's environment for every request after it, making a
            // workaround the first attempt for every PDF (review after
            // 1.4.32). Shutdown functions still run after that fatal error.
            if (!self::$gsOptionsGuarded) {
                self::$gsOptionsGuarded = true;
                register_shutdown_function([self::class, 'restoreGsOptions']);
            }

            putenv('GS_OPTIONS=' . trim((is_string($previous) ? $previous : '') . ' ' . $flags));
            $one = new \Imagick();
            $one->setResolution($resolution, $resolution);
            $one->readImage($pdfPath . '[' . $page . ']');
        } catch (\Throwable $e) {
            $one = null;
        } finally {
            self::restoreGsOptions();
        }

        if (null === $one) {
            return null;
        }

        if ($this->looksEmpty($one)) {
            $one->clear();

            // Nothing is learnt about the server from a page that is blank
            // either way, and "no" written over a "yes" took the Status tab's
            // "Needed a workaround" away from a server that does need it
            // (review after 1.4.31). Only "yes" is read.
            return null;
        }

        // Worth remembering, and worth saying on the Status tab: this host
        // ships a Ghostscript that cannot render some files unaided.
        set_transient(self::GS_OPTIONS_TRANSIENT, 'yes', WEEK_IN_SECONDS);

        return $one;
    }

    /**
     * The switches in this string that set a value and are not dangerous ones, spaced as they came
     *
     * Taken: -q, -dSAFER, and -dNAME, -dNAME=value, -sNAME=value for any
     * other name. -D and -S are Ghostscript's -d and -s, and names are
     * refused here in any case. Refused, with the reason:
     *
     * - NOSAFER, DELAYSAFER, DOPS, SAFER given a value, Permit*: they open
     *   Ghostscript's file access to the PDF, which anyone who can add
     *   media chose.
     * - OutputFile, DEVICE, stdout, stderr: they send its output elsewhere.
     * - Any name with BOX, PAPER, MEDIA, FIT, RESOLUTION, SCALE or CROP in
     *   it, or starting DEVICE or HW -- DEVICEWIDTH, FIXEDMEDIA, PAPERSIZE,
     *   DEFAULTPAPERSIZE, PDFFitPage, UseCropBox, DownScaleFactor, HWSize,
     *   EPSCrop (an EPS page as its bounding box, since 1.4.39 measured)
     *   -- and -r, -g: they change the page's size in pixels, or what is
     *   drawn to make it, after it was bounded (boundedResolution()).
     *   "-sDEFAULTPAPERSIZE=a0 -dPDFFitPage" drew a Letter page at 4967 by
     *   7021 pixels at 150 dpi (review after 1.4.36).
     * - WRITESYSTEMDICT, DELAYBIND, NOOUTERSAVE: they loosen what SAFER
     *   protects. FirstPage, LastPage, PageList: they choose another page
     *   than the one asked for and measured.
     * - Any name with BUFFER, BITMAP, BAND or THREAD in it, and any with
     *   INTERPOLATE but NOINTERPOLATE -- BufferSpace, MaxBitmap,
     *   BandHeight, NumRenderingThreads, DOINTERPOLATE: they raise how much
     *   memory Ghostscript holds or how much it draws, past the bound on
     *   the page. MaxBitmap was taken until the review after 1.4.36.
     * - -I, any -s or -d whose name ends in Dir, Path, Profile, File or
     *   Map --
     *   FONTPATH, GenericResourceDir, ICCProfilesDir, DefaultRGBProfile,
     *   FONTMAP, CIDFMAP -- any value with a "/" in it, and any name with
     *   ICC or SUBST in it (SourceObjectICC, CIDFSubstFont): they point
     *   Ghostscript at files on this server (reviews after 1.4.34 and
     *   1.4.36). A switch is refused by what it is, not looked up in a list
     *   of the dangerous ones: each list so far missed some.
     *
     * -dSAFER itself and -q were refused in the first draft of this, and a
     * site handing "-dSAFER -dNOTRANSPARENCY" lost the retry altogether
     * (review after 1.4.34).
     */
    private static function safeGhostscriptOptions(string $flags): string
    {
        $kept = [];

        foreach (explode(' ', $flags) as $flag) {
            if (self::safeGhostscriptSwitch($flag)) {
                $kept[] = $flag;
            }
        }

        return trim(implode(' ', $kept));
    }

    /**
     * Whether one switch is taken (safeGhostscriptOptions()); '' is, as spacing
     */
    private static function safeGhostscriptSwitch(string $flag): bool
    {
        if ('' === $flag || '-q' === $flag) {
            return true;
        }

        if (!preg_match('#\A-([dDsS])([A-Za-z][A-Za-z0-9_]*)(=[-A-Za-z0-9_.,:/]+)?\z#', $flag, $part)) {
            return false;
        }

        $name = strtoupper($part[2]);

        if ('SAFER' === $name) {
            return !isset($part[3]);
        }

        if (in_array($name, ['NOSAFER', 'DELAYSAFER', 'DOPS', 'OUTPUTFILE', 'DEVICE', 'STDOUT', 'STDERR', 'FIXEDMEDIA', 'FIXEDRESOLUTION', 'PAPERSIZE', 'WRITESYSTEMDICT', 'DELAYBIND', 'NOOUTERSAVE', 'FIRSTPAGE', 'LASTPAGE', 'PAGELIST'], true)
            || preg_match('/(?:DIR|PATH|PROFILE|FILE)\z/', $name)
            || preg_match('/MAP\z/', $name)
            || preg_match('/BUFFER|BITMAP|BAND|THREAD/', $name)
            || (false !== strpos($name, 'INTERPOLATE') && 'NOINTERPOLATE' !== $name)
            || 0 === strpos($name, 'PERMIT')
            || 0 === strpos($name, 'DEVICE')
            || 0 === strpos($name, 'HW')
            || preg_match('/BOX|PAPER|MEDIA|FIT|RESOLUTION|SCALE|ICC|SUBST|CROP/', $name)
            || (isset($part[3]) && false !== strpos($part[3], '/'))) {
            return false;
        }

        return true;
    }

    /**
     * May this process change its own environment?
     *
     * `putenv` is on more than one host's `disable_functions`, and a plugin
     * that assumes otherwise is a fatal error on those hosts.
     */
    private function canSetEnvironment(): bool
    {
        if (!function_exists('putenv') || !function_exists('getenv')) {
            return false;
        }

        // getenv() too: on PHP 7.4 a disabled function still exists, and
        // answers null with a warning -- read as "unset", and the value the
        // site had set was taken away by the put-back (review after 1.4.37).
        $disabled = (string) ini_get('disable_functions');

        return !self::isListedDisabled('putenv', $disabled) && !self::isListedDisabled('getenv', $disabled);
    }

    /**
     * Is this name on a disable_functions list, read the way PHP reads it?
     *
     * PHP splits the list on spaces and commas only, and takes each name as
     * written. This split on commas alone and lowered the case: "exec putenv"
     * was one name that disabled nothing here, and "PUTENV" -- which PHP does
     * not disable -- turned the blank-page retry off.
     */
    private static function isListedDisabled(string $function, string $list): bool
    {
        foreach ((array) preg_split('/[ ,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $name) {
            if ($function === (string) $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is a page already found to be one flat colour (looksEmpty()) white?
     *
     * Uniform is not the same as blank: a page of solid ink is one colour and
     * is a legitimate page. Only white counts here. The flatness is the
     * caller's to know: asked again here, the whole raster was scanned a
     * third time for every page with anything on it (review after 1.4.33).
     */
    private function looksWhite(\Imagick $imagick): bool
    {
        try {
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            if ($width < 1 || $height < 1) {
                return true;
            }

            // Sample after converting, because a CMYK raster reports white as
            // 0,0,0,0 and an sRGB one as 65535 -- the same page, two numbers.
            $probe = clone $imagick;

            try {
                $probe->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            } catch (\Throwable $e) {
                // Judge on whatever space it is in.
            }

            $pixel = $probe->getImagePixelColor((int) ($width / 2), (int) ($height / 2));

            if (!$pixel instanceof \ImagickPixel) {
                $probe->clear();

                return false;
            }

            $rgb = $pixel->getColor();
            // Transparent is white here: the page is flattened onto white,
            // and an unpainted page under a transparent colour other than
            // white was not taken for blank (review after 1.4.31).
            $alpha = defined('\Imagick::COLOR_ALPHA') && method_exists($pixel, 'getColorValue') ? (float) $pixel->getColorValue(\Imagick::COLOR_ALPHA) : 1.0;
            $probe->clear();

            return $alpha < 0.01 || ($rgb['r'] > 250 && $rgb['g'] > 250 && $rgb['b'] > 250);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Is every pixel the same colour?
     *
     * Uses the channel range rather than sampling: min equals max means one
     * value, exactly, with no grid coarse enough to miss a detail in a corner.
     * Cheap, because ImageMagick keeps these statistics anyway.
     */
    private function looksEmpty(\Imagick $imagick): bool
    {
        try {
            if ($imagick->getImageWidth() < 1 || $imagick->getImageHeight() < 1) {
                return true;
            }

            if (!method_exists($imagick, 'getImageChannelRange')) {
                return false;
            }

            // Transparency apart from colour. An unpainted page read through
            // pngalpha is transparent white, and on ImageMagick 7 its range
            // over every channel at once is 0 to 65535 -- colour 65535,
            // alpha 0 -- so it never read as one colour, and a blank page was
            // kept as a thumbnail with none of the retries below (review
            // after 1.4.31; measured on 7.1.1-15). Uniform transparency and
            // uniform colour is one flat colour, whichever way a version
            // stores the alpha.
            if (defined('\Imagick::CHANNEL_ALPHA') && method_exists($imagick, 'getImageAlphaChannel') && $imagick->getImageAlphaChannel()) {
                if (!self::oneValue($imagick->getImageChannelRange(\Imagick::CHANNEL_ALPHA))) {
                    return false;
                }

                return self::oneValue($imagick->getImageChannelRange(\Imagick::CHANNEL_ALL & ~\Imagick::CHANNEL_ALPHA));
            }

            return self::oneValue($imagick->getImageChannelRange(\Imagick::CHANNEL_ALL));
        } catch (\Throwable $e) {
            // Cannot tell. Say no, so nothing is re-read on a guess.
            return false;
        }
    }

    /**
     * Whether a channel range is a single value
     *
     * @param mixed $range getImageChannelRange()'s answer.
     */
    private static function oneValue($range): bool
    {
        if (!is_array($range) || !isset($range['minima'], $range['maxima'])) {
            return false;
        }

        return abs((float) $range['maxima'] - (float) $range['minima']) < 1e-9;
    }

    /**
     * Warn about CMYK PDFs on ImageMagick 6
     *
     * ImageMagick 6 renders a PDF it judges to be CMYK through its ps:cmyk
     * delegate, which asks Ghostscript for the bmpsep8 device. That writes a
     * separation BMP, and ImageMagick's own BMP reader cannot decode it: the
     * read either throws or yields an empty raster, so the thumbnail comes out
     * blank white. ImageMagick 7 uses pamcmyk32 instead and is unaffected.
     *
     * Nothing in the Imagick API overrides the delegate choice — the device is
     * picked from the PDF's own content — so this reports the problem rather
     * than working around it.
     *
     * @return array{name: string, status: bool, message: string, detail: string}|null
     *         Null when the running ImageMagick is not affected.
     */
    private function getCmykPdfStatus(): ?array
    {
        $major = $this->getImageMagickMajorVersion();

        if (null === $major || $major >= 7) {
            return null;
        }

        // Warning on the version number alone turned out to be too broad.
        // Measured on Xserver's ImageMagick 6.9.13-25: a DeviceCMYK page
        // renders correctly, colorspace and all. So render one and look.
        $probe = $this->probeCmykRender();
        $device = $this->getCmykDelegateDevice();

        // "some ImageMagick 6 builds" is a sentence nobody can act on. The
        // device name turns it into a request a hosting provider can carry
        // out, and tells two servers running the same version number apart.
        $deviceNote = '';
        if (null !== $device) {
            $deviceNote = ' ' . sprintf(
                /* translators: %s: a Ghostscript output device name, e.g. bmpsep8 */
                __('This build renders CMYK PDFs through the %s device.', 'rapls-pdf-image-creator'),
                $device
            );

            if ('bmpsep8' === $device) {
                $deviceNote .= ' ' . __('That is the one ImageMagick cannot read back.', 'rapls-pdf-image-creator');
            }
        }

        if (true === $probe['ok']) {
            return [
                'name' => __('CMYK PDF Rendering', 'rapls-pdf-image-creator'),
                'status' => true,
                'message' => __('Working on this server (tested)', 'rapls-pdf-image-creator'),
                'detail' => __('ImageMagick 6 renders some CMYK PDFs as a blank image. A test page rendered correctly here, so the common case works. Complex print-ready files (PDF/X) can still take a different path — check one of your own if you rely on them.', 'rapls-pdf-image-creator') . $deviceNote,
            ];
        }

        if (false === $probe['ok']) {
            return [
                'name' => __('CMYK PDF Rendering', 'rapls-pdf-image-creator'),
                'status' => false,
                'message' => __('Broken on this server (tested)', 'rapls-pdf-image-creator'),
                'detail' => __('A CMYK test page came back blank. Ask your hosting provider to update ImageMagick to version 7. Changing the ps:cmyk delegate from bmpsep8 to pamcmyk32 helps only where ImageMagick runs Ghostscript as a program; where it calls Ghostscript as a library, editing delegates.xml has no effect. RGB PDFs are not affected.', 'rapls-pdf-image-creator') . $deviceNote,
            ];
        }

        // Could not test. Say so rather than guessing either way -- and say
        // why, which until 1.4.0 this branch did not do. It captured the
        // reason and then threw it away, so the one answer that means "I do
        // not know" was also the one that gave the reader nothing to go on.
        $detail = __('RGB PDFs are not affected.', 'rapls-pdf-image-creator') . $deviceNote;

        if ('' !== $probe['error']) {
            $detail = sprintf(
                /* translators: %s: the error ImageMagick or PHP reported */
                __('The test could not be run, so this is a guess based on the version number rather than a measurement. What stopped it: %s', 'rapls-pdf-image-creator'),
                $probe['error']
            ) . ' ' . $detail;
        }

        return [
            'name' => __('CMYK PDF Rendering', 'rapls-pdf-image-creator'),
            'status' => false,
            'message' => __('ImageMagick 6 — CMYK PDFs may produce a blank image (not tested)', 'rapls-pdf-image-creator'),
            'detail' => $detail,
        ];
    }

    /**
     * Render a CMYK page and see whether anything survives
     *
     * ImageMagick 6 hands CMYK PDFs to Ghostscript's bmpsep8 device and then
     * cannot read what comes back, which lands as a blank white thumbnail.
     * Whether that happens depends on the build, so measure instead of
     * reading the version number: fill a page with 100% cyan and look at it.
     *
     * @return array{ok: bool|null, error: string} ok is null when untestable.
     */
    private function probeCmykRender(): array
    {
        $version = $this->getVersionString();

        $cached = get_transient(self::CMYK_PROBE_TRANSIENT);
        if (is_array($cached) && array_key_exists('ok', $cached) && isset($cached['version'])
            && $cached['version'] === $version) {
            return ['ok' => $cached['ok'], 'error' => (string) ($cached['error'] ?? '')];
        }

        $result = ['ok' => null, 'error' => ''];

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(72, 72);
            $imagick->readImageBlob(self::minimalCmykPdf(), 'rapls-pic-cmyk-probe.pdf');

            // Sample in sRGB: read straight out of a CMYK raster, 100% cyan
            // reports as r=255, which is indistinguishable from red.
            try {
                $imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            } catch (\Throwable $e) {
                // Keep whatever space it came back in and judge on that.
            }

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            // A read that comes back with no raster is not an inconclusive
            // test, it is the failure this probe exists to find: ImageMagick 6
            // handed a separation BMP it cannot decode sometimes throws and
            // sometimes returns an empty image, and the empty image is what
            // becomes a blank white thumbnail.
            if ($width < 1 || $height < 1) {
                $imagick->clear();
                $result['ok'] = false;
                $result['error'] = sprintf('empty raster (%dx%d)', $width, $height);

                set_transient(
                    self::CMYK_PROBE_TRANSIENT,
                    $result + ['version' => $version],
                    12 * HOUR_IN_SECONDS
                );

                return $result;
            }

            $pixel = $imagick->getImagePixelColor((int) ($width / 2), (int) ($height / 2));

            // Older Imagick builds return false here rather than throwing.
            // Calling getColor() on that is a PHP Error, not an Exception, so
            // it fell past the catch that would have called this a failure and
            // landed in the one that says "could not test".
            if (!$pixel instanceof \ImagickPixel) {
                $imagick->clear();
                $result['ok'] = false;
                $result['error'] = 'getImagePixelColor() returned no pixel';

                set_transient(
                    self::CMYK_PROBE_TRANSIENT,
                    $result + ['version' => $version],
                    12 * HOUR_IN_SECONDS
                );

                return $result;
            }

            $rgb = $pixel->getColor();
            $imagick->clear();

            // A page filled edge to edge cannot legitimately be white or black.
            $blank = ($rgb['r'] > 240 && $rgb['g'] > 240 && $rgb['b'] > 240)
                || ($rgb['r'] < 15 && $rgb['g'] < 15 && $rgb['b'] < 15);

            $result['ok'] = !$blank;
            $result['error'] = sprintf('#%02X%02X%02X', $rgb['r'], $rgb['g'], $rgb['b']);
        } catch (\Exception $e) {
            // ImageMagick refused the file. That is a real answer.
            $result['ok'] = false;
            $result['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            // Something below ImageMagick broke -- a build without
            // readImageBlob, a fatal in the extension. Not evidence that CMYK
            // is broken, so say nothing rather than say the wrong thing.
            $result['ok'] = null;
            $result['error'] = $e->getMessage();
        }

        set_transient(
            self::CMYK_PROBE_TRANSIENT,
            $result + ['version' => $version],
            12 * HOUR_IN_SECONDS
        );

        return $result;
    }

    /**
     * Can this server render a page other than the first?
     *
     * The setting to pick a page has been here since the beginning, and until
     * now nothing checked that it worked. A site owner who sets page 3 and
     * gets page 1 has no way to tell whether the setting is broken, the PDF is
     * odd, or the server is.
     *
     * Two pages, one white and one black, each read the way a conversion
     * reads a page: from a file, with the page number on the end. If both
     * come back the same colour, the number was ignored.
     *
     * From a file because readImageBlob() ignores a page number on the name
     * it is given and returns every page. Until 1.4.34 this read the blob
     * whole and stepped through the pages, which showed that Ghostscript can
     * draw two different pages -- and said nothing about the page number,
     * which is what this row is about (review after 1.4.33).
     *
     * @return array{ok: bool|null, error: string}
     */
    private function probePageSelection(): array
    {
        $version = $this->getVersionString();

        $cached = get_transient(self::PAGE_PROBE_TRANSIENT);
        if (is_array($cached) && array_key_exists('ok', $cached) && isset($cached['version'])
            && $cached['version'] === $version) {
            return ['ok' => $cached['ok'], 'error' => (string) ($cached['error'] ?? '')];
        }

        // null means "could not be tested", which is not the same as "does not
        // work" and must not be shown as though it were.
        $result = ['ok' => null, 'error' => ''];
        $file = '';

        try {
            $file = self::probeFile(self::twoPagePdf());
            $signatures = [];

            foreach ([0, 1] as $index) {
                $page = new \Imagick();
                $page->setResolution(36, 36);
                // A build that ignores the number hands back every page, and
                // does so for both reads: the two then come out the same,
                // which is the answer this check exists to give.
                $page->readImage($file . '[' . $index . ']');

                // Flatten first, as the conversion does. A page arrives
                // from the PDF delegate with an alpha channel, and an
                // unpainted area sampled straight out of that raster is
                // transparent black -- the same #000000 as a page painted
                // black.
                $page->setImageBackgroundColor(new \ImagickPixel('white'));

                if (method_exists($page, 'mergeImageLayers')) {
                    $flat = $page->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                    $page->clear();
                    $page = $flat;
                }

                $pixel = $page->getImagePixelColor(
                    (int) ($page->getImageWidth() / 2),
                    (int) ($page->getImageHeight() / 2)
                );
                $rgb = $pixel->getColor();
                $signatures[$index] = sprintf('%02X%02X%02X', $rgb['r'], $rgb['g'], $rgb['b']);

                $page->clear();
            }

            $result['ok'] = $signatures[0] !== $signatures[1];
            $result['error'] = $signatures[0] . ' / ' . $signatures[1];
        } catch (\Throwable $e) {
            // On a server with no PDF support this is the expected answer, and
            // the PDF Support row has already said so. Nothing to add.
            $result['ok'] = null;
            $result['error'] = $e->getMessage();
        } finally {
            if ('' !== $file) {
                @unlink($file);
                unset(self::$probeFiles[$file]);
            }
        }

        set_transient(
            self::PAGE_PROBE_TRANSIENT,
            $result + ['version' => $version],
            12 * HOUR_IN_SECONDS
        );

        return $result;
    }

    /**
     * Write a test PDF to a file of its own in the temporary folder
     *
     * Created, not opened: a name someone else made first -- a link left in
     * a shared /tmp -- is refused rather than written through.
     *
     * @param string      $pdf  The document.
     * @param string|null $file Where to write it; a fresh name in the
     *                          temporary folder unless a test says.
     * @return string The path; the caller deletes it.
     * @throws \RuntimeException When it cannot be written.
     */
    private static function probeFile(string $pdf, ?string $file = null): string
    {
        if (null === $file) {
            $dir = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
            $file = rtrim($dir, '/\\') . '/rapls-pic-page-probe-' . bin2hex(random_bytes(8)) . '.pdf';
        }
        $handle = @fopen($file, 'xb');

        if (false === $handle) {
            throw new \RuntimeException('The test file could not be written to the temporary folder.');
        }

        // A request that dies inside the read -- max_execution_time on a slow
        // Ghostscript -- runs no finally, and the file stayed in the
        // temporary folder for good (review after 1.4.34).
        self::$probeFiles[$file] = true;

        if (!self::$probeFilesGuarded) {
            self::$probeFilesGuarded = true;
            register_shutdown_function([self::class, 'removeProbeFiles']);
        }

        $written = fwrite($handle, $pdf);
        fclose($handle);

        if (strlen($pdf) !== $written) {
            @unlink($file);
            unset(self::$probeFiles[$file]);

            throw new \RuntimeException('The test file could not be written to the temporary folder.');
        }

        return $file;
    }

    /** @var array<string, true> Test files written and not yet deleted, by path. */
    private static array $probeFiles = [];

    /** Whether removeProbeFiles() is registered for shutdown (once a request). */
    private static bool $probeFilesGuarded = false;

    /**
     * Delete the test files a request left behind
     *
     * @internal Public only for register_shutdown_function().
     */
    public static function removeProbeFiles(): void
    {
        foreach (array_keys(self::$probeFiles) as $file) {
            @unlink($file);
        }

        self::$probeFiles = [];
    }

    /**
     * Two pages that cannot be confused with each other
     *
     * Page one is left white, page two is filled black edge to edge. Built
     * rather than bundled, for the same reason as the other probes: no file to
     * ship and no question about its licence.
     */
    private static function twoPagePdf(): string
    {
        // Both pages are painted. Leaving the first one empty and relying on
        // it being white is what made this probe wrong: an unpainted page is
        // transparent, not white, and the two only differ once something has
        // decided what transparent means.
        $white = "1.0 g\n0 0 72 72 re\nf\n";
        $black = "0.0 g\n0 0 72 72 re\nf\n";

        return self::buildPdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 72 72] /Resources << >> /Contents 5 0 R >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 72 72] /Resources << >> /Contents 6 0 R >>',
            '<< /Length ' . strlen($white) . " >>\nstream\n" . $white . 'endstream',
            '<< /Length ' . strlen($black) . " >>\nstream\n" . $black . 'endstream',
        ]);
    }

    /**
     * The Status tab row for page selection
     *
     * @return array{name: string, status: bool, message: string, detail: string}|null
     */
    private function getPageSelectionStatus(): ?array
    {
        $probe = $this->probePageSelection();

        if (null === $probe['ok']) {
            // Untestable. The PDF Support row has already explained why.
            return null;
        }

        if ($probe['ok']) {
            return [
                'name' => __('Page Selection', 'rapls-pdf-image-creator'),
                'status' => true,
                'message' => __('Working on this server (tested)', 'rapls-pdf-image-creator'),
                'detail' => __('A thumbnail can be made from any page, not only the first. Set the page on the Settings tab.', 'rapls-pdf-image-creator'),
            ];
        }

        return [
            'name' => __('Page Selection', 'rapls-pdf-image-creator'),
            'status' => false,
            'message' => __('Not working on this server (tested)', 'rapls-pdf-image-creator'),
            'detail' => __('Two different pages of a test file rendered identically, so the page number setting will have no effect here. The first page still works.', 'rapls-pdf-image-creator'),
        ];
    }

    /**
     * The Status tab row for the Ghostscript blank-page workaround
     *
     * Only appears once it has actually been needed. Nobody wants a row
     * about a fault their server does not have.
     *
     * @return array{name: string, status: bool, message: string, detail: string}|null
     */
    private function getGhostscriptWorkaroundStatus(): ?array
    {
        if ('yes' !== get_transient(self::GS_OPTIONS_TRANSIENT)) {
            return null;
        }

        return [
            'name' => __('Ghostscript', 'rapls-pdf-image-creator'),
            'status' => true,
            'message' => __('Needed a workaround on this server (measured)', 'rapls-pdf-image-creator'),
            'detail' => __('A PDF here came back blank until transparency was switched off, which is a fault in Ghostscript 9.27 and earlier. The thumbnail was made, but transparent areas of such a file may not look right. Asking your host to update Ghostscript is the real fix.', 'rapls-pdf-image-creator'),
        ];
    }

    /**
     * A one-page PDF whose only content is a solid CMYK fill
     */
    private static function minimalCmykPdf(): string
    {
        $stream = "/DeviceCMYK cs\n1.0 0.0 0.0 0.0 k\n0 0 72 72 re\nf\n";

        return self::buildPdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 72 72] '
                . '/Resources << /ColorSpace << /CS0 /DeviceCMYK >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
        ]);
    }

    /**
     * Get the major version of the running ImageMagick
     *
     * @return int|null Null when the version cannot be determined
     */
    private function getImageMagickMajorVersion(): ?int
    {
        try {
            $version = \Imagick::getVersion();
        } catch (\Exception $e) {
            return null;
        }

        $versionString = is_array($version) ? ($version['versionString'] ?? '') : '';

        if (!is_string($versionString) || !preg_match('/ImageMagick\s+(\d+)\./', $versionString, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Build the colour management row for the Status tab
     *
     * @return array{name: string, status: bool, message: string, detail: string}
     */
    private function getColorManagementStatus(): array
    {
        $status = $this->colorProfile->getStatus();

        if (ColorProfile::MODE_NAIVE === $status['mode']) {
            return [
                'name' => __('Color Management', 'rapls-pdf-image-creator'),
                'status' => false,
                'message' => __('Disabled by filter — using simple conversion', 'rapls-pdf-image-creator'),
                'detail' => '',
            ];
        }

        if ($status['managed']) {
            return [
                'name' => __('Color Management', 'rapls-pdf-image-creator'),
                'status' => true,
                'message' => __('Active (ICC)', 'rapls-pdf-image-creator'),
                'detail' => sprintf(
                    /* translators: 1: CMYK profile filename, 2: sRGB profile filename */
                    __('Converting %1$s to %2$s', 'rapls-pdf-image-creator'),
                    basename((string) $status['cmyk']),
                    basename((string) $status['srgb'])
                ),
            ];
        }

        // Report both, not whichever is checked first. The conversion needs a
        // source and a destination, and "CMYK is missing" left a reader unable
        // to tell whether finding one profile would be enough.
        $absent = [];
        if (null === $status['cmyk']) {
            $absent[] = __('CMYK', 'rapls-pdf-image-creator');
        }
        if (null === $status['srgb']) {
            $absent[] = __('sRGB', 'rapls-pdf-image-creator');
        }

        if (count($absent) > 1) {
            $missing = __('Neither a CMYK nor an sRGB profile was found on this server. Colour conversion needs both.', 'rapls-pdf-image-creator');
        } elseif ($absent) {
            $missing = sprintf(
                /* translators: %s: a colour profile type, CMYK or sRGB */
                __('No %s profile found on this server. Colour conversion needs both a CMYK and an sRGB profile; the other one is present.', 'rapls-pdf-image-creator'),
                $absent[0]
            );
        } else {
            $missing = __('Colour conversion is switched off.', 'rapls-pdf-image-creator');
        }

        return [
            'name' => __('Color Management', 'rapls-pdf-image-creator'),
            'status' => false,
            'message' => __('Inactive — using simple conversion', 'rapls-pdf-image-creator'),
            'detail' => $missing,
        ];
    }

    /**
     * Normalize an output format to an ImageMagick format name
     *
     * @param string $format Configured format
     * @return string 'JPEG', 'PNG' or 'WEBP'
     */
    private function normalizeFormat(string $format): string
    {
        switch (strtoupper($format)) {
            case 'PNG':
                return 'PNG';
            case 'WEBP':
                return 'WEBP';
            default:
                return 'JPEG';
        }
    }

    /**
     * Decide what to flatten transparency onto
     *
     * @param string $bgColor Configured background
     * @param string $format Normalized output format
     * @return string Background colour name
     */
    private function resolveBackground(string $bgColor, string $format): string
    {
        $bgColor = strtolower($bgColor);

        // JPEG cannot store alpha. Flattening onto "transparent" leaves the
        // encoder to pick, and it picks black.
        if ('transparent' === $bgColor && 'JPEG' === $format) {
            $bgColor = 'white';
        }

        /**
         * Filter the background transparent regions are flattened onto.
         *
         * @param string $bgColor 'white', 'black' or 'transparent'.
         * @param string $format  'JPEG', 'PNG' or 'WEBP'.
         */
        $bgColor = apply_filters('rapls_pdf_image_creator_flatten_background', $bgColor, $format);

        // Colour names and hex are case-insensitive to ImageMagick, so
        // normalizing here keeps the transparency check below simple.
        return is_string($bgColor) && '' !== $bgColor ? strtolower($bgColor) : 'white';
    }

    /**
     * Composite the page onto its background colour
     *
     * @param \Imagick $imagick Imagick instance. Released when replaced.
     * @return \Imagick The flattened image, which may be a new instance
     */
    private function flatten(\Imagick $imagick): \Imagick
    {
        // mergeImageLayers() returns a new instance, so the original has to be
        // released explicitly or it leaks for the rest of the request.
        if (method_exists($imagick, 'mergeImageLayers')) {
            $flattened = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->clear();
            return $flattened;
        }

        // flattenImages() is deprecated and unreliable under ImageMagick 7,
        // but it is all that is left on very old builds.
        $flattened = $imagick->flattenImages();
        $imagick->clear();

        return $flattened;
    }

    /**
     * Drop the alpha channel after flattening
     *
     * @param \Imagick $imagick Imagick instance
     */
    private function removeAlphaChannel(\Imagick $imagick): void
    {
        try {
            // ALPHACHANNEL_REMOVE composites against the background colour and
            // needs Imagick 3.4.4. Older builds only have OPAQUE, which makes
            // every pixel fully opaque instead.
            if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            } else {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
            }
        } catch (\Exception $e) {
            // The image is already flattened; an opaque alpha channel is only
            // a few wasted bytes.
        }
    }

    /**
     * Record what the last conversion did, for the Status tab
     *
     * @param int $colorspace Colorspace reported right after readImage()
     * @param string $mode ColorProfile::MODE_* value
     */
    private function recordDiagnostics(int $colorspace, string $mode): void
    {
        $stored = get_option(self::DIAGNOSTICS_OPTION);

        if (
            is_array($stored)
            && ($stored['colorspace'] ?? null) === $colorspace
            && ($stored['mode'] ?? null) === $mode
        ) {
            return;
        }

        update_option(
            self::DIAGNOSTICS_OPTION,
            [
                'colorspace' => $colorspace,
                'mode' => $mode,
                'time' => time(),
            ],
            false
        );
    }

    /**
     * Get background color object
     *
     * @param string $color Color name or hex
     * @return \ImagickPixel
     */
    private function getBgColor(string $color): \ImagickPixel
    {
        $colorLower = strtolower($color);
        switch ($colorLower) {
            case 'black':
                return new \ImagickPixel('black');
            case 'transparent':
                return new \ImagickPixel('transparent');
            case 'white':
                return new \ImagickPixel('white');
        }

        // The flatten_background filter can supply any colour ImageMagick
        // parses, e.g. '#f5f5f5'. An unparseable value throws.
        try {
            return new \ImagickPixel($color);
        } catch (\Exception $e) {
            return new \ImagickPixel('white');
        }
    }

    /**
     * Resize image maintaining aspect ratio
     *
     * @param \Imagick $imagick Imagick instance
     * @param int $maxWidth Maximum width
     * @param int $maxHeight Maximum height
     */
    private function resizeImage(\Imagick $imagick, int $maxWidth, int $maxHeight): void
    {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        if ($width <= $maxWidth && $height <= $maxHeight) {
            return;
        }

        // Calculate new dimensions
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        // At least a pixel: a page a thousand times wider than it is tall
        // rounded its height to 0, which ImageMagick refuses.
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        // Use Lanczos filter for high quality
        $imagick->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);
    }
}
