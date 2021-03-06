<?php
/**
 * Basis-SQL-Klasse
 * @package php-json-sql
 */
abstract class jsonSqlBase {
    /**
     * The Database-Structure
     * @var json_object
     */
    protected $db_structure;
    /**
     * The aliases of Table/Field-names
     * @var json_object
     */
    protected $db_aliases;
    /**
     * The allowed Operators for getWhere()
     * @var array
     */
    protected $allowed_op;

    /**
     * The allowed Order-operators
     * @var array
     */
    protected $allowed_order;
    /**
     *  Debuging aktiv?
     * @var type boolean
     */
    protected $debug;
    /**
     * Firephp-Objekt
     */
    protected $firephp;
    /**
     * Preparing everything
     * @param string $db_structure
     * @param string $db_aliases
     */
    public function __construct($db_structure,$db_aliases) {
        $this->db_structure=json_decode($db_structure);
        $this->db_aliases=json_decode($db_aliases);
        $this->debug=false;
        $this->firephp=null;
    }
    /**
     *Enable/disable Debuging(Shows Sql-Query, needs firephp)
     * @param bool $input Enable/disable
     * @param string $path Path to firephp lib
     */
    public function set_debug($input,$path=null){
        $this->debug=$input;
        global $log;
        if($this->debug && is_file($path)){
            require_once $path;
            ob_start();
            $this->firephp =new log($input,$path);
        }elseif(isset($this->firephp) && $this->firephp!=null){
            $this->firephp=null;
            unset($this->firephp);
        }
    }
    /**
     * Creates a new Query from a json_object
     * @param json_object $in The input-object
     * @param null|array $params Eine Liste aller Parameter
     * Normal: array(param1,param2...)
     * Update: array("update"=>array(param1,param2...),"where"=>array(param1,param2...))
     * @throws sqlException
     * @return siehe das Return der Unterfunktionen
     */
    public function query($in,$params=null) {
        global $dbh;
        $ret=null;
        //Check $in
        if(!is_string($in->type)) {
            if(is_array($in)) {
                foreach($in as $val) {
                    $ret[]=$this->query($val);
                }
            }else  {
                throw new sqlException("Syntax Error",1324225145);
            }
        } else {
            if($this->debug){
                $this->firephp->group("Exec ".$in->type."-Query");
                $this->firephp->log($in,"Input-Object");
                $this->firephp->log($params,"Params");
            }
            switch($in->type) {
                case "insert":
                    $ret= $this->insert($in,$params);
                    break;
                case "select":
                    $ret= $this->select($in,$params);
                    break;
                case "count":
                    $ret= $this->count($in,$params);
                    break;
                case "count_distinct":
                    $ret= $this->count($in,$params,true);
                    break;
                case "update":
                    $ret= $this->update($in,$params);
                    break;
                case "delete":
                    $ret= $this->delete($in,$params);
                    break;
            }
            if($this->debug){
                $this->firephp->log($ret,"Return");
                $this->firephp->groupEnd();
            }
            return $ret;
        }
    }
    /**
     * Formatiert einen String anhand eines formates
     * @param mixed $string
     * @param char $f p:DB-Bezeichner (Tabellennamen, etc.)
     * string,s: String (Varchar, Text,....)
     * float,f: float
     * int,i: int
     * bool,boolean,b: bool
     * @param bool $param Ist das ein Parameter (Tabellenfeld, etc...) für MySQL oder etwas, was eingefügt werden soll?
     * @return mixed Formatierter Input
     */
    protected function format($string,$f,$param=false) {

        if(is_object($f)) {
            $f=$f->type;
        } elseif(is_array($f)) {
            if($f[0]=='foreign')
                $f='int';
            else
                $f=$f[0];
        } elseif(!is_string($f)) {
            throw new sqlException('Wrong type', 1325077369,$f);
        }

        switch($f) {
            case 'p':
                return preg_replace("%[^A-Za-z0-9_]%siU","",$string);
                break;
            case 'string':
            case 's':
            case 'date':
            case 'datetime':
            case 'text':
                if(is_array($string))
                    return $string;

                $string=str_replace(
                    array("\x00","\n","\r","\\","'","\"","\x1a"),
                    array("\\x00","\\n","\\r","\\\\","\\'","\\\"","\\x1a")
                    ,$string);
                if($param)
                    return '"'.$string.'"';
                return $string;
                break;
            case 'float':
            case 'f':
                return floatval($string);
                break;
            case 'int':
            case 'i':
            case 'id':
                return intval($string);
                break;
            case 'bool':
            case 'boolean':
            case 'b':
                if($string===true || $string=='true')
                    return 'true';
                return 'false';
                break;
            case 'json':
                return json_encode($string);
                break;
        }
    }

    /**
     * Gibt ein Key/Value-Pair
     * @param json_object $params Das JSON-Objekt für die Insert-Abfrage
     Normales insert:
     {
     "foo":"bar,
     "baz":"?"
     }
     Insert mit Bedingung (Case)
     {
     "foo":[
            {
            "case":{
                "field":"foo", -- optional, wenn nicht gegeben, wird die aktuelle Spalte genommen
                "op":"_", -- operation @see $allowed_op
                "value":"" -- klar
                },
            "then":{
                "field":"foo", -- optional, wenn nicht gegeben wird die aktuelle Spalte genommen
                "op":"", -- Dann-Operation (+$value,-$value,=$value)
                "value":"" --klar
                }
            }…
            ……
            {"else"{
                "field":"foo", -- optional, wenn nicht gegeben wird die aktuelle Spalte genommen
                "op":"", -- Dann-Operation (+$value,-$value,=$value)
                "value":"" --klar
                }
            }
            
        ]
        …
    }
     * @param array $insert_params Die einzufügenden Daten
     * @throws sqlException
     * @return array array(table=>array(field=>value))
     */
    protected function getInsert($params,$insert_params=null) {
        $insert=array();
        foreach($params as $key=>$val) {
            if($key=='type')
                continue;
            if(!isset($this->db_aliases->$key))
                throw new sqlException('No such alias', 1325435040,$key);
            $alias=$this->db_aliases->$key;
            if($val=='?') {
                if($insert_params!=null && count($insert_params)>0) {
                    $p_key=key($insert_params);
                    $val=$insert_params[$p_key];
                    unset($insert_params[$p_key]);
                }
            }
            if(isset($alias->foreign)) {
                $value=$this->getForeignId($val,$alias);
            } else {
                $value=$val;
            }
            if(($value==='--' || $value==='++') && ($this->db_structure->{$alias->table}->{$alias->field}='int' || $this->db_structure->{$alias->table}->{$alias->field}='id'))
                $insert[$alias->table][$alias->field]=$value;
            elseif(is_array($value)){
                foreach($value as $key2=>$val2) {
                    //Einfache Überprüfung
                    
                    //Für Else
                    if(isset($val2->else)){
                        if(!isset($val2->else->field))
                            $val2->else->field=$key;
                        if(!isset($this->db_aliases->{$val2->else->field}))
                            throw new sqlException('No such alias', 1349086604,$val2->else->field);
                        $else_alias=$this->db_aliases->{$val2->else->field};
                        $val2->else->field=$else_alias;
                        
                        //Operatoren Überprüfen
                        if(!in_array($val2->else->op,$this->allowed_case_op))
                            throw new sqlException('This Case-operation is not allowed',1349086603,$val2);
                        //Value formatieren
                        $val2->else->value=$this->format($val2->else->value,$this->db_structure->{$else_alias->table}->{$else_alias->field});
                    } else { //Für Case-Then
                        if(!isset($val2->case))
                            throw new sqlException('No case-Statement',1349086600,$val2);
                        if(!isset($val2->then))
                            throw new sqlException('No then-Statement',1349086601,$val2);
                        //Standard-Einträge hinzufügen
                        if(!isset($val2->case->field))
                            $val2->case->field=$key;
                        if(!isset($val2->then->field))
                            $val2->then->field=$key;
                        //Feld-Überprüfung
                        if(!isset($this->db_aliases->{$val2->case->field}))
                            throw new sqlException('No such alias', 1349086604,$val2->case->field);
                        if(!isset($this->db_aliases->{$val2->then->field}))
                            throw new sqlException('No such alias', 1349086604,$val2->then->field);
                        $case_alias=$this->db_aliases->{$val2->case->field};
                        $val2->case->field=$case_alias;
                        $then_alias=$this->db_aliases->{$val2->then->field};
                        $val2->then->field=$then_alias;
                        
                        //Operatoren Überprüfen
                        if(!in_array($val2->case->op,$this->allowed_op))
                            throw new sqlException('This operation is not allowed',1349086602,$val2);
                        if(!in_array($val2->then->op,$this->allowed_case_op))
                            throw new sqlException('This Case-operation is not allowed',1349086603,$val2);
                        //Value formatieren
                        if($val2->case->op=='BETWEEN'){
                            $val2->case->value[0]=intval($val2->case->value[0]);
                            $val2->case->value[1]=intval($val2->case->value[1]);
                        } else {
                            $val2->case->value=$this->format($val2->case->value,$this->db_structure->{$case_alias->table}->{$case_alias->field});
                        }
                        $val2->then->value=$this->format($val2->then->value,$this->db_structure->{$then_alias->table}->{$then_alias->field});
                    }
                    $value[$key2]=$val2;
                }
                $insert[$alias->table][$alias->field]=$value;
            }else
                $insert[$alias->table][$alias->field]=$this->format($value,$this->db_structure->{$alias->table}->{$alias->field});
        }
        return $insert;
    }
    /**
     * Erstellt aus einem JSON-Objekt eine SQL-Insert-Abfrage
     * @param json_object $params {"_alias_":"value"}
     * @param array @see query()
     * @throws sqlException
     * @return int Id des eingefügten Datensatzes
     */
    protected function insert($params,$insert_params) {
        $insert=$this->getInsert($params,$insert_params);
        foreach($insert as $key=>$value) {
            return intval($this->execInsert($key, $value));
        }
    }
    /**
     * Führt eine Insert-Abfrage aus
     * @param string $table Tabelle
     * @param array $fields Felder (Key-Value Pair)
     * @return @see insert()
     */
    protected abstract function execInsert($table,$fields);

    /**
     * Gets a foreign id for a input-value of a foreign table (select || input)
     * @param json_object $value The input-value
     * @param json_object $alias The alias-Object
     * @return int ID des Datensatzes
     */
    protected abstract function getForeignId($value,$alias);
    /**
     * Helper function for select()
     * @param json_object $pwhat Which field you want to select?
     * @throws sqlException
     * @return array The fields wich you want to select
     */
    protected function getWhat($pwhat) {
        $what=array();
        $join=array();
        $new_alias=array();
        $position=0;
        foreach($pwhat as $val_alias) {
            $val_obj=false;
            if(is_object($val_alias)) {
                $val=$val_alias->alias;
                $val_obj=true;
            } else {
                $val=$val_alias;
            }
            if(!isset($this->db_aliases->$val))
                throw new sqlException('no such db-alias', 1325074427,$val);

            $alias=$this->db_aliases->$val;
            if(!isset($what[$alias->table]))
                $what[$alias->table]=array();
            if(!in_array($alias->field,$what[$alias->table])) {
                if($val_obj) {
                    if(!isset($alias->foreign))
                        throw new sqlException('No foreign table for',1335287770,$val);
                    $what[$val_alias->from][]=array('field'=>$alias->foreign->field,'alias'=>$val,'position'=>$position);
                    ++$position;
                    if(!isset($join[$val_alias->alias])) {
                        $alias->alias=$val_alias->from;
                        if(isset($val_alias->via))
                            $alias->via=$val_alias->via;
                        $join[$alias->alias]=$alias;
                        $new_alias[$val_alias->alias]=$val_alias->from;
                    }
                    continue;
                }
                if(!isset($alias->foreign)) {
                    $what[$alias->table][]=array('field'=>$alias->field,'alias'=>$val,'position'=>$position);
                    ++$position;
                }else {
                    if(!isset($what[$alias->foreign->table]))
                        $what[$alias->foreign->table]=array();
                    if(!in_array($alias->foreign->field,$what[$alias->foreign->table])) {
                        $what[$alias->foreign->table][]=array('field'=>$alias->foreign->field,'alias'=>$val,'position'=>$position);
                        ++$position;
                    }
                    $join[]=$alias;
                }

            }
        }
        return array($what,$join,$new_alias);
    }
    /**
     * Helper function for select()
     * @param json_object $pwhere where-params
     * @param array $where_params Die where-einfüge-parameter
     * @param array $new_alias Liste neuer aliase
     * @param array $join Die aktuellen Joining-Infos
     * @throws sqlException
     * @return array the query-params
     */
    protected function getWhere($pwhere,$where_params=null,$new_alias=null,$join=null) {
        $where='';
        $from=array();
        foreach($pwhere as $val) {
            $where.=' ';
            if(isset($val->field)) {
                $field=$val->field;
                $op=@$val->op;
                $value=$val->value;
                if(!isset($this->db_aliases->$field))
                    throw new sqlException('No such db-alias', 1325075468,$field);

                $alias=$this->db_aliases->$field;
                $alias_table=$alias->table;
                $alias_field=$alias->field;

                if(!in_array($alias_table,$from))
                    $from[]=$alias_table;

                //Operator
                $op=strtoupper($op);
                if(isset($alias->foreign)) {
                    if($new_alias==null || !isset($new_alias[$field])){
                        $where.=$alias->foreign->table.'.'.$alias->foreign->field;
                        $alias_table=$alias->foreign->table;
                        $alias_field=$alias->foreign->field;
                    }else {
                        $alias_table=$alias->foreign->table;
                        $alias_field=$alias->foreign->field;
                        $where.=$new_alias[$field].'.'.$alias->foreign->field;
                    }
                }else {
                    $where.=$alias_table.'.'.$alias_field;
                }
                $where.=' '.$op.' ';

                //Value format
                if($value==='?') {
                    if(!is_array($where_params))
                        throw new sqlException('No where params',1335281004);
                    $p_key=key($where_params);
                    $value=$where_params[$p_key];
                    unset($where_params[$p_key]);
                }
                $value=$this->format($value,$this->db_structure->$alias_table->$alias_field,true);
                switch($op) {
                    case '=':
                    case '<=>':
                    case '<>':
                    case '!=':
                    case '<=':
                    case '<':
                    case '>=':
                    case '>':
                        $where.=$value;
                        break;
                    case 'IS':
                    case 'IS NOT':
                        $value=str_replace('"','',strtoupper($value));
                        $allowed_values=array('TRUE','FALSE','NULL');

                        if(!in_array($value, $allowed_values))
                            throw new sqlException('wrong is-value', 1325077665,$value);
                        $where.=$value;
                        break;
                    case 'BETWEEN':
                        $where.=$this->format($value,$this->db_structure->$alias_table->$alias_field,true)
                        .' AND '.$this->format($value2,$this->db_structure->$alias_table->$alias_field,true);
                        break;
                    case 'LIKE':
                        $where.=$value;
                        break;
                    case 'NOT LIKE':
                        $where.=$value;
                        break;
                    case 'IN':
                    case 'NOT IN':
                        $where.='(';
                        $in=0;
                        foreach($val->value as $inval) {
                            if($in!=0)
                                $where.=', ';
                            $where.=$this->format($inval,$this->db_structure->$alias_table->$alias_field,true);
                            ++$in;
                        }
                        $where.=')';
                        break;
                }
            } else {
                $allowed_ops=array('AND','OR','NOT','(',')');
                if(!in_array($val->op,$allowed_ops)) {
                    throw new sqlException('wrong op', 1325078411,$val->op);
                }
                $where.=$val->op;
            }
        }
        return array($where,$join);
    }

    /**
     * returns an order-by-String
     * @param json_object $porder The Order-Param
     * @throws sqlException
     */
    protected abstract function getOrder($porder);

    /**
     * Erstellt aus einem JSON-Objekt eine Select-Abfrage
     * @param json_object $params
     * {"what":["_alias_"...],
     * 	"where":[
     * 	{
     * 		"field":"_alias_",
     * 		"op":"_operator_", //'=','<>','<','>'...
     * 		"value":"_value_"
     * 	},
     * 	{ "op":"_op"},...
     * 	],
     *  "order":{
     *  "_alias_":"op",...
     *  },
     *  "limit":1
     * }
     * @param array $select_params Die eifüge-Parameter
     * @param $return bool soll execSelect() ausgeführt werden, oder nur die Parameter, die an execSelect übergeben werden würden zurückgegeben?
     * @throws sqlException
     * @return array PDO-Return-array:
     * array(array(alias1=>val1,alias1=>val1,alias1=>val1),array(alias1=>val1,alias1=>val1,alias1=>val1),...)
     */
    protected function select($params,$select_params,$return=false) {
        //SELECT $what FROM $from [WHERE $where] [ORDER BY $order] [LIMIT $limit]
        $what=array();
        $from=array();
        $where=null;
        $order=null;
        $limit=isset($params->limit) ? $params->limit : null;

        $pwhat=$params->what;
        if(!is_array($pwhat))
            throw new sqlException('params->what should be an array', 1325074338);

        //$what
        $temp=$this->getWhat($pwhat);
        $what=$temp[0];
        $join=$temp[1];
        $new_alias=$temp[2];
        foreach($what as $key=>$val) {
            if(!in_array($key,$from))
                $from[]=$key;
        }

        //[$where]
        if(isset($params->where)) {
            $temp=$this->getWhere($params->where,$select_params,$new_alias,$join);
            $where=$temp[0];
            $join=$temp[1];
        }

        //Order
        if(isset($params->order) && is_object($params->order)) {
            foreach($params->order as $key=>$val) {
                $val=strtoupper($val);
                if(!in_array($val, $this->allowed_order))
                    throw new sqlException('No such order', 1325083418,$val);
                if(!isset($this->db_aliases->$key))
                    throw new sqlException('No such alias', 1325083415,$key);

                $alias=$this->db_aliases->$key;
                $alias_table=$alias->table;
                $alias_field=$alias->field;

                if(isset($alias->foreign)) {
                    if($new_alias==null || !isset($new_alias[$key])){
                        $order[]=array('table'=>$alias->foreign->table,'field'=>$alias->foreign->field,'op'=>$val);
                    }else {
                        $order[]=array('table'=>$new_alias[$key],'field'=>$alias->foreign->field,'op'=>$val);
                    }
                }else {
                    $order[]=array('table'=>$alias->table,'field'=>$alias->field,'op'=>$val);
                }
            }
        }
        //Group
        $group=null;
        if(isset($params->group)) {
            if(!is_array($params->group)){
                $params->group=array($params->group);
            }
            $group=array();
            foreach($params->group as $g) {
                if(!isset($this->db_aliases->$g))
                    throw new sqlException('No such alias', 1325083415,$params->group);

                $alias=$this->db_aliases->$g;

                $alias_table=$alias->table;
                $alias_field=$alias->field;

                if(isset($alias->foreign)) {
                    if($new_alias==null || !isset($new_alias[$g])){
                        $group[]=array('table'=>$alias->foreign->table,'field'=>$alias->foreign->field);
                    }else {
                        $group[]=array('table'=>$new_alias[$g],'field'=>$alias->foreign->field);
                    }
                }else {
                    $group[]=array('table'=>$alias->table,'field'=>$alias->field);
                }
            }
        }
        if($return)
            return array(
                'what'=>$what,
                'from'=>$from,
                'where'=>$where,
                'order'=>$order,
                'group'=>$group,
                'limit'=>$limit,
                'join'=>$join
            );
        else
            return $this->execSelect($what, $from,$where,$order,$group,$limit,$join);
    }
    /**
     * Wie where() aber, der erste Rückgabewert ist ein count()
     * @param json_object $params
     * {"what":["_alias_"...],
     * 	"where":[
     * 	{
     * 		"field":"_alias_",
     * 		"op":"_operator_", //'=','<>','<','>'...
     * 		"value":"_value_"
     * 	},
     * 	{ "op":"_op"},...
     * 	],
     *  "order":{
     *  "_alias_":"op",...
     *  },
     *  "limit":1
     * }
     * @param array $select_params Die eifüge-Parameter
     * @param $distinct bool sollen doppelte Einträge ignoriert werden?
     * @throws sqlException
     * @return array PDO-Return-array:
     * array(array(alias1=>count,alias1=>val1,alias1=>val1),array(alias1=>count,alias1=>val1,alias1=>val1),...)
     */
    private  function count($params,$select_params,$distinct=false) {
        $params=$this->select($params,$select_params,true);
        if(!$distinct)
            $params['what'][key($params['what'])][0]['type']='count';
        else
            $params['what'][key($params['what'])][0]['type']='count_distinct';
        return $this->execSelect($params['what'], $params['from'], $params['where'], $params['order'], $params['group'], $params['limit'], $params['join']);
    }
    /**
     * Create and execute the select-query
     * @param array $what Was soll geholt werden
     * @param array $from aus welchen Tabellen
     * @param array $where Die Where-parameter
     * @param aray $order Order
     * @param array $group Gruppen-Infos
     * @param array $limit Limit
     * @param array $join Join-Infos
     * @return @see select()
     */
    protected abstract function execSelect($what,$from,$where=null,$order=null,$group=null,$limit=null,$join=null);
    /**
     * Creates an update-query
     * @param json_object $params The input-param
     * @param array $update_params Die einfüge/update-Parameter @see query()
     * @return bool Hat es geklappt?
     */
    protected function update($params,$update_params) {

        if(!isset($update_params['update']) && !isset($update_params['where']) && is_array($update_params))
            $update_params=$update_params[key($update_params)];
        if(!isset($params->update))
            throw new sqlException('Nothing to update', 1325688162);
        $update=$this->getInsert($params->update,@$update_params['update']);

        if(count($update)!=1)
            throw new sqlException('You can update only one table a time!', 1325688732);
        if(isset($params->where)) {
            $temp=$this->getWhere($params->where,@$update_params['where']);
            $where=$temp[0];
        } else $where=null;
        return $this->execUpdate($update, $where);
    }
    /**
     * Führt eine Update-Abfrage aus
     * @param array $update Update-Teil
     * @param array $where Where-Teil
     * @return @see update()
     */
    protected abstract function execUpdate($update,$where=null);
    /**
     * Creates an delete-query
     * @param json_object $params The input-param
     * @param array $delete_params Die Lösch-Parameter
     * @return bool Hat es geklappt?
     */
    protected function delete($params,$delete_params){
        if(!isset($params->table) || !isset($this->db_structure->{$params->table}))
            throw new sqlException('No such table', 1325689698,isset($params->table)?$params->table:'no table given');

        if(isset($params->where)) {
            $temp=$this->getWhere($params->where,$delete_params);
            $where=$temp[0];
        } else $where=null;
        return $this->execDelete($params->table, $where);
    }
    /**
     * Führt eine Delete-Abfrage aus
     * @param string $table Tabellenname
     * @param object $where optionales Where-Objekt
     * @return @see delete()
     */
    protected abstract function execDelete($table,$where=null);

    /**
     * Holt die Label-Eigenschaft eines Aliases
     * @param String $alias Alias-Name
     * @return null|string
     */
    public function getLabel($alias) {
        if(isset($this->db_aliases->$alias->label)) {
            return $this->db_aliases->$alias->label;
        }
        return null;
    }
    /**
     * Existiert ein Alias unter dem Namen?
     * @param string $alias
     * @return bool Existiert er?
     */
    public function existAlias($alias) {
        return isset($this->db_aliases->$alias);
    }
    /**
     * Holt den Typ eines Aliases
     * @param string $alias_name Alias-Name
     * @param bool $foreign Soll der typ des Foreign-fields zurückgegeben werden?
     * @return string
     */
    public function getType($alias_name,$foreign=false) {
        if(isset($this->db_aliases->$alias_name)) {
            $alias=$this->db_aliases->$alias_name;
            if(isset($alias->foreign)){
                if($foreign===true){
                    return $alias->foreign;
                }
                $alias=$alias->foreign;
            }
            return $this->db_structure->{$alias->table}->{$alias->field};
        }
        return null;
    }
    /**
     * Holt den Alias des Foreign-Felds
     * @param string $alias Alias-Name
     * @return json_object siehe in der alias.json
     */
    public function getAliasForeign($alias){
        if(isset($this->db_aliases->$alias_name)) {
            return $this->db_aliases->$alias_name->foreign;
        }
        return null;
    }
}
