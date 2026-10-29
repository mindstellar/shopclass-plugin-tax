<?php
/*
 * This file is part of the Tax plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads the plugin's classes with no core, no database and no network, and stands in for
 * the few core helpers the hook code calls: __() and a filter registry.
 */

require_once __DIR__ . '/harness.php';

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__, 2) . '/');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\tax\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

ini_set('error_log', '/dev/null');
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!function_exists('__')) {
    function __($text, $domain = '')
    {
        return $text;
    }
}

$GLOBALS['filters'] = array();
if (!function_exists('osc_apply_filter')) {
    function osc_add_filter($hook, $fn, $priority = 5)
    {
        $GLOBALS['filters'][$hook][] = $fn;
    }

    function osc_apply_filter($hook, $content, ...$args)
    {
        foreach ($GLOBALS['filters'][$hook] ?? array() as $fn) {
            $content = $fn($content, ...$args);
        }

        return $content;
    }
}

/** Drop every filter a test added. */
function reset_filters(): void
{
    $GLOBALS['filters'] = array();
}

/** Units of a two-decimal currency as micros: 499.5 is 499500000. */
function m(float $units): int
{
    return (int) round($units * 1000000);
}
