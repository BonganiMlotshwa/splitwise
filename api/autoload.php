<?php

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'App\\';
    
    // Base directory for the namespace prefix
    $base_dir = __DIR__ . '/';
    
    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // No, move to the next registered autoloader
        return;
    }
    
    // Get the relative class name
    $relative_class = substr($class, $len);
    
    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Manually include JWT library files
$jwtFiles = [
    'JWT' => 'JWT',
    'Key' => 'Key',
    'SignatureInvalidException' => 'SignatureInvalidException',
    'BeforeValidException' => 'BeforeValidException',
    'ExpiredException' => 'ExpiredException'
];

foreach ($jwtFiles as $className => $fileName) {
    $filePath = __DIR__ . "/../vendor/firebase/php-jwt/src/$fileName.php";
    if (file_exists($filePath)) {
        require_once $filePath;
        class_alias("Firebase\\JWT\\$className", $className);
    } else {
        // If the file doesn't exist, create a dummy class to prevent errors
        if (!class_exists($className)) {
            eval("class $className {}");
        }
    }
}

// Set timezone
date_default_timezone_set('UTC');
