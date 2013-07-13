<?php 

// simple lazy config class

class configMongo {
    static $development = array(
        'database' => 'test'
    );

    static function current() {
    	return self::$development;
    }
}