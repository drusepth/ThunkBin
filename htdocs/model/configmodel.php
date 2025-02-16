<?php
    class ConfigModel
    {
        private $mysqli;
        private $cache;

        function __construct($host, $user, $pass, $db)
        {
            // Use the global mysqli connection if it exists, otherwise start a new one
            global $thunkbin_shared_mysqli;
            if(!isset($thunkbin_shared_mysqli))
            {
                $thunkbin_shared_mysqli = new mysqli($host, $user, $pass, $db);
                if($thunkbin_shared_mysqli->connect_error)
                    throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            }

            $this->mysqli =& $thunkbin_shared_mysqli;
            $this->cache = false;
        }

        public function GetValue($name)
        {
            // Read all config variables if they arent cached yet
            if($this->cache === false)
            {
                $result = $this->mysqli->query('SELECT * FROM `config`');
                if($result === false)
                    throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
                
                while($row = $result->fetch_array())
                    $this->cache[$row['name']] = $row['value'];

                $result->close();
            }
            if(isset($this->cache[$name])) 
                return $this->cache[$name];
            else
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
        }

        public function SetValue($name, $value)
        {
            // Update the database
            $stmt = $this->mysqli->prepare('UPDATE `config` SET `value`=? WHERE `name`=?');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('ss', $value, $name);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->close();

            // Update cache if it exists
            if(isset($this->cache[$name]))
                $this->cache[$name] = $value;
        }
    }
?>
