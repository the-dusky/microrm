<?php namespace MicrORM;

use PDO;
use PDOStatement;
use PDOException;

class MicroORM
{
    /**
     * @var PDO
     */
    private $connection;

    /**
     * @var PDOStatement
     */
    private $statement;


    private $host;
    private $name;
    private $user;
    private $pass;
    private $type = 'mysql';
    private $sql;
    private $param_list;
    private $param_array;
    private $param_chunk_count;
    private $insert_stmt_param_count;
    private $insert_stmt_param_binding_count;
    private $error_log_db = 'local';
    private $error_code;
    private $error_info;
    private $error_array;
    private $error_messages = [];



    /*private $error_tripped = 0;*/

    // CORE METHODS

    public function __construct($connection_name = 'local') {
        if(is_array($connection_name)) {
            $this->setDatabaseManual($connection_name);
        } else {
            $this->setDatabase($connection_name);
        }
    }

    public function connect() {
        $this->connection = null;
        try {
            /* var PDO $connection */
            $this->connection = new PDO("{$this->type}:host={$this->host};dbname=$this->name", $this->user, $this->pass,array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'"));
            /*** echo a message saying we have connected **/
        } catch (PDOException $e) {
            echo $e->getMessage();
        }
    }

    /**
     * @param mixed $error_log_db
     */
    public function setErrorLogDb($error_log_db): void
    {
        $this->error_log_db = $error_log_db;
    }

    public function setDatabase($connection_name): void
    {
        global $settings;
        $this->setDatabaseManual($settings['database'][$connection_name]);
    }

    public function setDatabaseManual($settings) {
        if(!empty($settings['name'])) {
            $this->name = $settings['name'];
        } elseif(!empty($settings['database'])) {
            $this->name = $settings['database'];
        }

        if(!empty($settings['user'])) {
            $this->user = $settings['user'];
        } elseif(!empty($settings['username'])) {
            $this->user = $settings['username'];
        }

        if(!empty($settings['pass'])) {
            $this->pass = $settings['pass'];
        } elseif(!empty($settings['password'])) {
            $this->pass = $settings['password'];
        }

        if(!empty($settings['ip'])) {
            $this->host = $settings['ip'];
        } elseif(!empty($settings['host'])) {
            $this->host = $settings['host'];
        }

        if(!empty($settings['type'])) {
            $this->type = $settings['type'];
        }
        $this->connect();
    }

    public function execute() {
        if(!$this->statement->execute()) {
            global $settings;
            $backtrace = debug_backtrace();
            if ($this->type != 'mysql') {

                $name = $this->name;
                $user = $this->user;
                $pass = $this->pass;
                $host = $this->host;
                $type = $this->type;

                $this->name = $settings['database'][$this->error_log_db]['name'];
                $this->user = $settings['database'][$this->error_log_db]['user'];
                $this->pass = $settings['database'][$this->error_log_db]['pass'];
                $this->host = $settings['database'][$this->error_log_db]['ip'];
                $this->type = $settings['database'][$this->error_log_db]['type'];
                $this->connect();

                $this->recordError($backtrace);

                $this->name = $name;
                $this->user = $user;
                $this->pass = $pass;
                $this->host = $host;
                $this->type = $type;
                $this->connect();

            } else {

                $this->recordError($backtrace);

            }

            return false;
        }
        return true;
    }

    public function recordError($backtrace = ''): void
    {
        if(ENV == 'production') {
            $error = $this->statement->errorInfo();
            $query = explode("\n", $this->statement->queryString);
            $query[0] = "                " . $query[0];
            foreach ($query as $key => $value) {
                $query[$key] = "|xx|" . $value . "|xx|";
            }
            $param_list = $this->param_list;
            $this->error_array = [
                'code_1' => $error[0],
                'code_2' => $error[1],
                'message' => $error[2],
                '1 - query' => $query,
                '2 - param_list' => $param_list,
                'backtrace_file' => $backtrace[1]['file'],
                'backtrace_line' => $backtrace[1]['line'],
                'backtrace_function' => $backtrace[2]['function'],
                'url' => CURRENT_URL
            ];
            $this->error_messages[] = $error[2];
            Sentry\captureMessage('Database Query Error', $this->error_array);
        } else {
            $error = $this->statement->errorInfo();
            $query = explode("\n", $this->statement->queryString);
            $query[0] = "                " . $query[0];
            foreach ($query as $key => $value) {
                $query[$key] = "|xx|" . $value . "|xx|";
            }
            $param_list = $this->param_list;
            $this->error_array = [
                'code_1' => $error[0],
                'code_2' => $error[1],
                'message' => $error[2],
                '1 - query' => $query,
                '2 - param_list' => $param_list,
                'backtrace_file' => $backtrace[1]['file'],
                'backtrace_line' => $backtrace[1]['line'],
                'backtrace_function' => $backtrace[2]['function'],
                'url' => CURRENT_URL
            ];
            $this->error_messages[] = "`DB ERROR: Message: {$this->error_array['message']}\nSQL:\n{$this->statement->queryString}\nLine: {$this->error_array['backtrace_line']}\nFile: {$this->error_array['backtrace_file']}\nFunction: {$this->error_array['backtrace_function']}`";
        }
    }

    public function query($sql, $ref_column = 'non_ref') {
        $this->param_list = '';
        $array = array();

        if(is_array($sql)) {

            //UPDATE SQL AND VALUES

            $this->sql = trim(str_replace("\t",'',$sql[0]));
            $this->statement = $this->connection->prepare($this->sql);
            $count = 1;
            if(isset($sql[1])) {
                foreach ($sql[1] as $value) {
                    switch (gettype($value)) {
                        case 'string':
                            $type = PDO::PARAM_STR;
                            break;
                        case 'integer':
                            $type = PDO::PARAM_INT;
                            break;
                        default:
                            $type = FALSE;
                    }
                    $this->statement->bindValue($count, $value, $type);
                    $this->param_list .= $count . " - " . $value . " - " . $type . ", ";
                    $count++;
                }
            }
        } else {
            $this->sql = trim(str_replace("\t",'',$sql));
            $this->statement = $this->connection->prepare($this->sql);
            $this->param_list .= ", ";
        }
        $this->param_list = rtrim($this->param_list, ", ");
        $this->execute();

        $record_set = $this->statement->fetchAll(PDO::FETCH_ASSOC);

        foreach($record_set as $assoc_result) {
            if(substr_count($ref_column,'|') == 2) {
                $array = $assoc_result[str_replace('|','',$ref_column)];
            } elseif(substr_count($ref_column,'|') == 3) {
                $explode_array = explode('|', $ref_column);
                $value_explode_array = explode(',', $explode_array[2]);
                $value_string = "";
                foreach($value_explode_array as $value) {
                    if(array_key_exists($value, $assoc_result)) {
                        $value_string .=  $assoc_result[$value];
                    } else {
                        $value_string .=  $value;
                    }
                }
                $array[$assoc_result[$explode_array[1]]] = $value_string;
            } elseif(substr_count($ref_column,'|') == 4) {
                $array[] = $assoc_result[str_replace('|','',$ref_column)];
            } elseif(substr_count($ref_column,'>') == 1) {
                $tiers_array = explode('>', $ref_column);
                $temp_array = [];
                foreach ($assoc_result as $key => $value) {
                    $old_key = $key;
                    $test = explode('_', $old_key);
                    if ($test[0] == $tiers_array[1]) {
                        if ($test[count($test) - 1] == 'rename') {
                            unset($test[0]);
                            array_pop($test);
                            $key = implode('_', $test);
                        }
                        $temp_array[$key] = $value;
                        /*if ($test[1] == 'id') {
                            $tier_two_id = $value;
                        }*/
                        unset($assoc_result[$old_key]);
                    }
                }
                if (array_key_exists($assoc_result[$tiers_array[0] . '_id'], $array)) {
                    $array[$assoc_result[$tiers_array[0] . '_id']][$tiers_array[1]][] = $temp_array;
                } else {
                    $array[$assoc_result[$tiers_array[0] . '_id']] = $assoc_result;
                    $array[$assoc_result[$tiers_array[0] . '_id']][$tiers_array[1]][] = $temp_array;
                }
            } elseif (substr_count($ref_column,'~') != 0) {
            } else {
                switch($ref_column) {
                    case 'single_row':
                        $array = $assoc_result;
                        break;
                    case 'non_ref':
                        $array[] = $assoc_result;
                        break;
                    default:
                        if(isset($assoc_result[$ref_column])) {
                            $array[$assoc_result[$ref_column]] = $assoc_result;
                        } else {
                            $array[] = $assoc_result;
                        }
                }
            }
        }
        if(isset($array)) {
            return $array;
        } else {
            return false;
        }
    }

    public function insertQuery($table, $values, $ip_and_date_time = 1) {
        $this->param_list = '';
        $this->param_array = [];
        $this->param_chunk_count = 0;
        if (!is_array(reset($values)) && $ip_and_date_time == 1) {
            $values['ip_address'] = USER_IP;
            $values['table_created_date_time'] = date('Y-m-d H:i:s');
            $values['table_updated_date_time'] = date('Y-m-d H:i:s');
        }
        $count = 0;
        $function_override = [];
        $sql = "INSERT INTO {$table} (";
        if(is_array(reset($values))) {
            foreach ($values as $key => $value) {
                $loop_count = 0;

                if ($ip_and_date_time == 1) {
                    $value['ip_address'] = USER_IP;
                    $value['table_created_date_time'] = date('Y-m-d H:i:s');
                    $value['table_updated_date_time'] = date('Y-m-d H:i:s');
                }
                $first = 0;
                if ($count == 0) {
                    $first = 1;
                }
                if ($first == 1) {
                    foreach ($value as $key => $value_sub) {
                        $sql .= $key . ", ";
                    }
                }
                $snapshot_count = $count;
                foreach ($value as $value_sub) {
                    $count++;
                    $loop_count++;
                    if (starts_with($value_sub, '|||') && ends_with($value_sub, '|||')) {
                        $function_override[$count] = str_replace('|||', '', $value_sub);
                    }
                }
                if ($first == 1) {
                    $sql = rtrim($sql, ", ") . ") VALUES (";
                } else {
                    $sql .= ", (";
                }
                for ($i = 1; $i <= $loop_count; $i++) {
                    if (isset($function_override[$snapshot_count + $i])) {
                        $sql .= $function_override[$snapshot_count + $i] . ", ";
                    } else {
                        $sql .= "?, ";
                    }
                }
                $sql = rtrim($sql, ", ") . ")";
            }
            $sql = rtrim($sql, ")") . ");";
        } else {
            foreach ($values as $key => $value) {
                $sql .= $key . ", ";
                $count++;
                if (starts_with($value, '|||') && ends_with($value, '|||')) {
                    $function_override[$count] = str_replace('|||', '', $value);
                }
            }
            $sql = rtrim($sql, ", ") . ") VALUES (";
            for ($i = 1; $i <= $count; $i++) {
                if (isset($function_override[$i])) {
                    $sql .= $function_override[$i] . ", ";
                } else {
                    $sql .= "?, ";
                }
            }
            $sql = rtrim($sql, ", ") . ");";
        }
        $this->statement = $this->connection->prepare($sql);
        //Binding Count Set
        $this->insert_stmt_param_binding_count = 1;
        $this->insert_stmt_param_count = 1;
        //Bind values to query
        $this->param_chunk_count = 0;
        if (is_array(reset($values))) {
            foreach ($values as $values_sub) {
                $this->insertQueryBindValues($values_sub, $function_override);
            }
        } else {
            $this->insertQueryBindValues($values, $function_override);
        }
        //Trim trailing comma
        $this->param_list = rtrim($this->param_list, ", ");
        //Execute Query
        if($this->execute()) {
            //Get inserted row id
            $new_id = $this->connection->lastInsertId();
            if($new_id == false) {
                return true;
            } else {
                return $new_id;
            }
        } else {
            $this->error_code = $this->connection->errorCode();
            $this->error_info = $this->connection->errorInfo();
            return false;
        }
    }

    public function insertQueryBindValues($values, $function_override) {
        $this->param_chunk_count++;
        foreach($values as $key => $param) {
            if(isset($function_override[$this->insert_stmt_param_count])) {
                $this->insert_stmt_param_count++;
            } else {
                $param_type = gettype($param);
                switch($param_type) {
                    case 'string':
                    case 'double':
                        $type = PDO::PARAM_STR;
                        break;
                    case 'integer':
                        $type = PDO::PARAM_INT;
                        break;
                    default:
                        $type = FALSE;
                }
                /*if(gettype($param) == 'string') {
                    $param = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $param);
                }*/
                $this->statement->bindValue($this->insert_stmt_param_binding_count, $param, $type);
                $this->param_list .= $key . " - " . $param . " :: " . $this->insert_stmt_param_binding_count . ", ";
                $this->param_array[$this->param_chunk_count][$key] = $param . " :: " . $this->insert_stmt_param_binding_count;
                $this->insert_stmt_param_binding_count++;
                $this->insert_stmt_param_count++;
            }
        }
    }

    public function updateQuery($table, $values, $criteria, $ip_and_date_time = 1)
    {
        $this->param_list = '';
        if ($ip_and_date_time == 1) {
            $values['ip_address'] = USER_IP;
            $values['table_updated_date_time'] = date('Y-m-d H:i:s');
        }
        $this->sql = "UPDATE {$table} SET ";
        foreach ($values as $key => $value) {
            if (starts_with($value, '||||') && ends_with($value, '||||')) {
                $this->sql .= $key . " " . str_replace('|||', '', $value) . ", ";
            } elseif (starts_with($value, '|||') && ends_with($value, '|||')) {
                $this->sql .= $key . " = " . str_replace('|||', '', $value) . ", ";
            } else {
                $this->sql .= $key . " = ?, ";
                $value_array[] = $value;
            }
        }
        $this->sql = rtrim($this->sql, ", ") . ' WHERE ';
        foreach ($criteria as $key => $value) {
            if (starts_with($value, '||||') && ends_with($value, '||||')) {
                $this->sql .= rtrim($key, " = ") . " " . str_replace('||||', '', $value) . " AND ";
            } elseif (starts_with($value, '|||') && ends_with($value, '|||')) {
                $this->sql .= rtrim($key, " = ") . " = " . str_replace('|||', '', $value) . " AND ";
            } else {
                $this->sql .= rtrim($key, " = ") . " = ? AND ";
                $value_array[] = $value;
                $id = $value;
            }
        }
        $this->sql = rtrim($this->sql, " AND ") . ";";
        $this->statement = $this->connection->prepare($this->sql);

        $count = 1;
        $this->param_list = '';
        foreach($value_array as $value) {
            switch(gettype($value)) {
                case 'string':
                    $type = PDO::PARAM_STR;
                    break;
                case 'integer':
                    $type = PDO::PARAM_INT;
                    break;
                default:
                    $type = FALSE;
            }
            $this->statement->bindValue($count, $value, $type);
            $this->param_list .= $count . " - " . $value . ", ";
            $count++;
        }
        $this->param_list = rtrim($this->param_list, ", ");

        if(!$this->execute()) {
            $this->statement->debugDumpParams();
        }
        return $id;
    }

    public function deleteQuery($table, $criteria = '') {
        if(is_array($criteria)) {
            $this->sql = "DELETE FROM {$table} WHERE ";
            foreach ($criteria as $key => $value) {
                $this->sql .= $key . " = ? AND ";
                $value_array[] = $value;
            }
            $this->sql = rtrim($this->sql, " AND ") . ";";
            $this->statement = $this->connection->prepare($this->sql);

            $count = 1;
            $this->param_list = '';
            foreach($value_array as $y) {

                switch(gettype($y)) {
                    case 'string':
                        $type = PDO::PARAM_STR;
                        break;
                    case 'integer':
                        $type = PDO::PARAM_INT;
                        break;
                    default:
                        $type = FALSE;
                }

                $this->statement->bindValue($count, $y, $type);
                $this->param_list .= $count . " - " . $y . ", ";
                $count++;
            }
            $this->param_list = rtrim($this->param_list, ", ");
        } else {
            $this->sql = "DELETE FROM {$table}";
            if(!empty($criteria)) {
                $this->sql .= ' ' . $criteria;
            }
            $this->statement = $this->connection->prepare($this->sql);
        }

        $this->execute();
    }

    public function getErrorMessages() {
        return $this->error_messages;
    }

}