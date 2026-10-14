<?php
/**
 * The content lookup's SQL, answered as MySQL would answer it
 *
 * Shared by the suites that stand in for $wpdb. It reads the query it is
 * given rather than trusting its shape: the ID it starts after, the post
 * types and states it leaves out, the columns it compares, each LIKE pattern
 * with its "%" and escapes, and whether the comparison is wrapped in LOWER().
 * Without LOWER() it compares case for case, as a case-sensitive collation
 * (utf8mb4_bin) does -- the one a review found deleting pictures (review
 * after 1.4.37).
 *
 * @param array{0: string, 1: array<int, mixed>} $q        As the stand-in prepare() returns it.
 * @param array<int, string>                      $content  Post ID => post_content.
 * @param array<int, string>                      $excerpts Post ID => post_excerpt.
 * @param array<int, array{type?: string, status?: string}> $kinds Post ID => its type and status.
 * @return array<int, array<string, string>>
 */
function rapls_test_content_rows(array $q, array $content, array $excerpts = [], array $kinds = []): array
{
    [$sql, $args] = $q;
    $after = (int) ($args[0] ?? 0);
    if (1 !== preg_match('/\bID > %d\b/', $sql)) {
        throw new \RuntimeException('the content lookup does not start after an ID: ' . $sql);
    }
    $patterns = array_slice($args, 1);
    if (substr_count($sql, 'LIKE %s') !== count($patterns)) {
        throw new \RuntimeException('LIKE placeholders and patterns do not match: ' . $sql);
    }
    // Each LIKE with the column it compares, and whether through LOWER().
    preg_match_all("/(LOWER\\((post_content|post_excerpt)\\)|LOWER\\(CONCAT_WS\\([^)]*\\)\\)|CONCAT_WS\\([^)]*\\)|post_content|post_excerpt)\\s+LIKE %s/", $sql, $likeOf, PREG_SET_ORDER);
    $columns = [];
    foreach ($likeOf as $one) {
        $columns[] = [0 === strpos($one[1], 'LOWER('), '' !== ($one[2] ?? '') ? $one[2] : $one[1]];
    }
    $withExcerpt = false !== strpos($sql, 'post_excerpt');
    $list = static function (string $column) use ($sql): array {
        return preg_match('/\b' . $column . ' NOT IN \(([^)]*)\)/', $sql, $m)
            ? array_map(static function ($v) { return trim($v, " '"); }, explode(',', $m[1]))
            : [];
    };
    $skipTypes = $list('post_type');
    $skipStatuses = $list('post_status');

    $regexes = [];
    foreach ($patterns as $pattern) {
        // LIKE: "%" any run, "_" one character, "\" escapes the next.
        $regex = '';
        $chars = preg_split('//u', (string) $pattern, -1, PREG_SPLIT_NO_EMPTY);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ('\\' === $c && $i + 1 < $n) {
                $regex .= preg_quote($chars[++$i], '/');
            } elseif ('%' === $c) {
                $regex .= '.*';
            } elseif ('_' === $c) {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }
        $regexes[] = '/\A' . $regex . '\z/su';
    }

    $rows = [];
    ksort($content);
    foreach ($content as $id => $html) {
        $kind = $kinds[$id] ?? [];
        if ($id <= $after || in_array($kind['type'] ?? 'post', $skipTypes, true) || in_array($kind['status'] ?? 'publish', $skipStatuses, true)) {
            continue;
        }
        foreach ($regexes as $i => $regex) {
            [$lowered, $column] = $columns[$i] ?? [false, ''];
            if ('post_content' === $column) {
                $text = $html;
            } elseif ('post_excerpt' === $column) {
                $text = $excerpts[$id] ?? '';
            } elseif (false !== strpos($column, 'CONCAT')) {
                $text = $html . ' ' . ($excerpts[$id] ?? '');
            } else {
                throw new \RuntimeException('a LIKE on something the lookup does not read: ' . $column);
            }
            if ($lowered || 0 === strpos($column, 'LOWER(')) {
                $text = mb_strtolower($text, 'UTF-8');
            }
            if (preg_match($regex, $text)) {
                $row = ['ID' => (string) $id, 'post_content' => $html];
                if ($withExcerpt) {
                    $row['post_excerpt'] = $excerpts[$id] ?? '';
                }
                $rows[] = $row;
                break;
            }
        }
    }

    return preg_match('/LIMIT (\d+)/', $sql, $limit) ? array_slice($rows, 0, (int) $limit[1]) : $rows;
}
