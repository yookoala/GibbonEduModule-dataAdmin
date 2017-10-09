<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Modules\DataAdmin\ImportType;

//Increase max execution time, as this stuff gets big
ini_set('max_execution_time', 600);

// Gibbon Bootstrap
include __DIR__ . '/../../gibbon.php';
include __DIR__ . '/../../version.php';

// Module Bootstrap
require __DIR__ . '/module.php';

if (isActionAccessible($guid, $connection2, "/modules/Data Admin/export_run.php")==FALSE) {
	// Acess denied
	print '<div class="error">';
		print __('You do not have access to this action.');
	print '</div>';
}
else {

	$dataExport = (isset($_GET['data']) && $_GET['data'] == true);
	$dataExportAll = (isset($_GET['all']) && $_GET['all'] == true);

	// Get the importType information
	$type = (isset($_GET['type']))? $_GET['type'] : '';
	$importType = ImportType::loadImportType( $type, $pdo );

	$checkUserPermissions = getSettingByScope($connection2, 'Data Admin', 'enableUserLevelPermissions');

	if ($checkUserPermissions == 'Y' && $importType->isImportAccessible($guid, $connection2) == false) {
		print "<div class='error'>" ;
		print __("You do not have access to this action.") ;
		print "</div>" ;
		return;
	} else if ( empty($importType)  ) {
		print "<div class='error'>" ;
		print __("Your request failed because your inputs were invalid.") ;
		print "</div>" ;
		return;
	} else if ( !$importType->isValid() ) {
		print "<div class='error'>";
		printf( __('Import cannot proceed, as the selected Import Type "%s" did not validate with the database.', 'Data Admin'), $type) ;
		print "<br/></div>";
		return;
	}

	/** Include PHPExcel */
	require_once $_SESSION[$guid]["absolutePath"] . '/lib/PHPExcel/Classes/PHPExcel.php';

	// Create new PHPExcel object
	$excel = new PHPExcel();

	//Create border styles
	$style_head_fill= array(
		'fill' => array('type' => PHPExcel_Style_Fill::FILL_SOLID, 'color' => array('rgb' => 'eeeeee')),
		'borders' => array('top' => array('style' => PHPExcel_Style_Border::BORDER_THIN, 'color' => array('argb' => '444444'), ), 'bottom' => array('style' => PHPExcel_Style_Border::BORDER_THIN, 'color' => array('argb' => '444444'), )),
	);

	// Set document properties
	$excel->getProperties()->setCreator(formatName("",$_SESSION[$guid]["preferredName"], $_SESSION[$guid]["surname"], "Staff"))
		 ->setLastModifiedBy(formatName("",$_SESSION[$guid]["preferredName"], $_SESSION[$guid]["surname"], "Staff"))
		 ->setTitle( __("Activity Attendance") )
		 ->setDescription(__('This information is confidential. Generated by Gibbon (https://gibbonedu.org).')) ;

	$excel->setActiveSheetIndex(0) ;

	$tableName = $importType->getDetail('table');
	$primaryKey = $importType->getPrimaryKey();

	$queryFields = array();
	$columnFields = array();

	foreach ($importType->getTableFields() as $fieldName ) {
		if ($importType->isFieldHidden($fieldName)) continue; // Skip hidden fields
		
		$columnFields[] = $fieldName;

		if ($importType->isFieldReadOnly($fieldName) && $dataExport == true) continue; // Skip readonly fields when exporting data
		
		$queryFields[] = $fieldName;
	}

	if ($dataExport && !empty($primaryKey)) {
		$queryFields = array_merge( array($primaryKey), $queryFields);
		$columnFields = array_merge( array($primaryKey), $columnFields);
	}

	// Create the header row
	$count = 0;
	foreach ($columnFields as $fieldName ) {
		
		$excel->getActiveSheet()->setCellValue( num2alpha($count).'1', $importType->getField($fieldName, 'name', $fieldName ) );
		$excel->getActiveSheet()->getStyle( num2alpha($count).'1')->applyFromArray($style_head_fill);

		// Dont auto-size giant text fields
		if ( $importType->getField($fieldName, 'kind') == 'text' ) {
			$excel->getActiveSheet()->getColumnDimension( num2alpha($count) )->setWidth(25);
		} else {
			$excel->getActiveSheet()->getColumnDimension( num2alpha($count) )->setAutoSize(true);
		}

		// Add notes to column headings
		$info = ($importType->isFieldRequired($fieldName))? "* required\n" : '';
		$info .= $importType->readableFieldType($fieldName)."\n";
		$info .= $importType->getField($fieldName, 'desc', '' );

		if (!empty($info)) {
			$excel->getActiveSheet()->getComment( num2alpha($count).'1' )->getText()->createTextRun( $info );
		}

		$count++;
	}

	
	if ($dataExport) {
		// Get the data
		$data=array(); 
		$sql="SELECT ".implode(', ', $queryFields)." FROM `{$tableName}`" ;

		if ($dataExportAll == false) {
			// Optionally limit all exports to the current school year by default, to avoid massive files
			$gibbonSchoolYearID = $importType->getField('gibbonSchoolYearID', 'name', null);
			
			if ($gibbonSchoolYearID != null && $importType->isFieldReadOnly('gibbonSchoolYearID') == false ) {
				$data['gibbonSchoolYearID'] = $_SESSION[$guid]['gibbonSchoolYearID'];
				$sql.= " WHERE gibbonSchoolYearID=:gibbonSchoolYearID ";
			}
		}

		$sql.= " ORDER BY $primaryKey ASC";
		$result = $pdo->executeQuery($data, $sql);

		// Continue if there's data
		if ($result && $result->rowCount() > 0) {

			// Build some relational data arrays, if needed (do this first to avoid duplicate queries per-row)
			$relationalData = array();

			foreach ($columnFields as $fieldName) {

				if ($importType->isFieldRelational($fieldName)) {

					extract( $importType->getField($fieldName, 'relationship') );
					$queryFields = (is_array($field))? implode(',', $field) : $field;

					// Build a query to grab data from relational tables
					$relationalSQL = "SELECT `{$table}`.`{$key}` id, {$queryFields} FROM `{$table}`";

					if (!empty($join) && !empty($on)) {
                        if (is_array($on) && count($on) == 2) {
                            $relationalSQL .= " JOIN {$join} ON ({$join}.{$on[0]}={$table}.{$on[1]})";
                        }
                    }

					$resultRelation = $pdo->executeQuery(array(), $relationalSQL);

					if ($resultRelation->rowCount() > 0) {

						// Fetch into an array as:  id => array( field => value, field => value, ... )
						$relationalData[$fieldName] = $resultRelation->fetchAll(\PDO::FETCH_GROUP|\PDO::FETCH_UNIQUE);
	            	}
				}
			}

			// print '<pre>';
			// print_r($relationalData);
			// print '</pre>';

			$rowCount = 2;
			while ($row = $result->fetch()) {

				// Work backwards, so we can potentially fill in any relational read-only fields 
				for ($i=count($columnFields)-1; $i >= 0; $i--) {

					$fieldName = $columnFields[$i];
				
					$value = (isset($row[ $fieldName ]))? $row[ $fieldName ] : null;

					// Handle relational fields
					if ($importType->isFieldRelational($fieldName)) {

						extract( $importType->getField($fieldName, 'relationship') );

						// Single key relational field -- value is the ID from other table
						$relationalField = (is_array($field))? $field[0] : $field;
						$relationalValue = @$relationalData[$fieldName][$value][$relationalField];

						// Multi-key relational field (fill in backwards, slightly hacky but works)
						if (is_array($field) && count($field) > 1) {
							for ($n=1; $n < count($field); $n++) {
								$relationalField = $field[$n];

								// Does the field exist in the import definition but not in the current table?
								// Add the value to the row to fill-in the link between read-only relational fields
								if ( $importType->isFieldReadOnly($relationalField) ) {
									$row[ $relationalField ] = @$relationalData[$fieldName][$value][$relationalField];
								}
							}
						}
						
						// Replace the relational ID value with the actual value
						$value = $relationalValue;
					}

					// Set the cell value
					$excel->getActiveSheet()->setCellValue( num2alpha($i).$rowCount, $value );
				}

				$rowCount++;
			}
		}

	}

	$filename = ($dataExport)? __("DataExport", 'Data Admin').'-'.$tableName : __("DataStructure", 'Data Admin').'-'.$type;

	$exportFileType = getSettingByScope($connection2, 'Data Admin', 'exportDefaultFileType');
	if (empty($exportFileType)) $exportFileType = 'Excel2007';

	switch($exportFileType) {
		case 'Excel2007': 		$filename .= '.xlsx'; 
								$mimetype = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; break;
		case 'Excel5': 			$filename .= '.xls';  
								$mimetype = 'application/vnd.ms-excel'; break;
		case 'OpenDocument': 	$filename .= '.ods';  
								$mimetype = 'application/vnd.oasis.opendocument.spreadsheet'; break;
		case 'CSV': 			$filename .= '.csv';  
								$mimetype = 'text/csv'; break;
	}

	//FINALISE THE DOCUMENT SO IT IS READY FOR DOWNLOAD
	// Set active sheet index to the first sheet, so Excel opens this as the first sheet
	$excel->setActiveSheetIndex(0);

	// Redirect output to a client’s web browser (Excel2007)
	header('Content-Type: '.$mimetype);
	header('Content-Disposition: attachment;filename="'.$filename.'"');
	header('Cache-Control: max-age=0');
	// If you're serving to IE 9, then the following may be needed
	header('Cache-Control: max-age=1');

	// If you're serving to IE over SSL, then the following may be needed
	header ('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
	header ('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT'); // always modified
	header ('Cache-Control: cache, must-revalidate'); // HTTP/1.1
	header ('Pragma: public'); // HTTP/1.0

	$objWriter = PHPExcel_IOFactory::createWriter($excel, $exportFileType);
	$objWriter->save('php://output');
	exit;
}	
?>