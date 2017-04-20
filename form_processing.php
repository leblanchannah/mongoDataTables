<?php
require('sspmongo.class.php');
date_default_timezone_set('America/Toronto');
// this file organizes get requests from two datatables in front end and passes information to sspmongo.class.php
// sspmongo.php handles inline editing on table cells, searching and filtering 
if (isset($_GET['tables'])) {
	$collection = $_GET['tables'];
}

// $mongo, array with information used to connect to database
$mongo = array(
	'user' => '',
	'pass' => '',
	'host' => '',
	'port' => '',
	'database' => ''
);
// user is trying to edit a cell in one of the tables
if (isset($_GET['edit'])) {
	echo json_encode(
		SSPMongoDB::inlineEdit($_GET, $mongo, $collection)
	); 
} else {
	// load tables instead of inline edit
	$distinct = array('_id' => '$a',
			'id' => array('$first' => '$_id'),
			'c' => array('$first' => '$b')
		);
    $fields = array(
				'_id'=> 0,
				'id' => '$id',
				'a' => '$_id',
				'b' => '$c'
				);
    // $columns is required by datatables so that sspmongo can format each column based on their own requirements
    // useful if you want to name a column something other than its field name in the mongodb collection
    $columns = array(
        array(
            'db' => '_id',
            'dt' =>'DT_RowId',
            'formatter' => function($d, $row) {
            return $d.$oid;
                }
        ),
        array('db'=>'a', 'dt'=>'a'),
        array('db'=>'b', 'dt'=>'b'),
        array(
            'db'=>'time',
            'dt'=>'time',
            // formatting date from unix timestamp
            'formatter' => function($d, $row) {
                return gmdate("Y-m-d H:i:s", $d);
            }
        ),
        array('db'=>'c', 'dt'=>'c'),
        array('db'=>'d', 'dt'=>'d')
    );
}
$primaryKey = 'id';
SSPMongoDB::queryGenerator($_GET, $mongo, $collection, $primaryKey, $columns, $fields, $distinct);

?>
