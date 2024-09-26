<?php
    spl_autoload_register(function ($class) {
        // Project-specific namespace prefix
        $prefix = 'tmPower\\';
        // Base directory for the namespace prefix
        $base_dir = __DIR__ . '/src/';

        // Third-party namespace prefixes and base directories
        $third_party = [
            'Bluerhinos\\' => __DIR__ . '/libs/',
        ];

        // Check for project-specific namespace
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require $file;
                return;
            }
        }

        // Check for third-party namespaces
        foreach ($third_party as $third_prefix => $third_base_dir) {
            $len = strlen($third_prefix);
            if (strncmp($third_prefix, $class, $len) === 0) {
                $relative_class = substr($class, $len);
                $file = $third_base_dir . str_replace('\\', '/', $relative_class) . '.php';

                if (file_exists($file)) {
                    require $file;
                    return;
                }
            }
        }
    });
