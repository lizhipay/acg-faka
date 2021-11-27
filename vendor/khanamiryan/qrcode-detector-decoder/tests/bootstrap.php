<?php

require_once __DIR__ . '/../lib/common/customFunctions.php';

spl_autoload_register(function (string $class) {
    $path      = explode('\\', $class);
    $className = array_pop($path);
    array_shift($path);
    $path = strtolower(implode('/', $path));
    $path = __DIR__ . '/../lib/' . $path . '/' . $className . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});
