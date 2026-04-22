<?php

if (!defined('ABSPATH')) {
    exit;
}

function dcb_release_extract_plugin_version(string $plugin_php): string {
    if (preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/mi', $plugin_php, $m)) {
        return sanitize_text_field((string) $m[1]);
    }
    if (preg_match('/define\(\s*[\'\"]DCB_VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $plugin_php, $m)) {
        return sanitize_text_field((string) $m[1]);
    }
    return '';
}

function dcb_release_extract_readme_stable_tag(string $readme): string {
    if (preg_match('/^\s*Stable\s+tag:\s*([^\r\n]+)\s*$/mi', $readme, $m)) {
        return sanitize_text_field((string) $m[1]);
    }
    return '';
}

function dcb_release_readme_has_version_changelog(string $readme, string $version): bool {
    $version = preg_quote(trim($version), '/');
    if ($version === '') {
        return false;
    }
    return (bool) preg_match('/^\s*=\s*' . $version . '\s*=\s*$/mi', $readme);
}

function dcb_release_version_consistency_payload(array $context = array()): array {
    $plugin_php = isset($context['plugin_php']) ? (string) $context['plugin_php'] : '';
    $readme = isset($context['readme']) ? (string) $context['readme'] : '';

    if ($plugin_php === '' && function_exists('file_get_contents') && defined('DCB_PLUGIN_FILE')) {
        $plugin_php = (string) @file_get_contents((string) DCB_PLUGIN_FILE);
    }
    if ($readme === '' && function_exists('file_get_contents') && defined('DCB_PLUGIN_DIR')) {
        $readme = (string) @file_get_contents((string) DCB_PLUGIN_DIR . 'readme.txt');
    }

    $plugin_version = dcb_release_extract_plugin_version($plugin_php);
    $stable_tag = dcb_release_extract_readme_stable_tag($readme);

    $errors = array();
    if ($plugin_version === '') {
        $errors[] = 'Plugin version could not be detected.';
    }
    if ($stable_tag === '') {
        $errors[] = 'Readme stable tag could not be detected.';
    }
    if ($plugin_version !== '' && $stable_tag !== '' && $plugin_version !== $stable_tag) {
        $errors[] = 'Plugin version and readme stable tag do not match.';
    }
    if ($plugin_version !== '' && !dcb_release_readme_has_version_changelog($readme, $plugin_version)) {
        $errors[] = 'Readme changelog is missing the current version section.';
    }

    return array(
        'ok' => empty($errors),
        'plugin_version' => $plugin_version,
        'stable_tag' => $stable_tag,
        'errors' => $errors,
    );
}

function dcb_release_package_exclude_patterns(): array {
    $patterns = array(
        '.git/**',
        '.github/**',
        '.DS_Store',
        'tests/**',
        'fixtures/**',
        'reports/**',
        'docs/**',
        'scripts/**',
        'dist/**',
        '*.zip',
    );

    $filtered = function_exists('apply_filters') ? apply_filters('dcb_release_package_exclude_patterns', $patterns) : $patterns;
    return is_array($filtered) ? array_values(array_filter(array_map('strval', $filtered))) : $patterns;
}

function dcb_release_should_exclude_path(string $relative_path, ?array $patterns = null): bool {
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    if ($relative_path === '') {
        return false;
    }

    if ($patterns === null) {
        $patterns = dcb_release_package_exclude_patterns();
    }

    foreach ($patterns as $pattern) {
        $pattern = str_replace('\\', '/', (string) $pattern);
        if ($pattern === '') {
            continue;
        }

        if (fnmatch($pattern, $relative_path)) {
            return true;
        }

        if (substr($pattern, -3) === '/**') {
            $prefix = substr($pattern, 0, -3);
            if ($prefix !== '' && strpos($relative_path, rtrim($prefix, '/') . '/') === 0) {
                return true;
            }
        }
    }

    return false;
}

function dcb_release_build_manifest(array $relative_paths, ?array $patterns = null): array {
    if ($patterns === null) {
        $patterns = dcb_release_package_exclude_patterns();
    }

    $include = array();
    $exclude = array();
    foreach ($relative_paths as $path) {
        $path = ltrim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '') {
            continue;
        }
        if (dcb_release_should_exclude_path($path, $patterns)) {
            $exclude[] = $path;
        } else {
            $include[] = $path;
        }
    }

    sort($include);
    sort($exclude);

    return array(
        'include' => $include,
        'exclude' => $exclude,
    );
}
