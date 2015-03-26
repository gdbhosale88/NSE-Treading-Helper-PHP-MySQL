<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Calendar extends CI_Controller {
	
    var $base_url;
    var $base_path;
    var $stock_files;
    var $data;
    var $dbCom;
    
    public function __construct() {
        parent::__construct();
        
        $this->base_url = $this->config->item("base_url");
		$this->base_path = $this->config->item("base_path");
		$this->stock_files = $this->config->item("stock_files");
        $this->data = array();
        $this->data['base_url'] = $this->base_url;
        $this->data['load_from'] = "calendar";

        $this->db = $this->load->database('default', TRUE);
        $config['hostname'] = "localhost";
        $config['username'] = "root";
        $config['password'] = "root";
        $config['database'] = "nse_tread_companies";
        $config['dbdriver'] = "mysqli";
        $config['dbprefix'] = "";
        $config['pconnect'] = FALSE;
        $config['db_debug'] = TRUE;
        $config['cache_on'] = FALSE;
        $config['cachedir'] = "";
        $config['char_set'] = "utf8";
        $config['dbcollat'] = "utf8_general_ci";
        $this->dbCom = $this->load->database($config, TRUE);
            
        $this->load->library('csvreader');
    }
    
    public function testIO() {
        //echo "Hi<br>";
        $field = json_decode('{"INSTRUMENT":"OPTIDX","SYMBOL":"NIFTY","EXP_DATE":"26\/03\/2015","STR_PRICE":"00004200.00","OPT_TYPE":"CE","OPEN_PRICE":"00004425.60","HI_PRICE":"00004428.75","LO_PRICE":"00004370.00","CLOSE_PRICE":"00004379.65","OPEN_INT*":"000000000049875","TRD_QTY":"17850","NO_OF_CONT":"714","NO_OF_TRADE":"136","NOTION_VAL":"153511166.25","PR_VAL":"78541166.25"}', true);
        //print_r($field);
        $this->processIOStock($field);
        echo $this->data['message'];
    }

    

    public function index() {
        $query = $this->db->get('companies');
        if($query->num_rows() > 0) {
            $year = $this->input->get("year", TRUE);
            if(isset($year) && $year != "") {
                $this->data['cnt_year'] = $year;
            } else {
                $this->data['cnt_year'] = date("Y");
            }

            $this->db->where("date >= ", "".$this->data['cnt_year']."-01-01");
            $this->db->where("date <= ", "".$this->data['cnt_year']."-12-31");
            $q = $this->db->get("date_reports");
            $days = array();
            foreach($q->result_array() as $day) {
                $days[$day['date']] = $day;
            }
            //print_r($days);
            $this->data['report_days'] = $days;
            
            $this->data['main_content'] = __FUNCTION__;
            $this->load->view('template', $this->data);
        } else {
            $this->data['showError'] = array('title'=> "Company Data",
                'message' => "Company data not present in Database !!!
                    <br><br>Need initial setup. Click <a href='".$this->base_url."/home/init_setup'>here</a> to proceed. It will take around 4-6 Minutes depands on connection &amp; computer speed.");
            $this->data['main_content'] = __FUNCTION__;
            $this->load->view('template', $this->data);
        }
    }

    public function report_datewise() {
        $date = $this->input->get("date", TRUE);
        $arr = explode("-", $date);
        $date = "20".$arr[2]."-".$arr[1]."-".$arr[0];

        $this->db->where("date", $date);
        $query = $this->db->get('date_reports');
        if($query->num_rows() == 1) {
            $this->data['date'] = $date;
            $this->data['report'] = $query->row_array();
            $this->data['report']['data'] = json_decode($this->data['report']['data']);

            $this->data['main_content'] = __FUNCTION__;
            $this->load->view('template', $this->data);
        } else {
            $this->data['showError'] = array('title'=> "Company Data", 'message' => "Company data not present in Database !!!");
            $this->data['main_content'] = __FUNCTION__;
            $this->load->view('template', $this->data);
        }
    }

    public function update_db_datewise() {
        $date = $this->input->get("date", TRUE);
        $query = $this->db->get('companies');
        if($query->num_rows() > 0) {
            $this->data['date'] = $date;

            // 26-02-15
            $this->loadDaywiseReport($date);

            $this->data['main_content'] = __FUNCTION__;
            $this->load->view('template', $this->data);
        } else {
            $this->data['showError'] = array('title'=> "Company Data", 'message' => "Company data not present in Database !!!");
            $this->data['main_content'] = __FUNCTION__;
            $this->load->view('template', $this->data);
        }
    }

    function loadDaywiseReport($day) {
        $this->data['message'] = "Load Data for date : (".$day.")<br>";
        $this->data['error'] = "";

        $status = "";
        $data1 = array('file_name' => '', 'file_offline_status' => "false", 'file_download_status' => "false", "data_companies" => array(), "new_companies" => array(), "error_message" => "");

        $arr = explode("-", $day);
        $day = $arr[0];
        $month = $arr[1];
        $year = $arr[2];

        $filename = "PR".$day.$month.$year.".zip";

        $data1['file_name'] = $filename;
        
        // do we have file ?
        $fileExists = false;
        if(file_exists($this->base_path . "/stock_files/".$filename) && filesize($this->base_path . "/stock_files/".$filename) > 1000) {
            $fileExists = true;
            $data1['file_offline_status'] = 'true';
        }

        if(!$fileExists) {
            $data1['file_offline_status'] = 'false';

            $path = "http://www.nseindia.com/archives/equities/bhavcopy/pr/".$filename;

            $zipResource = fopen($this->base_path . "/stock_files/".$filename, "w");

            $arr1 = downloadFile($path, $zipResource);
            $page = $arr1['page'];
            
            if(!$page) {
                $data1['file_download_status'] = "false";

                $this->data['error'] .= "File failed: ".$path." : ".$arr1['curl_error_str']."\n<br>";
                log_message("error", "File failed: ".$path." : ".$arr1['curl_error_str']);
                
                $data1['error_message'] = "File Download failed: ".$path." : ".$arr1['curl_error_str'];

                $this->db->where("date", "20".$year."-".$month."-".$day);
                $q = $this->db->get("date_reports");
                if($q->num_rows() == 0) {
                    $arr2 = array('date' => "20".$year."-".$month."-".$day, 'status' => "FAILED", 'tot_com_load' => 0, 'new_com_load' => 0, 'data' => json_encode($data1));
                    $this->db->insert('date_reports', $arr2);
                } else {
                    $arr2 = array('status' => "FAILED", 'tot_com_load' => 0, 'new_com_load' => 0, 'data' => json_encode($data1), 'time_updated' => date("Y-m-d H:i:s"));
                    $this->db->where("date", "20".$year."-".$month."-".$day);
                    $this->db->update('date_reports', $arr2);
                }
                return 0;
            } else {
                $data1['file_download_status'] = "true";
                $this->data['message'] .= "Got file: ".$path."\n<br>";
                log_message("debug", "Got file: ".$path." : ".$arr1['curl_error_str']);
            }
        }
        emptyFolder($this->base_path."/stock_files/temp");
        $this->data['error'] .= unzipFile($this->base_path . "/stock_files/".$filename, $this->base_path."/stock_files/temp");

        // load daily company data to their db
        $csvData = $this->csvreader->parse_file($this->base_path."/stock_files/temp/Pd".$day.$month.$year.".csv");

        $comUpdated = 0;
        $comLateUpdated = 0;
        foreach($csvData as $field) {
            //$this->data['message'] .= "".$field['SYMBOL']."<br>";
            if($field['SERIES'] == "EQ") {
                $tableName = processSymbol($field['SYMBOL']);

                // check if value already exists
                if ($this->dbCom->table_exists($tableName)) {

                    // check if date values already exists
                    $this->dbCom->where("date", "20".$year."-".$month."-".$day);
                    $q = $this->dbCom->get($tableName);
                    if($q->num_rows() == 0) {
                        $da = array(
                            'date' => "20".$year."-".$month."-".$day,
                            'open_price' => $field['OPEN_PRICE'],
                            'high_price' => $field['HIGH_PRICE'],
                            'low_price' => $field['LOW_PRICE'],
                            'close_price' => $field['CLOSE_PRICE']
                        );
                        //echo json_encode($da);
                        $this->dbCom->insert($tableName, $da);
                    } else {
                        // update
                        $da = array(
                            'open_price' => $field['OPEN_PRICE'],
                            'high_price' => $field['HIGH_PRICE'],
                            'low_price' => $field['LOW_PRICE'],
                            'close_price' => $field['CLOSE_PRICE']
                        );
                        //echo json_encode($da);
                        $this->dbCom->where("date", "20".$year."-".$month."-".$day);
                        $this->dbCom->update($tableName, $da);
                    }

                    $data1['data_companies'][] = $tableName;

                    $comUpdated = $comUpdated + 1;
                } else {
                    if($tableName != "") {
                        $this->data['message'] .= "Table not exists: (".$field['SYMBOL']."-".$tableName.")<br>";
                        $da = array(
                            'symbol' => $field['SYMBOL'],
                            'table' => $tableName,
                            'isin' => ""
                        );
                        $this->db->insert('companies', $da);
                        
                        $this->dbCom->query("CREATE TABLE IF NOT EXISTS ".$tableName." (
                            id int(11) AUTO_INCREMENT,
                            date date NOT NULL,
                            open_price int(11) DEFAULT 0,
                            high_price int(11) DEFAULT 0,
                            low_price int(11) DEFAULT 0,
                            close_price int(11) DEFAULT 0,
                            PRIMARY KEY (id)
                        )");
                        $da = array(
                            'date' => "20".$year."-".$month."-".$day,
                            'open_price' => $field['OPEN_PRICE'],
                            'high_price' => $field['HIGH_PRICE'],
                            'low_price' => $field['LOW_PRICE'],
                            'close_price' => $field['CLOSE_PRICE']
                        );
                        //echo json_encode($da);
                        $this->dbCom->insert($tableName, $da);
                        $comLateUpdated = $comLateUpdated + 1;

                        $data1['data_companies'][] = $tableName;
                        $data1['new_companies'][] = $tableName;

                    } else {
                        $da = array(
                            'date' => "20".$year."-".$month."-".$day,
                            'open_price' => $field['OPEN_PRICE'],
                            'high_price' => $field['HIGH_PRICE'],
                            'low_price' => $field['LOW_PRICE'],
                            'close_price' => $field['CLOSE_PRICE']
                        );
                        $this->data['message'] .= "Table not exists + Blank: (".$field['SYMBOL']."-".$tableName.")".json_encode($da)."<br>";
                    }
                }
            }
        }
        $this->data['message'] .= "Total Updated: ".$comUpdated." * ".$comLateUpdated."<br>";

        $data1['tot_com'] = $comUpdated;
        $data1['new_com'] = $comLateUpdated;

        // Load Index Option Data
        $file_fo = "fo".$day.$month."20".$year.".zip";
        if(file_exists($this->base_path . "/stock_files/temp/".$file_fo)) {
            $this->data['message'] .= "Option File ".$file_fo." Exists !!!<br>";
            $this->data['error'] .= unzipFile($this->base_path . "/stock_files/temp/".$file_fo, $this->base_path."/stock_files/temp/fo");

            $file_fom = "op".$day.$month."20".$year.".csv";
            if(file_exists($this->base_path . "/stock_files/temp/fo/".$file_fom)) {
                // If Option csv exists -> process it
                $csvData = $this->csvreader->parse_file($this->base_path."/stock_files/temp/fo/".$file_fom);
                foreach($csvData as $field) {
                    $field = trimCSVRow($field);
                    if(isset($field['SYMBOL']) && ( $field['SYMBOL'] == "NIFTY" || $field['SYMBOL'] == "BANKNIFTY")) {
                        //$this->data['message'] .= json_encode($field)."<br>";
                        $this->processIOStock("20".$year."-".$month."-".$day, $field);
                    }
                }
            } else {
                $this->data['message'] .= "Option File ".$file_fom." not Exists !!!<br>";
                $data1['error_message'] = "Option File ".$file_fom." not Exists !!!";

                $this->db->where("date", "20".$year."-".$month."-".$day);
                $q = $this->db->get("date_reports");
                if($q->num_rows() == 0) {
                    $arr2 = array('date' => "20".$year."-".$month."-".$day, 'status' => "FAILED", 'tot_com_load' => $comUpdated, 'new_com_load' => $comLateUpdated, 'data' => json_encode($data1));
                    $this->db->insert('date_reports', $arr2);
                } else {
                    $arr2 = array('status' => "FAILED", 'tot_com_load' => $comUpdated, 'new_com_load' => $comLateUpdated, 'data' => json_encode($data1), 'time_updated' => date("Y-m-d H:i:s"));
                    $this->db->where("date", "20".$year."-".$month."-".$day);
                    $this->db->update('date_reports', $arr2);
                }
                return 0;
            }

        } else {
            $this->data['message'] .= "Option Zip File ".$file_fo." not Exists !!!<br>";
            $data1['error_message'] = "Option Zip File ".$file_fo." not Exists !!!";

            $this->db->where("date", "20".$year."-".$month."-".$day);
            $q = $this->db->get("date_reports");
            if($q->num_rows() == 0) {
                $arr2 = array('date' => "20".$year."-".$month."-".$day, 'status' => "FAILED", 'tot_com_load' => $comUpdated, 'new_com_load' => $comLateUpdated, 'data' => json_encode($data1));
                $this->db->insert('date_reports', $arr2);
            } else {
                $arr2 = array('status' => "FAILED", 'tot_com_load' => $comUpdated, 'new_com_load' => $comLateUpdated, 'data' => json_encode($data1), 'time_updated' => date("Y-m-d H:i:s"));
                $this->db->where("date", "20".$year."-".$month."-".$day);
                $this->db->update('date_reports', $arr2);
            }
            return 0;
        }
        //$this->data['error'] .= unzipFile($this->base_path . "/stock_files/".$filename, $this->base_path."/stock_files/temp");

        $this->db->where("date", "20".$year."-".$month."-".$day);
        $q = $this->db->get("date_reports");
        if($q->num_rows() == 0) {
            $arr2 = array('date' => "20".$year."-".$month."-".$day, 'status' => "SUCCESS", 'tot_com_load' => $comUpdated, 'new_com_load' => $comLateUpdated, 'data' => json_encode($data1));
            $this->db->insert('date_reports', $arr2);
        } else {
            $arr2 = array('status' => "SUCCESS", 'tot_com_load' => $comUpdated, 'new_com_load' => $comLateUpdated, 'data' => json_encode($data1), 'time_updated' => date("Y-m-d H:i:s"));
            $this->db->where("date", "20".$year."-".$month."-".$day);
            $this->db->update('date_reports', $arr2);
        }
    }

    private function processIOStock($date, $row) {
        $folder = $this->base_path . "/idx_opt/";
        $stype = "N";
        if($row['SYMBOL'] == "BANKNIFTY") {
            $stype = "B";
        }
        $otype = "C";
        if($row['OPT_TYPE'] == "PE") {
            $otype = "P";
        }
        // convert to numbers
        $sprice = intval($row['STR_PRICE']);
        $row['OPEN_PRICE'] = floatval($row['OPEN_PRICE']);
        $row['HI_PRICE'] = floatval($row['HI_PRICE']);
        $row['LO_PRICE'] = floatval($row['LO_PRICE']);
        $row['CLOSE_PRICE'] = floatval($row['CLOSE_PRICE']);
        $row['OPEN_INT'] = floatval($row['OPEN_INT']);
        $row['NOTION_VAL'] = floatval($row['NOTION_VAL']);
        $row['TRD_QTY'] = floatval($row['TRD_QTY']);


        $arr = explode("/", $row['EXP_DATE']);
        $eYear = $arr[2];
        $eMonth = $arr[1];
        $eDate = $arr[0];
        //$this->data['message'] = "<br>";
        if(!file_exists($folder.$eYear)) {
            mkdir($folder.$eYear);
            //$this->data['message'] .= "Year ".$eYear." folder created. <br>";
            mkdir($folder.$eYear."/".$eMonth);
        }
        if(!file_exists($folder.$eYear."/".$eMonth)) {
            mkdir($folder.$eYear."/".$eMonth);
            //$this->data['message'] .= "Month ".$eMonth." folder created. <br>";
        }
        $fname = $stype.$eYear.$eMonth.$eDate.$otype.$sprice.".csv";

        $fexists = "false";
        $matchf = "false";
        
        if(!file_exists($folder.$eYear."/".$eMonth."/".$fname)) {
            $fexists = "false";
            // create option index file if not exists
            $file = fopen($folder.$eYear."/".$eMonth."/".$fname, "w");
            fputcsv($file, explode(",", "CNT_DATE,OPEN_PRICE,HI_PRICE,LO_PRICE,CLOSE_PRICE,OPEN_INT,NOTION_VAL,TRD_QTY,"));
            fputcsv($file, explode(",", "".$date.",".$row['OPEN_PRICE'].",".$row['HI_PRICE'].",".$row['LO_PRICE'].",".$row['CLOSE_PRICE'].",".$row['OPEN_INT'].",".$row['NOTION_VAL'].",".$row['TRD_QTY'].","));
            fclose($file);
        } else {
            $fexists = "true";
            $contents = file_get_contents($folder.$eYear."/".$eMonth."/".$fname);
            $pattern = preg_quote("".$date, '/');
            $pattern = "/^.*$pattern.*\$/m";
            if(!preg_match_all($pattern, $contents, $matches)) {
                // write to file
                $file = fopen($folder.$eYear."/".$eMonth."/".$fname, "a");
                fputcsv($file, explode(",", "".$date.",".$row['OPEN_PRICE'].",".$row['HI_PRICE'].",".$row['LO_PRICE'].",".$row['CLOSE_PRICE'].",".$row['OPEN_INT'].",".$row['NOTION_VAL'].",".$row['TRD_QTY'].","));
                fclose($file);

                $this->sortCsv($folder.$eYear."/".$eMonth."/".$fname);
            } else {
                $matchf = "true";
            }
        }
        //$this->data['message'] .= "File: $fname ($fexists), Match: ($matchf)  <br>";
    }

    public function sortCsv($filename) {
        $dates = array();
        $csvData = $this->csvreader->parse_file($filename);
        foreach($csvData as $field) {
            $dates[] = $field['CNT_DATE'];
        }
        array_multisort($dates, $csvData);
        $file = fopen($filename, "w");
        fputcsv($file, explode(",", "CNT_DATE,OPEN_PRICE,HI_PRICE,LO_PRICE,CLOSE_PRICE,OPEN_INT,NOTION_VAL,TRD_QTY,"));
        foreach($csvData as $row) {
            fputcsv($file, explode(",", "".$row['CNT_DATE'].",".$row['OPEN_PRICE'].",".$row['HI_PRICE'].",".$row['LO_PRICE'].",".$row['CLOSE_PRICE'].",".$row['OPEN_INT'].",".$row['NOTION_VAL'].",".$row['TRD_QTY'].","));
        }
        fclose($file);
    }
}