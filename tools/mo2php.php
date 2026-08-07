<?php
/**
 * Generate a WordPress .l10n.php from a compiled .mo file.
 *
 * Usage: php mo2php.php <in.mo> <out.l10n.php>
 */

[$self, $moPath, $outPath] = array_pad($argv, 3, null);

if (!$moPath || !$outPath) {
    fwrite(STDERR, "usage: php mo2php.php <in.mo> <out.l10n.php>\n");
    exit(1);
}

$raw = file_get_contents($moPath);
if (false === $raw || strlen($raw) < 28) {
    fwrite(STDERR, "cannot read $moPath\n");
    exit(1);
}

$magic = substr($raw, 0, 4);
if ("\xde\x12\x04\x95" === $magic) {
    $fmt = 'V';                 // little endian
} elseif ("\x95\x04\x12\xde" === $magic) {
    $fmt = 'N';                 // big endian
} else {
    fwrite(STDERR, "not a .mo file\n");
    exit(1);
}

$header = unpack("{$fmt}rev/{$fmt}count/{$fmt}originals/{$fmt}translations", substr($raw, 4, 16));

$read = function (int $offset, int $count) use ($raw, $fmt): array {
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $entry = unpack("{$fmt}len/{$fmt}pos", substr($raw, $offset + $i * 8, 8));
        $out[] = substr($raw, $entry['pos'], $entry['len']);
    }
    return $out;
};

$originals = $read($header['originals'], $header['count']);
$translations = $read($header['translations'], $header['count']);

$messages = [];
$meta = [];

foreach ($originals as $i => $original) {
    $translation = $translations[$i];

    if ('' === $original) {
        // The header entry carries the PO metadata.
        foreach (explode("\n", $translation) as $line) {
            if (false === strpos($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $meta[strtolower(trim($key))] = trim($value);
        }
        continue;
    }

    // Contexts and plurals use \x04 and \x00 separators. This catalogue has
    // neither, so bail loudly rather than writing something subtly wrong.
    if (false !== strpos($original, "\x04") || false !== strpos($original, "\0")) {
        fwrite(STDERR, "context/plural entries are not supported: " . var_export($original, true) . "\n");
        exit(1);
    }

    if ('' === $translation) {
        continue;
    }

    $messages[$original] = $translation;
}

$data = [
    'plural-forms' => $meta['plural-forms'] ?? 'nplurals=2; plural=(n != 1);',
    'language' => $meta['language'] ?? '',
    'project-id-version' => preg_replace('/\s+[\d.]+$/', '', $meta['project-id-version'] ?? ''),
    'messages' => $messages,
];

file_put_contents($outPath, "<?php\nreturn " . var_export($data, true) . ";\n");

fwrite(STDERR, count($messages) . " messages written to $outPath\n");
