<?php
    //TODO: ---------------------------
    //TODO: shitloads of error handling
    //TODO: there is literally 0 error checking in all the SQL code
    //TODO: ---------------------------
    class PasteModel
    {
        private $members;
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
            $this->cache = array();
        }

        public function CountPublicPastes()
        {
            if (!isset($this->cache['pastecount']))
            {
                $res = $this->mysqli->query('SELECT COUNT(*) FROM `paste` WHERE `state` = 0');
                if($res === false)
                    throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
                
                $row = $res->fetch_row();

                $this->cache['pastecount'] = (int)$row[0];
            }
            
            return $this->cache['pastecount'];
        }

        public function CountUserPastes($ip, $time)
        {
            $stmt = $this->mysqli->prepare('SELECT COUNT(*) FROM `paste` WHERE `ip` = ? AND `created` > ?');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);

            $stmt->bind_param('si', $ip, $time);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            
            return $count;
        }
        
        public function ExpirePastes()
        {
            $cleardel =   'DELETE `paste`,`clearfile`,`clearpaste` '
                        . 'FROM `paste` '
                        . 'INNER JOIN `clearpaste` ON `paste`.`id` = `clearpaste`.`pid` '
                        . 'INNER JOIN `clearfile` ON `paste`.`id` = `clearfile`.`pid` '
                        . 'WHERE `state` != 2 AND `expires` != 0 AND `expires` < ' . time();
            $cryptdel =   'DELETE `paste`,`cryptpaste` '
                        . 'FROM `paste` '
                        . 'INNER JOIN `cryptpaste` ON `paste`.`id` = `cryptpaste`.`pid` '
                        . 'WHERE `state` = 2 AND `expires` != 0 AND `expires` < ' . time(); 

            if(!$this->mysqli->query($cleardel) || !$this->mysqli->query($cryptdel))
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
        }

        // Grab all language descriptions from the database and cache them because
        // this function will get called multiple times per page load
        public function GetLanguages()
        {
            if (!isset($this->cache['languages']))
            {
                $res = $this->mysqli->query('SELECT `id`, `name` FROM `language`');
                if($res === false)
                    throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
                
                $languages = array();
                while($row = $res->fetch_row())
                {
                    $languages[] = array('id' =>    $row[0],
                                         'name' =>  $row[1]);
                }
                $this->cache['languages'] = $languages;
            }
            return $this->cache['languages'];
        }
        
        // Grab only the language descriptions from the databes, but use the GetLanguages
        // function for its cache.  Also caches all results because this will agani be called
        // multiple times per page load
        public function GetLanguageIds()
        {
            if (!isset($this->cache['language_ids']))
            {
                $ids = array();
                $languages = $this->GetLanguages();
                foreach($languages as $lang)
                    $ids[] = $lang['id'];
                $this->cache['language_ids'] = $ids;
            }
            return $this->cache['language_ids'];
        }

        public function ListPublicPastes($limits)
        {
            $stmt = $this->mysqli->prepare('SELECT `pid`,`link`,`title`,`author` FROM `paste` LEFT JOIN `clearpaste` ON `paste`.`id`=`clearpaste`.`pid` WHERE `state` = \'0\' ORDER BY `pid` DESC LIMIT ?, ?');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('ii', $limits[0], $limits[1]);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            
            $stmt->bind_result($pid, $link, $title, $author);

            $pastes = array();
            while($stmt->fetch())
                $pastes[] = array('link' => $link, 'title' => $title, 'author' => $author);

            $stmt->close();
            return $pastes;
        }

        public function ReadClearPaste($link, $state)
        {
            // todo: more error handling before $stmt->fetch() required? Investigate

            // Get paste header
            $stmt = $this->mysqli->prepare('SELECT `pid`,`title`,`author`,`created`,`expires` FROM `paste` LEFT JOIN `clearpaste` ON `paste`.`id`=`clearpaste`.`pid` WHERE `link` = ? AND `state` = ?');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('si', $link, $state);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_result($pid, $title, $author, $created, $expires);
            $stmt->fetch();
            $header = array('title'     => $title,
                            'author'    => $author,
                            'created'   => $created,
                            'expires'   => $expires);
            $stmt->close();
            
            // Get paste files
            $stmt = $this->mysqli->prepare('SELECT `filename`,`contents`,`lid`,`name` FROM `clearfile` LEFT JOIN `language` ON `clearfile`.`lid`=`language`.`id` WHERE `pid` = ?');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('i', $pid);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_result($filename, $contents, $lid, $language);
            
            $files = array();
            while($stmt->fetch())
            {
                $files[] = array('filename' => $filename,
                                 'contents' => $contents,
                                 'langid'   => $lid,
                                 'lang'     => $language);
            }

            return array($header, $files);
        }
        
        public function ReadCryptPaste($link)
        {
            // Get paste header
            $stmt = $this->mysqli->prepare('SELECT `contents`,`iv`,`expires`,`created` FROM `paste` LEFT JOIN `cryptpaste` ON `paste`.`id`=`cryptpaste`.`pid` WHERE `link` = ? AND `state` = 2');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('s', $link);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_result($contents, $iv, $expires, $created);
            $stmt->fetch();
            $data = array('contents'=> $contents,
                          'iv'      => $iv,
                          'expires' => $expires,
                          'created' => $created);
            $stmt->close();
            return $data;
        }


        public function NewCryptPaste($expires, $iv, $data, $ip)
        {
            $link = $this->RandomLink();

            $now = time();
            if($expires != 0)
                $expires += $now;

            $stmt = $this->mysqli->prepare('INSERT INTO `paste` (`link`, `state`, `created`, `expires`, `ip`) VALUES (?, 2, ?, ?, ?)');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('siis', $link, $now, $expires, $ip);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->close();
            $pid = $this->mysqli->insert_id;
            
            $stmt = $this->mysqli->prepare('INSERT INTO `cryptpaste` (`pid`, `iv`, `contents`) VALUES (?, ?, ?)');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('iss', $pid, $iv, $data);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->close();
            
            return $link;
        }

        // Save a new public/private paste
        public function NewClearPaste($header, $files)
        {
            // TODO: correctly handle link UNIQUE errors
            // TODO: Should we care about what happens when paste entry succeeds, but clearpaste entry and/or file entry fails?
            $link = $this->RandomLink();

            $now = time();
            if($header['expiration'] != 0)
                $header['expiration'] += $now;

            // Create paste entry
            $stmt = $this->mysqli->prepare('INSERT INTO `paste` (`link`, `state`, `created`, `expires`, `ip`) VALUES (?, ?, ?, ?, ?)');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('siiis', $link, $header['state'], $now, $header['expiration'], $header['ip']);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->close();
            $pid = $this->mysqli->insert_id;
            
            // Create clearpaste entry with meta data
            $stmt = $this->mysqli->prepare('INSERT INTO `clearpaste` (`pid`, `title`, `author`) VALUES (?, ?, ?)');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->bind_param('iss', $pid, $header['title'], $header['author']);
            if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            $stmt->close();

            // create the clearfiles
            $stmt = $this->mysqli->prepare('INSERT INTO `clearfile` (`pid`, `lid`, `filename`, `contents`) VALUES (?, ?, ?, ?)');
            if(!$stmt)
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            foreach($files as $file)
            {
                $stmt->bind_param('iiss', $pid, $file['lang'], $file['filename'], $file['contents']);
                if(!$stmt->execute())
                throw new FramelessException('Internal Database Error', ErrorCodes::E_DATABASE);
            }
            $stmt->close();

            return $link;
        }
    
        // generate new random link
        private function RandomLink()
        {
            $charset = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $link = '';
            for($i = 0; $i < 9; $i++)
                $link .= $charset[rand() % strlen($charset)];

            return $link;
        }
    };
?>
