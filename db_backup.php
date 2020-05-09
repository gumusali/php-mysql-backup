<?php
	# db connection
	try {
		$host = "localhost";
		$user = "username";
		$pass = "password";
		$db   = "dbname";

		$_sql = new PDO("mysql:host={$host};dbname={$db}", $user, $pass);
		$_sql->exec("SET NAMES utf8mb4");
	} catch(PDOException $e) {
		echo "DB Connection error";
	}

	# variables
	$backup = "";

	# get database tables
	$tables = $_sql->prepare("SHOW TABLES FROM {$db}");
	$tables->execute();
	$tables = $tables->fetchAll(PDO::FETCH_NUM);
	$table  = array_map(function($item) { return $item[0]; }, $tables);

	# get all columns for each table
	foreach($table as $tbl) {
		$columns = $_sql->prepare("SHOW COLUMNS FROM {$tbl}");
		$columns->execute();
		$columns = $columns->fetchAll(PDO::FETCH_ASSOC);
		$col_opt = array();

		# set column options
		foreach($columns as $col) {
			$field   = $col['Field'];
			$type    = $col['Type'];
			$null    = ($col['Null'] == 'NO') ? 'NOT NULL' : 'NULL';
			$default = ($col['Default']) ? (($col['Default'] == 'NULL') ? 'DEFAULT '. $col['Default'] : "DEFAULT '{$col['Default']}'") : '';

			$col_opt['options'][] = "`{$field}` {$type} {$null} {$default}";
			$col_opt['names'][]   = "`{$field}`";
		}

		# set indexes of table
		$indexes = $_sql->prepare("SHOW INDEXES FROM {$tbl}");
		$indexes->execute();
		$indexes = $indexes->fetchAll(PDO::FETCH_ASSOC);

		foreach($indexes as $index) {
			if($index['Non_unique'] == '0' && $index['Key_name'] == 'PRIMARY') {
				$col_opt['extras'][] = "ALTER TABLE `{$index['Table']}` ADD PRIMARY KEY (`{$index['Column_name']}`);";
			}

			if($index['Non_unique'] == '0' && $index['Key_name'] != 'PRIMARY') {
				$col_opt['extras'][] = "ALTER TABLE `{$index['Table']}` ADD UNIQUE KEY (`{$index['Column_name']}`);";
			}

			if($index['Non_unique'] == '1' && $index['Index_type'] == 'FULLTEXT') {
				$col_opt['extras'][] = "ALTER TABLE `{$index['Table']}` ADD FULLTEXT (`{$index['Column_name']}`);";
			}

			if($index['Non_unique'] == '1' && $index['Index_type'] == 'BTREE') {
				$col_opt['extras'][] = "ALTER TABLE `{$index['Table']}` ADD INDEX (`{$index['Column_name']}`);";
			}
		}

		# set auto_increment columns
		foreach($columns as $col) {
			if($col['Extra'] == 'auto_increment') {
				$field   = $col['Field'];
				$type    = $col['Type'];
				$null    = ($col['Null'] == 'NO') ? 'NOT NULL' : 'NULL';
				$default = ($col['Default']) ? "DEFAULT {$col['Default']}" : "";

				$col_opt['extras'][] = "ALTER TABLE `{$tbl}` MODIFY `{$field}` {$type} {$null} {$default} AUTO_INCREMENT;";
			}
		}


		# set query to create table
		$backup .= "CREATE TABLE `{$tbl}` (".implode(",\n", $col_opt['options']).");";

		# set indexes
		$backup .= "\n\n";
		$backup .= implode("\n", $col_opt['extras']);

		# get table content
		$content = $_sql->prepare("SELECT * FROM {$tbl}");
		$content->execute();
		$content = $content->fetchAll(PDO::FETCH_ASSOC);
		$data    = array();

		foreach($content as $cnt) {
			$cnt    = array_map(function($value) { return "'".addslashes($value)."'"; }, $cnt);
			$data[] = "(".implode(',', $cnt).")";
		}

		# create insert query
		if(count($data) > 0) {
			$backup .= "INSERT INTO `{$tbl}`(".implode(",", $col_opt['names']).") VALUES \n". implode(",\n", $data) .";";
		}
	}

	# get triggers
	$triggers = $_sql->prepare("SHOW TRIGGERS");
	$triggers->execute();
	$triggers = $triggers->fetchAll(PDO::FETCH_ASSOC);

	foreach($triggers as $t) {
		$backup .= "\n\n";
		$backup .= "DELIMITER $$
		CREATE TRIGGER `{$t['Trigger']}` {$t['Timing']} {$t['Event']} ON `{$t['Table']}` FOR EACH ROW
		{$t['Statement']}
		$$
		DELIMITER ;";
	}

	# save .sql file
	$dir  = $_SERVER['DOCUMENT_ROOT'];
	$file = date("d-m-Y"). "_backup.sql";
	$open = fopen($dir. '/' .$file, "w+");
			fwrite($open, $backup);
			fclose($open);
?>