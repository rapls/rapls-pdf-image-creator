<?php
/**
 * ICC Colour Profile Handling
 *
 * @package PDFImageCreator\Engine
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator\Engine;

/**
 * Resolves ICC profiles and converts CMYK images to sRGB.
 *
 * Without a profile, ImageMagick converts CMYK with the arithmetic formula
 * R = 255 * (1 - C) * (1 - K), which ignores ink behaviour entirely and pushes
 * greens and blues towards fluorescent. Running the same values through a
 * coated-stock profile and an sRGB profile reproduces what the PDF actually
 * looks like.
 *
 * No profile ships with the plugin. Profiles are located on the host at run
 * time, so nothing is redistributed and no licence travels with the package.
 * When none can be found the arithmetic conversion is still used, which is the
 * behaviour every release before 1.1.0 had unconditionally.
 *
 * Everything here is PHP and the Imagick extension. No external process is
 * started, and Ghostscript-supplied profiles are deliberately not in the
 * candidate list: their AGPL terms do not sit well beside GPL-2.0-or-later.
 * Sites that want to use one can point the icc_paths filter at it themselves.
 */
final class ColorProfile
{
    /**
     * Image was not CMYK; nothing was converted.
     */
    public const MODE_PASSTHROUGH = 'passthrough';

    /**
     * Converted through ICC profiles (colour managed).
     */
    public const MODE_ICC = 'icc';

    /**
     * Converted with ImageMagick's arithmetic transform.
     */
    public const MODE_NAIVE = 'naive';

    /**
     * Pick ICC when profiles are available, arithmetic otherwise.
     */
    public const MODE_AUTO = 'auto';

    /**
     * Transient key prefix for resolved profile paths.
     */
    private const TRANSIENT_PREFIX = 'rapls_pic_icc_path_';

    /**
     * Smallest plausible ICC file. The header alone is 128 bytes.
     */
    private const MIN_PROFILE_SIZE = 128;

    /**
     * Largest profile we are willing to read into memory.
     */
    private const MAX_PROFILE_SIZE = 4194304;

    /**
     * Convert a CMYK image to sRGB in place.
     *
     * @param \Imagick $imagick Image to convert. Modified in place.
     * @return string One of the MODE_* constants: the conversion actually used.
     */
    public function convertToSrgb(\Imagick $imagick): string
    {
        try {
            if (\Imagick::COLORSPACE_CMYK !== $imagick->getImageColorspace()) {
                return self::MODE_PASSTHROUGH;
            }
        } catch (\Exception $e) {
            return self::MODE_PASSTHROUGH;
        }

        if (self::MODE_NAIVE === $this->getMode()) {
            return $this->convertArithmetic($imagick);
        }

        $srgbProfile = $this->loadProfile('srgb');
        if (false === $srgbProfile) {
            return $this->convertArithmetic($imagick);
        }

        try {
            // profileImage() only attaches when the image carries no profile;
            // it converts when one is already present. So the source profile
            // has to go on first, then the destination profile drives the
            // actual LittleCMS transform.
            if (!$imagick->getImageProfiles('icc', false)) {
                $cmykProfile = $this->loadProfile('cmyk');
                if (false === $cmykProfile) {
                    return $this->convertArithmetic($imagick);
                }
                $imagick->profileImage('icc', $cmykProfile);
            }

            $imagick->setImageRenderingIntent($this->getRenderingIntent());
            $imagick->profileImage('icc', $srgbProfile);
            $imagick->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        } catch (\Exception $e) {
            // A malformed or mismatched profile throws. Degrading to the
            // arithmetic conversion keeps thumbnail generation working.
            return $this->convertArithmetic($imagick);
        }

        return self::MODE_ICC;
    }

    /**
     * Attach an sRGB profile to an already-converted image.
     *
     * Used after stripImage(), which drops the profile written by
     * convertToSrgb(). Silently does nothing when no profile is available.
     *
     * @param \Imagick $imagick Image to tag. Modified in place.
     */
    public function attachSrgb(\Imagick $imagick): void
    {
        $profile = $this->loadProfile('srgb');
        if (false === $profile) {
            return;
        }

        try {
            $imagick->profileImage('icc', $profile);
        } catch (\Exception $e) {
            // Tagging is cosmetic; sRGB is the assumed default anyway.
        }
    }

    /**
     * Describe the current colour management setup for the Status tab.
     *
     * @return array{mode: string, srgb: ?string, cmyk: ?string, managed: bool}
     */
    public function getStatus(): array
    {
        $mode = $this->getMode();
        $srgb = $this->findProfilePath('srgb');
        $cmyk = $this->findProfilePath('cmyk');

        return [
            'mode' => $mode,
            'srgb' => $srgb,
            'cmyk' => $cmyk,
            'managed' => self::MODE_NAIVE !== $mode && null !== $srgb && null !== $cmyk,
        ];
    }

    /**
     * Forget cached profile paths so the next lookup rescans the filesystem.
     */
    public function flushCache(): void
    {
        delete_transient(self::TRANSIENT_PREFIX . 'srgb');
        delete_transient(self::TRANSIENT_PREFIX . 'cmyk');
    }

    /**
     * Read an ICC profile's bytes.
     *
     * @param string $type 'srgb' or 'cmyk'.
     * @return string|false Profile data, or false when none is usable.
     */
    public function loadProfile(string $type)
    {
        $path = $this->findProfilePath($type);
        if (null === $path) {
            return false;
        }

        $data = $this->readIccFile($path);
        if (false !== $data) {
            return $data;
        }

        // The cached path has gone away. Rescan once rather than staying
        // broken for the rest of the transient's lifetime.
        delete_transient(self::TRANSIENT_PREFIX . $type);
        $path = $this->findProfilePath($type);

        return null === $path ? false : $this->readIccFile($path);
    }

    /**
     * Locate a usable profile, caching the result.
     *
     * @param string $type 'srgb' or 'cmyk'.
     * @return string|null Absolute path, or null when none was found.
     */
    public function findProfilePath(string $type): ?string
    {
        $key = self::TRANSIENT_PREFIX . $type;
        $cached = get_transient($key);

        if (false !== $cached) {
            return '' === $cached ? null : (string) $cached;
        }

        foreach ($this->getCandidates($type) as $path) {
            if (false !== $this->readIccFile($path)) {
                set_transient($key, $path, DAY_IN_SECONDS);
                return $path;
            }
        }

        // Remember the miss too, so a server with no profiles does not stat
        // a dozen paths on every single conversion.
        set_transient($key, '', HOUR_IN_SECONDS);

        return null;
    }

    /**
     * Candidate profile locations, most preferred first.
     *
     * @param string $type 'srgb' or 'cmyk'.
     * @return string[]
     */
    private function getCandidates(string $type): array
    {
        if ('srgb' === $type) {
            $candidates = [
                // Drop a profile here to override host discovery entirely.
                RAPLS_PIC_PLUGIN_DIR . 'assets/icc/sRGB2014.icc',
                '/usr/share/color/icc/colord/sRGB.icc',
                '/usr/share/color/icc/sRGB.icc',
                '/System/Library/ColorSync/Profiles/sRGB Profile.icc',
            ];
        } else {
            $candidates = [
                '/usr/share/color/icc/colord/CoatedFOGRA39.icc',
                '/usr/share/color/icc/ISOcoated_v2_300_eci.icc',
                '/usr/share/color/icc/colord/CoatedFOGRA27.icc',
                '/System/Library/ColorSync/Profiles/Generic CMYK Profile.icc',
            ];
        }

        /**
         * Filter where ICC profiles are looked for.
         *
         * Paths are tried in order and the first readable, valid profile wins.
         *
         * @param string[] $candidates Absolute paths.
         * @param string   $type       'srgb' or 'cmyk'.
         */
        $candidates = apply_filters('rapls_pdf_image_creator_icc_paths', $candidates, $type);

        return array_values(array_filter((array) $candidates, 'is_string'));
    }

    /**
     * Read and validate an ICC file.
     *
     * The path can come from a filter, so it is checked rather than trusted.
     *
     * @param string $path Absolute path to an ICC profile.
     * @return string|false Profile data, or false when the file is unusable.
     */
    private function readIccFile(string $path)
    {
        if ('' === $path) {
            return false;
        }

        $real = realpath($path);
        if (false === $real || !is_file($real) || !is_readable($real)) {
            return false;
        }

        $extension = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, ['icc', 'icm'], true)) {
            return false;
        }

        $size = filesize($real);
        if (false === $size || $size < self::MIN_PROFILE_SIZE || $size > self::MAX_PROFILE_SIZE) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem mangles binary reads; nothing is written.
        $data = file_get_contents($real);
        if (false === $data || strlen($data) < self::MIN_PROFILE_SIZE) {
            return false;
        }

        // Every ICC profile carries 'acsp' at offset 36.
        if ('acsp' !== substr($data, 36, 4)) {
            return false;
        }

        return $data;
    }

    /**
     * Resolve the conversion mode.
     *
     * @return string MODE_AUTO, MODE_ICC or MODE_NAIVE.
     */
    private function getMode(): string
    {
        /**
         * Filter how CMYK is converted to sRGB.
         *
         * 'auto' uses ICC profiles when available and falls back to the
         * arithmetic transform. 'icc' behaves the same but makes the intent
         * explicit. 'naive' pins the pre-1.1.0 behaviour, for sites that would
         * rather keep their existing thumbnail colours.
         *
         * @param string $mode 'auto', 'icc' or 'naive'.
         */
        $mode = apply_filters('rapls_pdf_image_creator_color_conversion', self::MODE_AUTO);

        return in_array($mode, [self::MODE_ICC, self::MODE_NAIVE], true) ? $mode : self::MODE_AUTO;
    }

    /**
     * Resolve the rendering intent used for the ICC transform.
     *
     * @return int An Imagick::RENDERINGINTENT_* value.
     */
    private function getRenderingIntent(): int
    {
        /**
         * Filter the ICC rendering intent.
         *
         * Relative colorimetric is the default because it reproduces print
         * most directly. Perceptual tends to flatten contrast.
         *
         * @param int $intent An Imagick::RENDERINGINTENT_* value.
         */
        return (int) apply_filters(
            'rapls_pdf_image_creator_rendering_intent',
            \Imagick::RENDERINGINTENT_RELATIVE
        );
    }

    /**
     * ImageMagick's built-in transform, used when no profile is available.
     *
     * @param \Imagick $imagick Image to convert. Modified in place.
     * @return string Always MODE_NAIVE.
     */
    private function convertArithmetic(\Imagick $imagick): string
    {
        try {
            $imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        } catch (\Exception $e) {
            // Leave the image alone rather than failing the whole conversion.
        }

        return self::MODE_NAIVE;
    }
}
