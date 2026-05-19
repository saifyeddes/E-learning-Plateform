<?php
namespace local_elearning_system;

defined('MOODLE_INTERNAL') || die();

class plugin_db {
    private static ?\mysqli $connection = null;

    public static function get(): \mysqli {
        if (self::$connection instanceof \mysqli) {
            return self::$connection;
        }

        $host = getenv('PLUGIN_DB_HOST') ?: 'plugin_db';
        $port = (int)(getenv('PLUGIN_DB_PORT') ?: 3306);
        $name = getenv('PLUGIN_DB_NAME') ?: 'elearning_plugin';
        $user = getenv('PLUGIN_DB_USER') ?: 'elearning';
        $pass = getenv('PLUGIN_DB_PASS') ?: 'elearning123';

        $db = new \mysqli($host, $user, $pass, $name, $port);

        if ($db->connect_error) {
            throw new \moodle_exception('Plugin database connection failed: ' . $db->connect_error);
        }

        $db->set_charset('utf8mb4');

        self::$connection = $db;
        return self::$connection;
    }
}