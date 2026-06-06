<?php
// Carga todos los plugins de la carpeta plugins (cada subcarpeta con un index.php)
function get_plugins_list($plugins_dir = __DIR__){
    $plugins = [];
    foreach (scandir($plugins_dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $plugin_path = $plugins_dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($plugin_path) && file_exists($plugin_path . '/index.php')) {
            $meta = [
                'name' => ucfirst($entry),
                'description' => '',
                'version' => ''
            ];
            $meta_file = $plugin_path . '/metadata.json';
            if (file_exists($meta_file)) {
                $json = json_decode(file_get_contents($meta_file), true);
                if (is_array($json)) {
                    $meta = array_merge($meta, $json);
                }
            }
            $plugins[] = array_merge($meta, [
                'dir' => $entry,
                'path' => $plugin_path . '/index.php'
            ]);
        }
    }
    return $plugins;
}
