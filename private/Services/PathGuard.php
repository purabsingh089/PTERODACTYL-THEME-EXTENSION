<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use InvalidArgumentException;

/**
 * PathGuard — jail every client-supplied file path before it reaches Wings.
 *
 * Rules: relative only, no ".." segments, no null bytes, no backslashes,
 * no drive letters, and (optionally) the first segment must be one of the
 * given allowed roots. Returns the cleaned path or throws.
 */
class PathGuard
{
    public function resolve(string $input, array $allowedRoots = []): string
    {
        if ($input === '' ) {
            throw new InvalidArgumentException('Path is empty.');
        }

        if (str_contains($input, "\0")) {
            throw new InvalidArgumentException('Path contains a null byte.');
        }

        if (str_contains($input, '\\')) {
            throw new InvalidArgumentException('Path contains a backslash.');
        }

        $input = ltrim($input, '/');

        if (preg_match('#^[A-Za-z]:#', $input)) {
            throw new InvalidArgumentException('Path must be relative.');
        }

        $segments = [];
        foreach (explode('/', $input) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new InvalidArgumentException('Path must not contain "..".');
            }
            $segments[] = $seg;
        }

        if ($segments === []) {
            throw new InvalidArgumentException('Path is empty.');
        }

        if ($allowedRoots !== [] && !in_array($segments[0], $allowedRoots, true)) {
            throw new InvalidArgumentException('Path escapes the allowed roots.');
        }

        return implode('/', $segments);
    }
}
