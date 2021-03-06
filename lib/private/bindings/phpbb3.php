<?php
    @include_once dirname(__FILE__)."/../../config/config.phpbb3.php";
    
    array_push(PluginRegistry::$Classes, "PHPBB3Binding");
    
    class PHPBB3Binding
    {
        public static $HashMethod_md5r = "phpbb3_md5r";
        public static $HashMethod_md5  = "phpbb3_md5";
        
        private static $Itoa64 = "./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
        
        public $BindingName = "phpbb3";
        private $mConnector = null;
    
        // -------------------------------------------------------------------------
        
        public function isActive()
        {
            return defined("PHPBB3_BINDING") && PHPBB3_BINDING;
        }
        
        // -------------------------------------------------------------------------
        
        private function getGroup( $aUserId )
        {
            $DefaultGroup = "none";
            $MemberGroups   = explode(",", PHPBB3_MEMBER_GROUPS );
            $RaidleadGroups = explode(",", PHPBB3_RAIDLEAD_GROUPS );
            
            $GroupSt = $this->mConnector->prepare("SELECT user_type, `".PHPBB3_TABLE_PREFIX."user_group`.group_id, ban_start, ban_end ".
                                                 "FROM `".PHPBB3_TABLE_PREFIX."users` ".
                                                 "LEFT JOIN `".PHPBB3_TABLE_PREFIX."user_group` USING(user_id) ".
                                                 "LEFT JOIN `".PHPBB3_TABLE_PREFIX."banlist` ON user_id = ban_userid ".
                                                 "WHERE user_id = :UserId");
                                                 
            $GroupSt->bindValue(":UserId", $aUserId, PDO::PARAM_INT);
            $GroupSt->execute();
            
            while ($Group = $GroupSt->fetch(PDO::FETCH_ASSOC))
            {
                if ( ($Group["user_type"] == 1) || 
                     ($Group["user_type"] == 2) )
                {
                    // 1 equals "inactive"
                    // 2 equals "ignore"
                    return "none"; // ### return, disabled ###
                }
                
                if ($Group["ban_start"] > 0)
                {
                    $CurrentTime = time();
                    if ( ($Group["ban_start"] < $CurrentTime) &&
                         (($Group["ban_end"] == 0) || ($Group["ban_end"] > $CurrentTime)) )
                    {
                        return "none"; // ### return, banned ###
                    }
                }
            
                if ( in_array($Group["group_id"], $MemberGroups) )
                {
                    $DefaultGroup = "member";
                }
                   
                if ( in_array($Group["group_id"], $RaidleadGroups) )
                {
                    return "raidlead"; // ### return, highest possible group ###
                }
            }

            return $DefaultGroup;
        }
        
        // -------------------------------------------------------------------------
        
        private function generateInfo( $aUserData )
        {
            $Info = new UserInfo();
            $Info->UserId      = $aUserData["user_id"];
            $Info->UserName    = $aUserData["username_clean"];
            $Info->Password    = $aUserData["user_password"];
            $Info->Salt        = self::extractSaltPart($aUserData["user_password"]);
            $Info->Group       = $this->getGroup($aUserData["user_id"]);
            $Info->BindingName = $this->BindingName;
            $Info->PassBinding = $this->BindingName;
        
            return $Info;
        }
        
        // -------------------------------------------------------------------------
        
        public function getUserInfoByName( $aUserName )
        {
            if ($this->mConnector == null)
                $this->mConnector = new Connector(SQL_HOST, PHPBB3_DATABASE, PHPBB3_USER, PHPBB3_PASS);
            
            $UserSt = $this->mConnector->prepare("SELECT user_id, username_clean, user_password ".
                                                 "FROM `".PHPBB3_TABLE_PREFIX."users` ".
                                                 "WHERE LOWER(username_clean) = :Login LIMIT 1");
                                          
            $UserSt->BindValue( ":Login", strtolower($aUserName), PDO::PARAM_STR );
            
            if ( $UserSt->execute() && ($UserSt->rowCount() > 0) )
            {
                $UserData = $UserSt->fetch( PDO::FETCH_ASSOC );
                $UserSt->closeCursor();
                
                return $this->generateInfo($UserData);
            }
        
            $UserSt->closeCursor();
            return null;
        }
        
        // -------------------------------------------------------------------------
        
        public function getUserInfoById( $aUserId )
        {
            if ($this->mConnector == null)
                $this->mConnector = new Connector(SQL_HOST, PHPBB3_DATABASE, PHPBB3_USER, PHPBB3_PASS);
            
            $UserSt = $this->mConnector->prepare("SELECT user_id, username_clean, user_password ".
                                                 "FROM `".PHPBB3_TABLE_PREFIX."users` ".
                                                 "WHERE user_id = :UserId LIMIT 1");
                                          
            $UserSt->BindValue( ":UserId", $aUserId, PDO::PARAM_INT );
        
            if ( $UserSt->execute() && ($UserSt->rowCount() > 0) )
            {
                $UserData = $UserSt->fetch( PDO::FETCH_ASSOC );
                $UserSt->closeCursor();
                
                return $this->generateInfo($UserData);
            }
        
            $UserSt->closeCursor();
            return null;
        }
        
        // -------------------------------------------------------------------------
        
        private static function extractSaltPart( $aPassword )
        {
            if (strlen($aPassword) == 34)
            {
                $Count = strpos(self::$Itoa64, $aPassword[3]);
                $Salt = substr($aPassword, 4, 8);
                
                return $Count.":".$Salt;
            }
            
            return "";
        }
        
        // -------------------------------------------------------------------------
        
        public function getMethodFromPass( $aPassword )
        {
            if (strlen($aPassword) == 34)
                return self::$HashMethod_md5r;
                
            return self::$HashMethod_md5;
        }
        
        // -------------------------------------------------------------------------
        
        private static function encode64( $aInput, $aCount )
        {
            $Output = '';
            $i = 0;
            
            do {
                $Value = ord($aInput[$i++]);
                $Output .= self::$Itoa64[$Value & 0x3f];
                
                if ($i < $aCount)
                {
                   $Value |= ord($aInput[$i]) << 8;
                }
                
                $Output .= self::$Itoa64[($Value >> 6) & 0x3f];
                
                if ($i++ >= $aCount)
                {
                   break;
                }
                
                if ($i < $aCount)
                {
                   $Value |= ord($aInput[$i]) << 16;
                }
                
                $Output .= self::$Itoa64[($Value >> 12) & 0x3f];
                
                if ($i++ >= $aCount)
                {
                   break;
                }
                
                $Output .= self::$Itoa64[($Value >> 18) & 0x3f];
            } while ($i < $aCount);
            
            return $Output;
        }
        
        // -------------------------------------------------------------------------
        
        public static function hash( $aPassword, $aSalt, $aMethod )
        {
            if ($aMethod == self::$HashMethod_md5 )
            {
                return md5($aPassword);
            }
            
            $Parts   = explode(":",$aSalt);
            $CountB2 = intval($Parts[0],10);
            $Count   = 1 << $CountB2;
            $Salt    = $Parts[1];
            
            $Hash = md5($Salt.$aPassword, true);
            
            do {
                $Hash = md5($Hash.$aPassword, true);
            } while (--$Count);
            
            return '$H$'.self::$Itoa64[$CountB2].$Salt.self::encode64($Hash,16);
        }
    }
?>