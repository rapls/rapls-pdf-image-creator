<?php
/**
 * Every public method 1.4.20 had is still there
 *
 * A public method is not removed in a release: code elsewhere reaching one
 * -- through Plugin::getInstance(), or a hook of its own -- stops with a
 * fatal error. Four had gone since 1.4.20 (review of 1.4.41):
 * BulkProcessor::getPDFs() and getStats(), Inserter::addInsertSettingsToJs()
 * and MediaLibrary::ajaxRegenerateThumbnail(). The list is 1.4.20's own,
 * read from its source; a method added since is not held to it, only one
 * that was there.
 */

define('RAPLS_PIC_PLUGIN_DIR', dirname(__DIR__) . '/');
spl_autoload_register(static function (string $class): void {
    $prefix = 'Rapls\\PDFImageCreator\\';
    if (0 === strpos($class, $prefix)) {
        $file = RAPLS_PIC_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$pass = 0;
$fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %-62s got=%s want=%s\n", $ok ? 'ok  ' : 'FAIL', $label, json_encode($got, JSON_UNESCAPED_SLASHES), json_encode($want, JSON_UNESCAPED_SLASHES));
}

// 1.4.20 (c6e3fbd): each class or interface, and its public methods.
$public1420 = [
    'Admin' => ['__construct', 'addMenuPage', 'enqueueAssets', 'getRecheckUrl', 'init', 'maybeDismissColorNotice', 'maybeDismissEngineNotice', 'maybeRecheckProbes', 'registerSettings', 'renderSettingsPage', 'sanitizeSettings', 'showAdminNotices'],
    'BulkProcessor' => ['__construct', 'ajaxGenerate', 'ajaxScan', 'ajaxStatus', 'getPDFs', 'getStats', 'init'],
    'Engine\\ColorProfile' => ['attachSrgb', 'convertToSrgb', 'findProfilePath', 'flushCache', 'getStatus', 'loadProfile'],
    'Engine\\ConversionResult' => ['__construct', 'failure', 'getCode', 'getConversionTime', 'getError', 'getFileSize', 'getHeight', 'getOutputPath', 'getWidth', 'isSuccess', 'success'],
    'Engine\\EngineInterface' => ['convert', 'getAvailabilityStatus', 'getDisplayName', 'getName', 'getRequirements', 'isAvailable'],
    'FailureCode' => ['all', 'describe', 'fromExceptionMessage', 'isPermanent', 'permanent'],
    'Generator' => ['__construct', 'checkCapabilities', 'deleteThumbnail', 'generate', 'getAvailabilityStatus', 'getAvailableEngine', 'getEngines', 'getLastFailure', 'getThumbnailId', 'getThumbnailImage', 'getThumbnailUrl', 'hasThumbnail', 'isOwnThumbnail', 'outcomeFor'],
    'Engine\\ImagickEngine' => ['__construct', 'convert', 'getAvailabilityStatus', 'getColorProfile', 'getDisplayName', 'getName', 'getRequirements', 'isAvailable', 'probeTransients'],
    'Inserter' => ['__construct', 'addInsertSettingsToJs', 'filterMediaSendToEditor', 'generateInsertHtml', 'init'],
    'MediaLibrary' => ['__construct', 'addMediaColumns', 'addRowActions', 'ajaxRegenerateThumbnail', 'allowPdfsInImageSelection', 'enqueueBlockEditorAssets', 'ensurePdfMetadataHasImageKeys', 'filterAttachmentForJs', 'filterAttachmentImage', 'filterAttachmentImageAttributes', 'filterAttachmentImageSrc', 'filterMediaLibrary', 'filterMimeTypeIcon', 'filterRestAttachment', 'handleRegenerateAction', 'init', 'maybeSuppressCorePdfPreview', 'onAttachmentAdded', 'onAttachmentDeleted', 'renderMediaColumn'],
    'Plugin' => ['__wakeup', 'getGenerator', 'getInstance', 'getSettings', 'init'],
    'ReviewPrompt' => ['handleAjaxDismiss', 'handleDismiss', 'init', 'render'],
    'Settings' => ['get', 'getBgColor', 'getCustomHtml', 'getDefaults', 'getFileExtension', 'getFormat', 'getInsertLink', 'getInsertSize', 'getInsertType', 'getMaxHeight', 'getMaxWidth', 'getMimeType', 'getPage', 'getQuality', 'getResolution', 'getValue', 'isAutoGenerateEnabled', 'save', 'shouldDisplayThumbnailIcon', 'shouldHideGeneratedImages', 'shouldKeepOnUninstall', 'shouldSetFeatured'],
    'SiteHealth' => ['__construct', 'init', 'registerDebugInformation', 'registerTest', 'runTest'],
];

foreach ($public1420 as $class => $methods) {
    $fq = 'Rapls\\PDFImageCreator\\' . $class;
    $missing = [];
    if (!class_exists($fq) && !interface_exists($fq)) {
        check("$class exists", false, true);
        continue;
    }
    $reflection = new ReflectionClass($fq);
    foreach ($methods as $method) {
        if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isPublic()) {
            $missing[] = $method;
        }
    }
    check("$class: every public method of 1.4.20's is there and public", $missing, []);
}

// The restored ones are hooked by nothing here: each was taken off its hook
// for a reason, and stays off it.
$hooked = [];
foreach (['BulkProcessor.php' => 'getPDFs|getStats', 'Inserter.php' => 'addInsertSettingsToJs', 'MediaLibrary.php' => 'ajaxRegenerateThumbnail'] as $file => $names) {
    if (preg_match("/'(?:$names)'/", (string) file_get_contents(RAPLS_PIC_PLUGIN_DIR . 'includes/' . $file))) {
        $hooked[] = $file;
    }
}
check('the methods kept for 1.4.20 are hooked by nothing', $hooked, []);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
