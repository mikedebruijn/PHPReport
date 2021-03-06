<?php

require_once __DIR__ . '/PHPExcel/Classes/PHPExcel.php';
require_once __DIR__ . '/Evaluator.php';

/*
 * Sorts an array SQL-style, that is, $order is a string like col1 DESC, col2 ASC, ... , colN DESC
 * The array must have rows indexed with keys corresponding to the column names in the $order clause.
 * Anything other than ASC in the $order clause is treated as DESC
 */
function sql_sort_array($array, $order)
{
	$cmp = function($a, $b)
	{
		if($a < $b)return -1;
		else if($b < $a)return 1;
		else return 0;
	};

	$column_order = array_map(function($o){
		
		$tmp = explode(' ', trim($o));
		return array('column' => $tmp[0], 'order' => ($tmp[1]=='ASC' ? 1 : -1));
	},explode(',', $order));

	

	uasort($array, function($a, $b) use ($cmp, $column_order){
		foreach($column_order as $o)
		{
			$r = $cmp($a[$o['column']],$b[$o['column']]);
			if($r != 0)return $r * $o['order'];
		}
		return 0;
	});
	
	return $array;
}

class Report
{
	public $connections = array();
	public $datasets    = array();
	public $views       = array();

	public function replaceVars($str)
	{
		return str_replace('$now', date('Y-m-d'), $str);
	}

	public function load($path)
	{
		$report = new SimpleXMLElement(file_get_contents($path));

		$this->name = (string)$report['name'];
		
		foreach($report->connection as $connection)
		{
			$this->connections[(string)$connection['name']] = array(
					'driver'   => (string)($connection['driver'] ? $connection['driver'] : 'pdo_mysql'),
					'host'     => (string) $connection['host'],
					'dbname'   => (string) $connection['dbname'],
					'user'     => (string) $connection['user'],
					'password' => (string) $connection['password']
			);
		}

		foreach($report->dataset as $dataset)
		{
			$this->datasets[(string)$dataset['name']] = array(
				"connection" => (string)$dataset['connection'],
				"query"      => (string)$dataset->query
			);
		}

		foreach($report->view as $view)
		{
			$v = array();

			$v['type'] 		= (string)($view['type'] ? $view['type'] : 'table');
			$v['dataset'] 	= (string)$view['dataset'];
			$v['row'] 		= (int)$view['row'];
			$v['column'] 	= (int)$view['column'];
			if(isset($view['order']))$v['order'] = (string)$view['order'];
			if(isset($view['limit']))$v['limit'] = (int)   $view['limit'];

			$v['highlight-rows'] = array();
			foreach($view->{"highlight-rows"} as $hl)
			{
				$f = array('condition' => (string)$hl['if']);
				
				if(isset($hl['background-color']))$f['background-color'] = (string)$hl['background-color'];
				if(isset($hl['color']))$f['color'] = (string)$hl['color'];
				//echo "<pre>"; print_r($f); echo "</pre>";
				$v['highlight-rows'][] = $f;
			}

			if($v['type'] == 'table')
			{
				$columns = array();
				foreach($view->column as $column)
				{
					$columns[] = array(
						'field'   => (string)$column['field'],
						'display' => (string)($column['display'] ? $this->replaceVars($column['display']) : $column['field']),
						'style'   => $column['style'] ? array_map('trim',explode(',',(string)$column['style'])) : array()
					);
				}
				$v['columns'] = $columns;
			}
			
			$this->views[(string)$view['name']] = $v;
		}

		/*
		echo "<pre>";
		//print_r($this->connections);
		//print_r($this->datasets);
		print_r($this->views);
		echo "</pre>";//*/
	}

	public function compute()
	{
		$connections 	  = array();
		$this->data  	  = array();
		$this->views_data = array();

		foreach($this->views as $view_name => $view)
		{
			$dataset_name = $view['dataset'];

			if(!isset($this->data[$dataset_name]))
			{
				$connection_name = $this->datasets[$dataset_name]['connection'];
				if(!isset($connections[$connection_name]))
				{
					$cd  = $this->connections[$connection_name];
					$dns = end(explode('_',$cd['driver'])) . ':dbname=' . $cd['dbname'] . ';host=' . $cd['host']; 
					$connection = new PDO($dns, $cd['user'], $cd['password']);
				}
				else $connection = $connections[$connection_name];

				$data  = $connection->query($this->datasets[$dataset_name]['query'])->fetchAll();
				$this->data[$dataset_name] = $data;

			}
			else $data = $this->data[$dataset_name];

			if(isset($view['order']))
			{
				$data = sql_sort_array($data, $view['order']);
			}

			if(isset($view['limit']))
			{
				$data = array_slice($data, 0, $view['limit']);
			}

			
			foreach($data as &$row)
			{
				foreach($row as &$cell)
				{
					$cell = array('value' => $cell, 'computed-style' => array());
				}
			}
			
			$evaluators = array();
			foreach($view['highlight-rows'] as $hl)
			{
				$st = array();
				foreach($hl as $k => $v)if($k != 'condition')$st[$k]=$v;
				$evaluators[] = array('evaluator' => new Evaluator($hl['condition']), 'style' => $st);
			}
			if(count($evaluators) > 0)
			{
				$r = 0;
				foreach($data as &$row)
				{
					$r+=1;
					foreach($evaluators as $evaluator)
					{
						$val = $evaluator['evaluator']->evaluate($row);
						if($val == 1)
						{
							foreach($row as &$cell)
							{
								$cell['computed-style'][] = $evaluator['style'];
							}
						}
					}

					foreach($this->views[$view_name]['columns'] as $c)
					{
						$suffix = "";
						foreach($c['style'] as $s)
						{
							if($s == '%')
							{
								$row[$c['field']]['value'] = round(100*$row[$c['field']]['value'],2);
								$suffix = '%';
							}
							else if($s == 'pn')
							{
								if($row[$c['field']]['value'] < 0)
								{
									$row[$c['field']]['computed-style'][] = array('color' => '#660000');
								}
								else if($row[$c['field']]['value'] > 0)
								{
									$row[$c['field']]['computed-style'][] = array('color' => '#006600');
								}
							}
						}
						if(is_numeric($row[$c['field']]['value']))
						{
							$row[$c['field']]['computed-style'][] = array('text-align' => 'right');
							if(strpos($row[$c['field']]['value'], '.') === false)
								$row[$c['field']]['value'] = number_format((int)$row[$c['field']]['value']);
						}



						$row[$c['field']]['value'] .= $suffix;
					}

				}
			}

			$this->views_data[$view_name] = $data;
			
			/*
			echo "<pre>";
			print_r($data);
			echo "</pre>";*/

		}
	}


	public function writeExcelSheet($sheet)
	{
		$col = function($number)
		{
			return PHPExcel_Cell::stringFromColumnIndex($number - 1);
		};

		$cell = function($r, $c) use ($col)
		{
			return $col($c) . $r;
		};

		/* $style is an array of the form:
         * array(
         * 		'color' => '#RRGGBB',
         *      'background-color' => '#RRGGBB'
         * )
		 */
		$write = function($r, $c, $value, $style=null) use ($col, $cell, $sheet)
		{
			$coords = $cell($r,$c);
			$sheet->setCellValue($coords, $value);

			if(null !== $style)
			{
				$st = array();
				if(isset($style['background-color']))
				{
					$st['fill'] = array(
						'type' => PHPExcel_Style_Fill::FILL_SOLID,
						'color' => array('rgb' => substr($style['background-color'],1)) //remove the #
					);
				}
				if(isset($style['bold']) and $style['bold'])
				{
					$st['font'] = array('bold' => true);
				}
				if(isset($style['color']))
				{
					$color = new PHPExcel_Style_Color();
					$color->setRGB(substr($style['color'],1));
					$sheet->getStyle($coords)->getFont()->setColor($color);
				}
				if(isset($style['extra']))
				{
					foreach($style['extra'] as $k => $a)
					{
						$st[$k] = $a;
					}
				}
				$sheet->getStyle($coords)->applyFromArray($st);
			}

		};


		$this->views = sql_sort_array($this->views, "row ASC, column ASC");


		$current_row = false;

		//Table Absolute Top : Max height reached in previous row + 2
		$tat = 1;
		//Table Absolute Left
		$tal = 1; 
		
		$max_row_height = 0;
		$previous_table_width = 0;

		foreach($this->views as $view_name => $desc)
		{
			$data = $this->views_data[$view_name];
			
			$new_row = false;
			if(false === $current_row)
			{
				$current_row = $desc['row'];
				$new_row = true;
			}
			else if($desc['row'] != $current_row)
			{
				$current_row = $desc['row'];
				$new_row = true;
				$tat += $max_row_height + 3;
			}
			if($new_row)
			{
				$tal  = 1; //write at the beginning of the row
				$previous_table_width = 0;
			}
			else
			{
				$tal += $previous_table_width + 1;
			}


			//write title
			$write($tat, $tal, $view_name, array('bold' => true, 'color' => '#C50747', 'background-color' => '#FFC14F'));
			$sheet->mergeCells($cell($tat, $tal) . ':' . $cell($tat,$tal+count($desc['columns'])-1));
			//write headers
			$loff = 0;
			foreach($desc['columns'] as $c)
			{
				$borders = array();
				foreach(array('top', 'bottom', 'left', 'right') as $b)
				{
					$borders[$b] = array('style' => PHPExcel_Style_Border::BORDER_HAIR);
				}
				$style = array('extra' => array('borders' => $borders));
				$style['background-color'] = "#FFC14F";
				$style['bold'] = true;

				$write($tat + 1, $tal + $loff, $c['display'], $style);
				$loff += 1;
			}

			//write data
			$toff = 2;
			foreach($data as $line)
			{
				$loff = 0;
				foreach($desc['columns'] as $c)
				{
					$style = array('extra' => array('borders' => array('bottom' => array('style' => PHPExcel_Style_Border::BORDER_HAIR))));
					foreach($line[$c['field']]['computed-style'] as $s)
					{
						foreach($s as $k => $v)
						{
							$style[$k] = $v;
						}
					}
					$write($tat + $toff, $tal + $loff, $line[$c['field']]['value'], $style);
					$loff += 1;
				}

				$toff += 1;
			}

			//put borders around table
			$borders = array();
			foreach(array('top', 'bottom', 'left', 'right') as $b)
			{
				$borders[$b] = array('style' => PHPExcel_Style_Border::BORDER_HAIR);
			}
			$sheet->getStyle($cell($tat,$tal).':'.$cell($tat + $toff - 1, $tal + count($desc['columns']) - 1))->applyFromArray(array('borders' => $borders));

			$previous_table_width  = count($desc['columns']);
			$row_height = count($data) + 1; //rows + header
			if($row_height > $max_row_height)$max_row_height = $row_height;

		}

	}

	public function renderAsExcel($send = false)
	{
		$xlsx = new PHPExcel();
	    $xlsx->setActiveSheetIndex(0);

	    $sheet = $xlsx->getActiveSheet();
	    $sheet->setShowGridlines(false);
	    
	    $this->writeExcelSheet($sheet);

	    $writer = new PHPExcel_Writer_Excel2007($xlsx);
	    if(!$send)ob_start();
	    $writer->save("php://output");
	    if(!$send)
	    {
	    	$contents = ob_get_contents();
		    ob_end_clean();
		    return $contents;
	    }
	}

	public function sendAsExcel()
	{
		header('Content-Description: File Transfer');
	    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	    header('Content-Disposition: attachment; filename=report.xlsx');
	    header('Content-Transfer-Encoding: binary');
	    header('Expires: 0');
	    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	    header('Pragma: public');
	    ob_clean();
	    flush();
	    $this->renderAsExcel(true);
	    exit;
	}

	public function renderAsHTML()
	{
		//sort by view position, top-to-bottom, left-to-right
		$this->views = sql_sort_array($this->views, "row ASC, column ASC");

		$html = "";

		$html .="
		<style>
		body{
			font-family: sans-serif;
		}
		.evenly-container {
		    text-align: justify;
		    -ms-text-justify: distribute-all-lines;
		    text-justify: distribute-all-lines;
		    font-size: 1px;
		    margin: 10px;
		    padding-left:50px;
		    padding-right:50px;
		}

		.evenly-box {
			margin-top:15px;
		    vertical-align: top;
		    display: inline-block;
		    *display: inline;
		    zoom: 1;
		    font-size:small;
		}

		.stretch {
		    width: 100%;
		    display: inline-block;
		    font-size: 0;
		    line-height: 0;
		}
		
		table.report-table
		{
				text-align:left;
				border:1px solid #bbb;
		}

		table.report-table th
		{
			background-color:#FFC14F;
		}

		table.report-table th, table.report-table td
		{
			padding-right:10px;
			font-size:small;
		}

		table.report-table tr:not(:first-child) th
		{
			border-bottom:2px solid black;
		}

		table.report-table tr:first-child th
		{
			color:#C50747;
		}

		table.report-table tr:not(:last-child) td
		{
			border-bottom:1px solid #ccc;
		}

		h1{
			display:block;
			text-align:center;
			padding:10px;
			background-color:#F0F0F0;
		}

		</style>



		";
		
		$html .= "<div>";
		$html .= "<h1>" . $this->name . "</h1>";

		$current_row = false;

		foreach($this->views as $view_name => $desc)
		{
			if(false === $current_row)
			{
				$current_row = $desc['row'];
				//new row
				$html .= "<div class='evenly-container'>";
			}
			else if($desc['row'] != $current_row)
			{
				$current_row = $desc['row'];
				//$html .= "<div style='clear:both'/>";
				//end previous row
				$html .= "<span class='stretch'></span>";
				$html .= "</div>";
				//new row
				$html .= "<div class='evenly-container'>";
			}

			$data = $this->views_data[$view_name];
			$vhtml = "";

			$vhtml .= "<div class='evenly-box'>";

			$vhtml .= "<table cellspacing='0' class='report-table'>";
			
			$vhtml .= "<tr>";
			$vhtml .= "<th colspan='" . count($desc['columns']) . "'>" . $view_name . "</th>";
			$vhtml .= "</tr>";

			$vhtml .= "<tr>";
				foreach($desc['columns'] as $col)
				{
					$vhtml .= "<th>";
					$vhtml .= $col['display'];
					$vhtml .= "</th>";
				}
			$vhtml .= "</tr>";

			foreach($data as $row)
			{
				$vhtml .= "<tr>";
				foreach($desc['columns'] as $col)
				{
					$style = '';
					foreach($row[$col['field']]['computed-style'] as $s)
					{
						foreach($s as $k => $v)
						{
							$style.= "$k:$v;";
						}
					}
					$vhtml .= "<td style='$style'>";
					$vhtml .= $row[$col['field']]['value'];
					$vhtml .= "</td>";
				}
				$vhtml .= "</tr>";
			}
			$vhtml .= "</table>";

			$vhtml .= "</div>\n";

			$html  .= $vhtml;

		}

		//end last row
		$html .= "<span class='stretch'></span>";
		$html .= "</div>";

		

		return $html;

	}

}