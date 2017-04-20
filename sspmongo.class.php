<?php
// current overall flow, 
// 1. query generator is called from client side
// 2. a connection is made to the database
// 3. using the request parameters provided an aggregation call will be made 
//      - match (which contains side bar and dataTables searching) is done first
//      - sort 
//      - group
//      - skip & limit
// 4. amount of total documents and documents retrieved post query are counted 
// 5. update draw 
// 5. array is constructed and returned to the client side in datatables friendly format

date_default_timezone_set('America/Toronto');
require_once __DIR__ . "/vendor/autoload.php";

class SSPMongoDB {

  	/**
	 * sort function in mongodb where 1 == ascending and -1 == descending 
     * currently this only supports sorting for one column at a time
     * 
	 *  @param  array $request Data sent to server by DataTable's ajax request
	 *  @param  array $columns Column information array
	 *  @return array          contains fields and direction for sorting
	 */
	static function order ($request, $columns) {
        $orderBy = array();
        if (isset($request['order']) && count($request['order'])) {
            $dtColumns = self::pluck($columns, 'dt');
			for ($i=0, $ien=count( $request['order'] ); $i<$ien; $i++) {
				// Convert the column index into the column data property
				$columnIdx = intval( $request['order'][$i]['column']);
				$requestColumn = $request['columns'][$columnIdx];
				$columnIdx = array_search($requestColumn['data'], $dtColumns);
				$column = $columns[ $columnIdx ];
				if ($requestColumn['orderable'] == 'true') {
					$dir = $request['order'][$i]['dir'] === 'asc' ?
					   -1 :
					    1 ;
                    $orderBy[$column['db']] = $dir;
				}
			}
        } return $orderBy;
	}

    /**
     *  Search bar on top right corner of datatable searches all columns in table for value
     *
     *  @param  array $request   Data sent to server by DataTable's ajax request
	 *  @param  array $columns   Column information array       
     *  @return array            search applies to all columns in datatable, will be added to $match stage
     */
    static function globalSearch ($request, $columns, $dtColumns) {
        $search = array();
        //search globally 
        $search['$or'] = array(); 
        $str =$request['search']['value'];
        for ($i=0, $ien=count($request['columns']); $i<$ien; $i++) {
            $requestColumn = $request['columns'][$i];
            $columnIdx = array_search( $requestColumn['data'], $dtColumns);
            $column = $columns[ $columnIdx ];
            if ( $requestColumn['searchable'] == 'true' ) {
                $search['$or'][] = array($column['db'] => is_numeric($str) ? intval($str) : ($requestColumn['regex'] ? "%".$str."%" : $str));
            }
        } return $search;
    }

    /**
     *  in datatables each column has its own search bar that applies only to that column 
     *  multiple column searches can be used at the same time 
     *
     *  @param  array $request   Data sent to server by DataTable's ajax request
	 *  @param  array $columns   Column information array       
     *  @return array            search applies to specified columns, will be added to $match stage
     */
    static function columnSearch ($request, $columns, $dtColumns) {
        $search = array();
        for ($i=0, $ien=count($request['columns']); $i<$ien; $i++) {
            $requestColumn = $request['columns'][$i];
            $columnIdx = array_search( $requestColumn['data'], $dtColumns );
            $column = $columns[ $columnIdx ];
            $str = $requestColumn['search']['value'];
            if ( $requestColumn['searchable'] == 'true' && $str != '' ) {
                if ($column['db'] == "time") {
                    $date = strtotime($str);
                    if ($date === false) {
                        break;
                    } else {
                        $search[$column['db']] = $date;
                        break;
                    }
                }
                $search[$column['db']] = is_numeric($str) ? intval($str) : ($requestColumn['regex'] ? "%".$str."%" : $str);
            }
        } return $search;
    }


    /**
     * Filters the documents to pass only the documents that match specified condition(s) to
     * the next pipeline stage, uses form and search inputs to make match stage array
     * 
  	 *  @param  array $request   Data sent to server by DataTable's ajax request
	 *  @param  array $columns   Column information array       
     *  @param  array $distinct  if distinct is null, form match array, enode table doesnt need match stage of aggregation
     *  @return array            contains the $match stage of the aggregation pipeline
     */
    static function match ($request, $columns, $distinct) {
        $search = array();
        if ($distinct == null) { // dont want to perform match stage of aggregation on the enode table 
            $dtColumns = self::pluck($columns, 'dt');
            //filtering/ search globally 
            if (isset($request['search']) && $request['search']['value'] != '') {
               $search = array_merge($search, self::globalSearch($request, $columns, $dtColumns));
            }
            //filtering/search on columns
            $columnFilter = self::columnSearch($request, $columns, $dtColumns);
            if (isset( $request['columns'])) {
                if (!empty($columnFilter)) {
                   $search = array_merge($search, $columnFilter);
                }
            }
        }
        return $search;      
    }

    /**
     * limits the number of documents passed to the next stage in the pipeline
     * length value must be a positive integer 
     * 
     *  @param   integer  $request  Data sent to server by DataTables
     *  @return  array              ['limit', $length]
     */
	static function limit ($request) {
        return array("limit" =>  intval($request['length']));
	}

    /**
     * Skips over the specificed number of documents that pass into the stage 
     * and passes the remaining documents to the next stage of the pipeline
     * start value must be a positive integer 
     * 
     *  @param   integer  $request  $request Data sent to server by DataTables 
     *  @return  array              ['skip', $skip]            
     */
    static function skip ($request) {
        return array("skip" => intval($request['start']));
    }

    /**
     * This counts all of the documents in the collection that is being used
     * 
     *  @param   object  $manager     used to connect to database
     *  @param   string  $collection  Name of collection from database
     *  @param   string  $database    Name of database 
     *  @return  int                  number of documents in the colection 

     */
    static function countAll ($manager, $collection, $conn) {
        $database = $conn['database'];
        $command = new MongoDB\Driver\Command([
            'count' => $collection
        ]);
        try {
            $cursor = $manager->executeCommand($database, $command);
            return ($cursor->toArray()[0]->n);
        } catch (MongoDB\Driver\Exception\Exception $e) {
            echo $e->getMessage(), "\n";
        }        
    }

    /**
     * executes count command on collection using the match stage from aggreagtion pipeline
     * the number of filtered documents and number of total documents are required for datatables paging to work 
     * they are returned to the client along with the data in json format 
     *
     *  @param   object  $manager     used to connect to database
     *  @param   string  $collection  name of collection to be queried
     *  @param   array   $conn        array of connection info for database
     *  @param   array   $match       describes how the collection should be filtered before documents are counted 
     *  @return  integer              number of documents after filter has been applied 
     */
    static function countFiltered ($manager, $collection, $conn, $match, $group) {
        if ($group == null && $match == null ) {
            return self::countAll($manager, $collection, $conn);
        }
        $q = new \MongoDB\Driver\Command([ 'count' => $collection,'query' => $match ]);
        $r = $manager->executeCommand($conn['database'], $q);
        return ($r->toArray()[0]->n);
    }

    /**
     * variable passed to and from client side that keeps track of the number of requests
     *  @param  integer  $draw  value from client side
     *  @return integer
     */
    static function drawCounter ($draw) {
        return $draw + 1; 
    }

     /**
	 * Create the data output array for the DataTables rows
	 *
	 *  @param  array $columns  Column information array
	 *  @param  array $data     Data from the MongoDB get
	 *  @return array           Formatted data in a row based format
	 */
     static function data_output ($columns, $data) {
        $out = array();
        for ($i=0, $ien=count($data); $i<$ien; $i++) {
            $row = array();
            for ($j=0, $jen=count($columns); $j<$jen; $j++) {
                $column = $columns[$j];
                if (isset( $column['formatter'])) {
                    $row[ $column['dt'] ] = $column['formatter']( $data[$i][ $column['db'] ], $data[$i] );
                }else {
                    $row[ $column['dt'] ] = $data[$i][ $columns[$j]['db'] ];
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * queryGenerator
     * 
     *  @param  array   $request     Data send to server by DataTables
     *  @param  array   $conn        Connection information for remote server
     *  @param  string  $collection  Collection to grab documents from
     *  @param  string  $primaryKey  Primary key of table
     *  @param  array   $columns     Column information array 
     *  @param  array   $proj        Projection array stage from client side
     *  @param  array   $distinct    grouping stage for distinct values on a field 
     *  @return array                server-side processing response array 
     */
    static function queryGenerator ($request, $conn, $collection, $primaryKey, $columns, $proj, $distinct) {
        $manager  = self::mongoConnect($conn);
        $limit = self::limit($request);
        $order = self::order($request, $columns);
        $skip = self::skip($request);
        $match = self::match($request, $columns, $distinct);
        $data = self::executeQuery($manager, $collection, $limit, $order, $skip, $match, $conn, $proj, $distinct);
        $recordsFiltered = self::countFiltered( $manager, $collection, $conn, $match, $distinct); 
        $recordsTotal = self::countAll($manager, $collection, $conn);
        $output = array(
			"draw"            => isset ($request['draw']) ? intval($request['draw']) : 0,
			"recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
			"data"            => self::data_output($columns, $data)
        );   
        echo json_encode($output);
    }

    /**
     * Connect to the database, from http://php.net/manual/en/class.mongodb-driver-manager.php
     * 
     * @param   array   $conn     Contains [username],[password],[host],[port],[database]
     * @return  object  $manager  MongoDB\Driver\Manager is the main entry point to the extension and maintains connections ot mongoDB
     */
    static function mongoConnect ($conn) {
        try { 
            $handle = "mongodb://" . $conn['user'] . ":" . $conn['pass'] . "@" . $conn['host'] . ":" . $conn['port'] . "/" . $conn['database'];
            $manager = new MongoDB\Driver\Manager($handle);
        } catch (MongoDB\Driver\Exception\Exception $e) {
            self::fatal($e);    
        }
        return $manager;
    }

    /**
     * grabs appropritate documents from collection based on aggregation pipeline constructed 
     *
     *  @param  string  $manager     cursor returned from connecting to a database
     *  @param  string  $collection  collection used to grab documents from 
     *  @param  array   $limit       amount of documents per table page
     *  @param  array   $order       information of how columns should be ordered (asc or desc)
     *  @param  array   $skip        amount od documents to skip for paging 
     *  @param  array   $match       match stage for aggregation pipeline handles filtering
     *  @param  array   $conn        infromation used to connect to the mongoDB database
     *  @param  array   $proj        how the columns in the collection should be projected 
     *  @param  array   $group       group documents by a specified expression 
     *  @return array   $cursor      cursor returned from preforming aggregation on collection 
     */

    static function executeQuery ($manager, $collection, $limit, $order, $skip, $match, $conn, $proj, $group) {
        $bson = array();
	if ($match != null) {
	    $bson[] = array('$match' => (object) $match);
	}
        if ($group != null) {
            $bson[] = array('$group' => (object) $group);
        } 
        if ($order != null) { 
            $bson[] = array('$sort' => (object) $order);
        }
        $bson[] = array('$skip' => $skip["skip"]);
        $bson[] =  array('$limit' => $limit["limit"]);
        if ($proj != null) {
            $bson[] =  array('$project'=> (object) $proj);
        }
        try {
            $db = new MongoDB\Database( $manager, $conn['database'] );
            $col = $db->selectCollection( $collection ); 
            // allowdiskuse  Enables writing to temporary files. When set to true, aggregation stages can write data to the _tmp sub-directory in the dbPath directory
            // batchsize    specifies the initial batch size for the cursor. A batchSize of 0 means an empty first batch and is useful for quickly returning a cursor or 
            // failure message without doing significant server-side work.
            $result = $col->aggregate($bson, array("allowDiskUse"=> true, "batchSize" => 0));
           // var_dump($bson);
            $bson = null;
            $cursor = $result->toArray();
            //var_dump($cursor);
            return $cursor;
        } catch (MongoDB\Driver\Exception\Exception $e) {
            self::fatal($e);
        }
    }
    
    static function fatal ($e) { 	
		echo json_encode(array(
    	"Exception:", $e->getMessage(), "\n",
   	    "In file:", $e->getFile(), "\n",
    	"On line:", $e->getLine(), "\n"
		));
		exit(0);
	}
    
    /**
     * from ssp.class.php for mysql datatables 
	 * Pull a particular property from each assoc. array in a numeric array, 
	 * returning and array of the property values from each item.
	 *
	 *  @param  array  $a    Array to get data from
	 *  @param  string $prop Property to read
	 *  @return array        Array of property values
	 */
	static function pluck ( $a, $prop ){
		$out = array();
		for ($i=0, $len=count($a); $i<$len; $i++ ) {
			$out[] = $a[$i][$prop];
		}
		return $out;
	}

    /** 
     * updates document field, get request is from ajax-inlineedit.js
     * returns the number of matched and modified documents in an array 
     *
     *  @param   array    $request     Array from js that contains inline editing info 
     *  @param   boolean  $conn        0 = updateOne, 1 = updateAll 
     *  @param   string   $collection  contains information required to connect to database
     *  @return  array                 number of modified and # of matched documents 
     */
    static function inlineEdit ($request, $conn, $collection) {
        /// updating the document creates a new oid, need to replace the file so that the primary key doesnt change 
        $type = $request['table'];
        $column = $request['column'];
		$id = $request['id'];
		$newValue = $request["newValue"];
        // connect to database 
        $manager  = self::mongoConnect( $conn );
        $db = new MongoDB\Database( $manager, $conn['database'] );
        $col = $db->selectCollection( $collection );
        try {
            $updateResult = $col->updateOne(
                ['_id' => new MongoDB\BSON\ObjectID($id)],
                ['$set' => [ $column => $newValue]]
            );
            return $updateResult->getModifiedCount() == 0 ? array('success' => false, 'value' => $newValue) :  array('success' => true, 'value' => $newValue);
        } catch (MongoDB\Driver\Exception\Exception $e) {
            self::fatal($e);
        }
            return array('sucess' => false, 'value' => $newValue);
        } 
    }
}
?>
