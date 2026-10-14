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
 * after 1.4.37). A REGEXP is read as MySQL's, case for case too. A row
 * carries only the columns the SELECT names: one that returned the excerpt
 * whatever was selected let the lookup stop asking for it unnoticed (review
 * after 1.4.38).
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
    if (substr_count($sql, 'LIKE %s') + substr_count($sql, 'REGEXP %s') !== count($patterns)) {
        throw new \RuntimeException('LIKE and REGEXP placeholders and patterns do not match: ' . $sql);
    }
    // Each LIKE or REGEXP with the column it compares, and whether through LOWER().
    preg_match_all("/(LOWER\\((post_content|post_excerpt)\\)|LOWER\\(CONCAT_WS\\([^)]*\\)\\)|CONCAT_WS\\([^)]*\\)|post_content|post_excerpt)\\s+(LIKE|REGEXP) %s/", $sql, $likeOf, PREG_SET_ORDER);
    $columns = [];
    foreach ($likeOf as $one) {
        $columns[] = [0 === strpos($one[1], 'LOWER('), '' !== ($one[2] ?? '') ? $one[2] : $one[1], $one[3]];
    }
    $selected = preg_match('/\\A\\s*SELECT\\s+(.*?)\\s+FROM\\b/s', $sql, $select) ? array_map('trim', explode(',', $select[1])) : [];
    $withExcerpt = in_array('post_excerpt', $selected, true);
    $withContent = in_array('post_content', $selected, true);
    $list = static function (string $column) use ($sql): array {
        return preg_match('/\b' . $column . ' NOT IN \(([^)]*)\)/', $sql, $m)
            ? array_map(static function ($v) { return trim($v, " '"); }, explode(',', $m[1]))
            : [];
    };
    $skipTypes = $list('post_type');
    $skipStatuses = $list('post_status');

    $regexes = [];
    foreach ($patterns as $i => $pattern) {
        if ('REGEXP' === ($columns[$i][2] ?? '')) {
            // MySQL's regular expression, as PCRE: the same syntax for what
            // the lookup writes ([[:space:]], \\[, ( | ), [^0-9], $).
            $regexes[] = '/' . str_replace('/', '\\/', (string) $pattern) . '/s';
            continue;
        }
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

    // The conditions as the query joins them: AND before OR, and brackets.
    // Read as a list of either, a LIKE joined to a REGEXP by AND matched
    // where either alone did (review of 1.4.38's fixes).
    $where = preg_match('/\)\s+AND\s+\((.*)\)\s+ORDER BY/s', $sql, $w) ? $w[1] : implode(' OR ', array_fill(0, count($regexes), 'C'));
    $tokens = [];
    $condition = 0;
    $rest = $where;
    while ('' !== ($rest = ltrim($rest))) {
        if (preg_match('/\A(LOWER\((?:post_content|post_excerpt)\)|LOWER\(CONCAT_WS\([^)]*\)\)|CONCAT_WS\([^)]*\)|post_content|post_excerpt)\s+(?:LIKE|REGEXP) %s/', $rest, $m)) {
            $tokens[] = ['c', $condition++];
            $rest = substr($rest, strlen($m[0]));
        } elseif ('C' === $rest[0]) {
            $tokens[] = ['c', $condition++];
            $rest = substr($rest, 1);
        } elseif (preg_match('/\A(AND|OR)\b/', $rest, $m)) {
            $tokens[] = [$m[1], null];
            $rest = substr($rest, strlen($m[0]));
        } elseif ('(' === $rest[0] || ')' === $rest[0]) {
            $tokens[] = [$rest[0], null];
            $rest = substr($rest, 1);
        } else {
            throw new \RuntimeException('the content lookup joins its conditions in a way this stand-in does not read: ' . $rest);
        }
    }
    $evaluate = static function (array $holds) use ($tokens): bool {
        $at = 0;
        $or = null;
        $and = null;
        $primary = null;
        $or = static function () use (&$at, &$and, $tokens): bool {
            $value = $and();
            while ($at < count($tokens) && 'OR' === $tokens[$at][0]) {
                $at++;
                $value = $and() || $value;
            }
            return $value;
        };
        $and = static function () use (&$at, &$primary, $tokens): bool {
            $value = $primary();
            while ($at < count($tokens) && 'AND' === $tokens[$at][0]) {
                $at++;
                $value = $primary() && $value;
            }
            return $value;
        };
        $primary = static function () use (&$at, &$or, $tokens, $holds): bool {
            $token = $tokens[$at++];
            if ('(' === $token[0]) {
                $value = $or();
                $at++;
                return $value;
            }
            return $holds[$token[1]];
        };

        return $or();
    };

    $rows = [];
    ksort($content);
    foreach ($content as $id => $html) {
        $kind = $kinds[$id] ?? [];
        if ($id <= $after || in_array($kind['type'] ?? 'post', $skipTypes, true) || in_array($kind['status'] ?? 'publish', $skipStatuses, true)) {
            continue;
        }
        $holds = [];
        foreach ($regexes as $i => $regex) {
            [$lowered, $column] = $columns[$i] ?? [false, '', ''];
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
            $holds[$i] = 1 === preg_match($regex, $text);
        }
        if ($evaluate($holds)) {
            $row = ['ID' => (string) $id];
            if ($withContent) {
                $row['post_content'] = $html;
            }
            if ($withExcerpt) {
                $row['post_excerpt'] = $excerpts[$id] ?? '';
            }
            $rows[] = $row;
        }
    }

    return preg_match('/LIMIT (\d+)/', $sql, $limit) ? array_slice($rows, 0, (int) $limit[1]) : $rows;
}
