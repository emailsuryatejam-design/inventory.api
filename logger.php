<?php
/**
 * KCL Stores — File-based Logger
 * Structured logging to cache/logs/
 */

function appLog($level, $message, $context = []) {
    $logDir = __DIR__ . '/cache/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $date = date('Y-m-d');
    $time = date('Y-m-d H:i:s');
    $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $line = "[$time] [$level] [$ip] $message$contextStr\n";

    file_put_contents("$logDir/$date.log", $line, FILE_APPEND | LOCK_EX);
}
