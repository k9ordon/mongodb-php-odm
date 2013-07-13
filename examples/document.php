<?
include "../config/mongo.php";

var_dump(configMongo::$development);

include "../classes/mongo/database.php";
include "../classes/mongo/collection.php";
include "../classes/mongo/document.php";

class Model_Document extends Mongo_Document {
	public $name = 'test';
}

$document = new Model_Document();
$document->username = 'yoda';
$document->type = 'jedi';
$document->save();