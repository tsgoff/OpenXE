<?php

/*
 * Helper to compare database structures from files vs. database
 *
 * Copyright (c) 2022 OpenXE project
 *
 */


/*
MariaDB [openxe]> SHOW FULL TABLES;
+----------------------------------------------+------------+
| Tables_in_openxe                             | Table_type |
+----------------------------------------------+------------+
| abrechnungsartikel                           | BASE TABLE |
| abrechnungsartikel_gruppe                    | BASE TABLE |
| abschlagsrechnung_rechnung                   | BASE TABLE |
| accordion                                    | BASE TABLE |
| adapterbox                                   | BASE TABLE |
| adapterbox_log                               | BASE TABLE |
| adapterbox_request_log                       | BASE TABLE |
| adresse                                      | BASE TABLE |
| adresse_abosammelrechnungen                  | BASE TABLE |
| adresse_accounts                             | BASE TABLE |
| adresse_filter                               | BASE TABLE |
| adresse_filter_gruppen                       | BASE TABLE |
...


MariaDB [openxe]> SHOW FULL COLUMNS FROM wiki;
+-------------------+--------------+--------------------+------+-----+---------+----------------+---------------------------------+---------+
| Field             | Type         | Collation          | Null | Key | Default | Extra          | Privileges                      | Comment |
+-------------------+--------------+--------------------+------+-----+---------+----------------+---------------------------------+---------+
| id                | int(11)      | NULL               | NO   | PRI | NULL    | auto_increment | select,insert,update,references |         |
| name              | varchar(255) | utf8mb3_general_ci | YES  | MUL | NULL    |                | select,insert,update,references |         |
| content           | longtext     | utf8mb3_general_ci | NO   |     | NULL    |                | select,insert,update,references |         |
| lastcontent       | longtext     | utf8mb3_general_ci | NO   |     | NULL    |                | select,insert,update,references |         |
| wiki_workspace_id | int(11)      | NULL               | NO   |     | 0       |                | select,insert,update,references |         |
| parent_id         | int(11)      | NULL               | NO   |     | 0       |                | select,insert,update,references |         |
| language          | varchar(32)  | utf8mb3_general_ci | NO   |     |         |                | select,insert,update,references |         |
+-------------------+--------------+--------------------+------+-----+---------+----------------+---------------------------------+---------+
7 rows in set (0.002 sec)

*/

function implode_with_quote(string $quote, string $delimiter, array $array_to_implode) : string {
    return($quote.implode($quote.$delimiter.$quote, $array_to_implode).$quote);
}

$host = 'localhost';
$user = 'openxe';
$passwd = 'openxe';
$schema = 'openxe';

$target_folder = "export";
$tables_file_name_wo_folder = "0-tables.txt";
$tables_file_name = $target_folder."/".$tables_file_name_wo_folder;
$delimiter = ";";
$quote = '"';

$color_red = "\033[31m";
$color_green = "\033[32m";
$color_yellow = "\033[33m";
$color_default = "\033[39m";

echo("\n");

if ($argc > 1) {

    if (in_array('-v', $argv)) {
      $verbose = true;
    } else {
      $verbose = false;
    } 

    if (in_array('-f', $argv)) {
      $force = true;
    } else {
      $force = false;
    } 

    if (in_array('-e', $argv)) {
      $export = true;
    } else {
      $export = false;
    } 

    if (in_array('-c', $argv)) {
      $compare = true;
    } else {
      $compare = false;
    } 

    if (in_array('-i', $argv)) {
      $onlytables = true;
    } else {
      $onlytables = false;
    } 

    echo("--------------- Loading from database $schema@$host... ---------------\n");
    $tables = load_tables_from_db($host, $schema, $user, $passwd);

    if (empty($tables)) {
        echo ("Could not load from $schema@$host\n");
        exit;
    }

    echo("--------------- Loading from database complete. ---------------\n");

    if ($export) {

        echo("--------------- Export to CSV... ---------------\n");
        $result = save_tables_to_csv($tables, $target_folder, $tables_file_name, $delimiter, $quote, $force);

        if (!$result) {
            echo ("Could not save to CSV $path/$tables_file_name\n");
            exit;
        }
    
        echo("Exported ".count($tables)." tables.\n");
        echo("--------------- Export to CSV complete. ---------------\n");
    }

    if ($compare) {

        // Results here as ['text'] ['diff']
        $compare_differences = array();

        echo("--------------- Loading from CSV... ---------------\n");
        $compare_tables = load_tables_from_csv($target_folder, $tables_file_name_wo_folder, $delimiter, $quote);

        if (empty($compare_tables)) {
            echo ("Could not load from CSV $path/$tables_file_name\n");
            exit;
        }
        echo("--------------- Loading from CSV complete. ---------------\n");

        // Do the comparison

        echo("--------------- Comparison Databse DB vs. CSV ---------------\n");

        echo(count($tables)." tables in DB, ".count($compare_tables)." in CSV.\n");
        $compare_differences = compare_table_array($tables,"in DB",$compare_tables,"in CSV",!$onlytables);
        echo("Comparison found ".(empty($compare_differences)?0:count($compare_differences))." differences.\n");
    
        foreach ($compare_differences as $compare_difference) {
            $comma = "";
            foreach ($compare_difference as $key => $value) {
                echo($comma."$key => '$value'");
                $comma = ", ";
            }
            echo("\n");
        }           
        echo("--------------- Comparison CSV (nominal) vs. database (actual) ---------------\n");
        $compare_differences = compare_table_array($compare_tables,"in CSV",$tables,"in DB",false);
        echo("Comparison found ".(empty($compare_differences)?0:count($compare_differences))." differences.\n");
    
        foreach ($compare_differences as $compare_difference) {
            $comma = "";
            foreach ($compare_difference as $key => $value) {
                echo($comma."$key => '$value'");
                $comma = ", ";
            }
            echo("\n");
        }           

        echo("--------------- Comparison complete. ---------------\n");
    }

    echo("--------------- Done. ---------------\n");

    echo("\n");

} else {
  info();
  exit;
}

// Load all tables from a DB connection into a tables array

function load_tables_from_db(string $host, string $schema, string $user, string $passwd) : array {

    // First get the contents of the database table structure
    $mysqli = mysqli_connect($host, $user, $passwd, $schema);

    /* Check if the connection succeeded */
    if (!$mysqli) {
        return(array());
    }

    // Get tables and views

    $sql = "SHOW FULL TABLES"; 
    $query_result = mysqli_query($mysqli, $sql);
    if (!$query_result) {
        return(array());
    } 
    while ($row = mysqli_fetch_assoc($query_result)) {
        $table = array();
        $table['name'] = $row['Tables_in_'.$schema];
        $table['type'] = $row['Table_type'];
        $tables[] = $table; // Add table to list of tables
    }

    // Get and add columns of the table
    foreach ($tables as &$table) {    
        $sql = "SHOW FULL COLUMNS FROM ".$table['name'];
        $query_result = mysqli_query($mysqli, $sql);

        if (!$query_result) {
            return(array());
        }

        $columns = array();
        while ($column = mysqli_fetch_assoc($query_result)) {
            $columns[] = $column; // Add column to list of columns
        }     
        $table['columns'] = $columns;       
    }   
    unset($table);    
    return($tables);   
}

// Save all tables to CSV files
function save_tables_to_csv(array $tables, string $path, string $tables_file_name, string $delimiter, string $quote, bool $force) : bool {
    
    // Prepare tables file
    if (!is_dir($path)) {
        mkdir($path);
    }
    if (!$force && file_exists($path."/".$tables_file_name)) {
        return(false);
    }

    $tables_file = fopen($tables_file_name, "w");
    if (empty($tables_file)) {
        return(false);
    }

    $first_table = true;
    // Now export all colums of the tables
    foreach ($tables as $export_table) {         
        if ($first_table) {
            $first_table = false;
            fwrite($tables_file,$quote.'name'.$quote.$delimiter.$quote.'type'.$quote."\n");  
        }
        fwrite($tables_file,$quote.$export_table['name'].$quote.$delimiter.$quote.$export_table['type'].$quote."\n");  

        // Prepare export_table file
        $table_file_name = $path."/".$export_table['name'].".txt";
        if (!$force && file_exists($table_file_name)) {
            return(false);
        }
        $table_file = fopen($table_file_name, "w");
        if (empty($table_file)) {
            return(false);
        }  

        $first_column = true;

        foreach ($export_table['columns'] as $column) {
            if ($first_column) {
                $first_column = false;
                fwrite($table_file,implode_with_quote($quote,$delimiter,array_keys($column))."\n");  
            }
            fwrite($table_file,implode_with_quote($quote,$delimiter,array_values($column))."\n");  
        }
        unset($column);

        fclose($table_file);
    }
    unset($export_table);
    fwrite($tables_file,"\n");  
    fclose($tables_file);
    return(true);
}

// Load all tables from CSV files
function load_tables_from_csv(string $path, string $tables_file_name, string $delimiter, string $quote) : array {
    
    $tables = array();
    $first_table = true;
    $tables_file = fopen($path."/".$tables_file_name, "r");

    if (!$tables_file) {
        return(array());
    }

    while (($csv_line = fgetcsv($tables_file,0,$delimiter,$quote)) !== FALSE) {

        if ($first_table) {
            $first_table = false;
        } else if (count($csv_line) == 2) {
            $new_table = array();
            $new_table['name'] = $csv_line['0'];
            $new_table['type'] = $csv_line['1'];
            $tables[] = $new_table;
        } else {
                     
        }
    }
    fclose($tables_file);

    // Get columns for each table

    foreach ($tables as &$table) {

        $table_file_name = $path."/".$table['name'].".txt";
        if (!file_exists($table_file_name)) {
            return(array());
        }
        $table_file = fopen($table_file_name, "r");
        if (empty($table_file)) {
            return(array());    
        }  

        $first_column = true;
        $column_headers = array();
        $columns = array();
        $column = array();
        while (($csv_line = fgetcsv($table_file,0,$delimiter,$quote)) !== FALSE) {
            if ($first_column) {
                $first_column = false;
                $column_headers = $csv_line;
            } else {                    
                for ($cr = 0;$cr < count($csv_line);$cr++) {
                    $column[$column_headers[$cr]] = $csv_line[$cr];
                }   
                $columns[] = $column;                                     
            }
        }            
        $table['columns'] = $columns;
    }
    unset($table);
    return($tables);
}

// Compare two definitions
// Report based on the first array
// Return Array
function compare_table_array(array $nominal, string $nominal_name, array $actual, string $actual_name, bool $check_column_definitions) : array {

    $compare_differences = array();

    if (count($nominal) != count($actual)) {
        $compare_difference = array();
        $compare_difference['type'] = "Table count";
        $compare_difference[$nominal_name] = count($nominal);
        $compare_difference[$actual_name] = count($actual);
        $compare_differences[] = $compare_difference;
    }

    foreach ($nominal as $database_table) {
        
        $found_table = array(); 
        foreach ($actual as $compare_table) {
            if ($database_table['name'] == $compare_table['name']) {
                $found_table = $compare_table;
                break;
            }
        }
        unset($compare_table);

        if ($found_table) {

            // Check type table vs view

            if ($database_table['type'] != $found_table['type']) {
                $compare_difference = array();
                $compare_difference['type'] = "Table type";
                $compare_difference['table'] = $database_table['name'];
                $compare_difference[$nominal_name] = $database_table['type'];
                $compare_difference[$actual_name] = $found_table['type'];
                $compare_differences[] = $compare_difference;
            }
          
            // Check columns
            $compare_table_columns = array_column($found_table['columns'],'Field');

            foreach ($database_table['columns'] as $column) {

                $column_name_to_find = $column['Field'];
                $column_key = array_search($column_name_to_find,$compare_table_columns,true);
                if ($column_key !== false) {
                        
                    // Compare the properties of the columns
                    if ($check_column_definitions) {
                        $found_column = $found_table['columns'][$column_key];
                        foreach ($column as $key => $value) {                            
                            if ($found_column[$key] != $value) {
                                $compare_difference = array();
                                $compare_difference['type'] = "Column definition";
                                $compare_difference['table'] = $database_table['name'];
                                $compare_difference['column'] = $column['Field'];
                                $compare_difference[$nominal_name] = $key."=".$value;
                                $compare_difference[$actual_name] = $key."=".$found_column[$key];
                                $compare_differences[] = $compare_difference;
                            }
                        }
                        unset($value);                          
                    } // $check_column_definitions
                } else {
                    $compare_difference = array();
                    $compare_difference['type'] = "Column existance";
                    $compare_difference['table'] = $database_table['name'];
                    $compare_difference[$nominal_name] = $column['Field'];
                    $compare_differences[] = $compare_difference;
                }
            } 
            unset($column); 
        } else {
            $compare_difference = array();
            $compare_difference['type'] = "Table existance";
            $compare_difference[$nominal_name] = $database_table['name'];
            $compare_differences[] = $compare_difference;
        }
    }
    unset($database_table);

    return($compare_differences);
}

function info() {
    echo("OpenXE database compare\n");
    echo("Copyright 2022 (c) OpenXE project\n");
    echo("\n");
    echo("Export database structures in a defined format for database comparison / upgrade\n");
    echo("Options:\n");
    echo("\t-v: verbose output\n");
    echo("\t-f: force override of existing files\n");
    echo("\t-e: export database structure to files\n");
    echo("\t-c: compare content of files with database structure\n");
    echo("\t-i: ignore column definitions\n");
    echo("\n");
}

