<?php
require_once APP_PATH."/classes/base/main/Base.php";
require_once APP_PATH."/classes/base/main/Logger.php";
require_once APP_PATH."/classes/base/exceptions/DAOException.php";

class Model{
	/**
	 * Efetua transação com bd
	 */
	private $base;
    protected $logger;

	private static $properties = "C:\\inetpub\\properties\\bd_estagiario.properties";
	private static $propertiesLinux = "/var/www/properties/bd_estagiario.properties";

	protected $tableName;
	protected $columns;
	protected $oneMany;
	protected $manyOne;
	protected $manyOneId;

	/* Necessário para POSTGRE */
	protected $pkSequenceName = NULL;

	public function __construct() {
        
        
		if(file_exists(self::$properties)){
			$this->base = new Base(self::$properties);
		}else{
			if(!file_exists(self::$propertiesLinux)){
                throw new DAOException(": Erro na conexão. Arquivo inexistente ".self::$properties. " ou ".self::$propertiesLinux);
			}
            $this->base = new Base(self::$propertiesLinux);
		}
        $logger = null;
	}

	public function __get($campo) {
		return $this->$campo;
	}

	public function __set($campo, $valor) {
		$this -> $campo = $valor;
	}

	public final function obter($id){
		$filtroId = array($this->getPKColName() => "= $id");
		$result = $this->select($filtroId);
		reset($result);
		return current($result);
	}

	/*
	* A primeira coluna da classe filha deve ser referente ao ID / Primary Key da tabela
	*/
	private function getPKColName($bd = FALSE){
		if(!is_array($this->columns) || count($this->columns) < 1){
			throw new DAOException("Verifique o mapeamento das propriedades da classe.");
		}
		reset($this->columns);
		return ($bd === FALSE) ? current($this->columns) : key($this->columns);
	}

	/**
	 * Método utilizado para trocar os campos do BD pelas propriedades da classe
	 * @param $columns:array de campos key=campo_do_bd value=propriedade_da_classe,
	 * objetobd:um objeto já populado vindo por consulta do bd,
	 * objetoclass[opcional]: se for omitido retorna um novo objeto, se passado retorna um objeto da sua classe
	 *
	 */
	protected final function bind($objetobd, $objetoclass = NULL){
		if(! ($classe = get_class($objetoclass) ) )
			throw new DAOException("Falha no mapeamento do banco de dados. O parâmetro fornecido não é um objeto.");

		if(! (is_array($this->columns) ) )
			throw new DAOException("Falha no mapeamento do banco de dados. As colunas devem ser definidas em um array.");

		if($objetoclass === NULL){
			$objetoclass = new $classe();
		}

		foreach($this->columns as $campobd => $campoclass){
			if(property_exists($objetobd, $campobd)){
				$objetoclass->$campoclass = $objetobd->$campobd;
			}
		}
		return $objetoclass;

	}

	public final function remove($id){
		if($id === NULL || $id <= 0){
			throw new DAOException("O objeto não tem o id da base e não poderá ser removido.");
		}
		$values = array();
		$campoId = $this->getPKColName(TRUE);
		$sql = "DELETE FROM ".$this->tableName." WHERE ".$campoId." = ?";
		array_push($values, $id);

		$result = $this->base->deletar($sql, $values);
		if($result == 0){
			$errMsg = "$sql ";
			foreach($values as $v){
				$errMsg.=" | $v";
			}
			throw new DAOException("$errMsg O comando foi executado mas nenhum registro da base foi modificado para o ID $id.");
		}
		return $result;
	}
    
    protected function beforeSelfUpdate($input, $id){
        $objOrig = $this->obter($id);
		if($objOrig === FALSE){
			throw new DAOException("$id nao encontrado", 404);
		}
		if($input != NULL){
			foreach($this->columns as $nomeCampo){
				$this->$nomeCampo = (isset($input[$nomeCampo]) && !is_array($input[$nomeCampo])) ? $input[$nomeCampo] : $objOrig->$nomeCampo;
			}
		}
    }

	public final function selfUpdate($input, $id){
        $this->beforeSelfUpdate($input, $id);
		$sqlColunas = "";
		$values = array();
		$first = true;
		$campoId = $this->getPKColName(TRUE);

		foreach($this->columns as $campobd => $campoClass){
			if($campobd != $campoId){
				if(is_object($this->$campoClass)){
					if(isset($this->$campoClass->id)){
						$this->$campoClass = $this->$campoClass->id;
						error_log("Foi obtido o ID do objeto fornecido de $campobd");
					}else{
						throw new DAOException("$campobd não tem um valor permitido. Classe:".get_class($this->$campoClass));
					}
				}
				if(!$first){
					$sqlColunas.=",";
				}
				$sqlColunas.=$campobd."=?";
				array_push($values, $this->$campoClass);
				$first = false;
			}
		}
		$sql = "UPDATE ".$this->tableName." SET ".$sqlColunas." WHERE ".$campoId." = ?";

		array_push($values, $id);

		$result = $this->base->atualizar($sql, $values);
		if($result == 0){
			$errMsg = "$sql ";
			foreach($values as $v){
				$errMsg.=" | $v";
			}
			throw new DAOException("$errMsg O comando foi executado mas nenhum registro da base foi modificado para o ID $id.");
		}
		return $result;
	}

	public final function update($columns, $conditions = NULL){

		$sqlColunas = "";
		if($columns == NULL){
			throw new DAOException("Valores para update não especificados.");
		}
		if(!is_array($columns)){
			throw new DAOException("Valores para update devem ser um array.");
		}

		$values = array();
		$first = true;
		foreach($columns as $column => $val){
			if(!$first){
				$sqlColunas.=",";
			}
			$bdCol = array_search($column, $this->columns, TRUE);
			if($bdCol !== FALSE){
				$sqlColunas.=$bdCol."=?";
				array_push($values, $val);
				$first = false;
			}
		}

		if(strlen($sqlColunas) < 1){
			throw new DAOException("Pelo menos uma coluna para atualização deve ser especificada.");
		}

		$sql = "UPDATE ".$this->tableName." SET ".$sqlColunas;
		$first = TRUE;
		if($conditions != NULL){
			if(!is_array($conditions)){
				throw new DAOException("Condicoes para update devem ser um array.");
			}
			$sqlConditions = " ";
			$validOper = array("=","<>","NOT LIKE","LIKE","IS");
			foreach($conditions as $property => $val){
				$column = array_search($property, $this->columns);
				if($column !== FALSE){
					foreach($validOper as $oper){
						$operPos = stripos($val,$oper);
						if($operPos !== FALSE){
							$param = trim(substr($val, $operPos+strlen($oper), strlen($val)));
							if($first === FALSE){
								$sqlConditions.=" AND ";
							}
							$sqlConditions.=$column." ".$oper." ?";
							$param = str_replace("'", "", $param);
							$param = str_replace("\"", "", $param);
							array_push($values, $param);
							break;
						}
					}
					$first = FALSE;
				}
			}
		}

		if(strlen($sqlConditions) < 1){
			throw new DAOException("Filtro nao reconhecido. Operadores aceitos =,<>,NOT LIKE,LIKE,IS" );
		}
		$sql .= " WHERE ".$sqlConditions;
		$result = $this->base->atualizar($sql, $values);
		if($result == 0){
			$errMsg = "$sql ";
			foreach($values as $v){
				$errMsg.=" | $v";
			}
			throw new DAOException("$errMsg O comando foi executado mas nenhum registro da base foi modificado.");
		}
		return $result;
	}
    
    protected function beforeInsert($input){
        if($input != NULL){
			foreach($this->columns as $nomeCampo){
				if(isset($input[$nomeCampo])){
					$this->$nomeCampo = $input[$nomeCampo];
				}
			}
		}
    }

	public final function insert($input){
        $this->beforeInsert($input);
		$sqlColunas = "";
		$sqlVals = "";
		$values = array();
		$first = true;
		$pk = $this->getPKColName();
		foreach($this->columns as $campobd => $campoClass){
			if($campoClass == $pk && $this->$campoClass == NULL){
				continue;
			}
			if(!$first){
				$sqlColunas.=",";
				$sqlVals.=",";
			}
			$sqlColunas.=$campobd;
			$sqlVals.="?";
			array_push($values, $this->$campoClass);
			$first = false;
		}
		$sql = "INSERT INTO ".$this->tableName."( ".$sqlColunas." ) VALUES ( ".$sqlVals." )";

		return $this->base->criar($sql, $values, $this->pkSequenceName);

	}

	public function select($conditions = NULL, $orderColumns = NULL, $orderType = NULL, $selectColumns = NULL){
		$sqlColunas = "";
		$values = NULL;
		if($selectColumns == NULL){
			$first = true;
			foreach($this->columns as $campobd => $campoclass){
				if(!$first){
					$sqlColunas.=",";
				}
				$sqlColunas.=$campobd;
				$first = false;
			}
		}else{
			$sqlColunas = $selectColumns;
		}
		$sql = "SELECT ".$sqlColunas." FROM ".$this->tableName;
		if($conditions != NULL){
			$first = TRUE;
			if(!is_array($conditions)){
				throw new DAOException("O parametro com condicoes deve ser um array.");
			}
			$sqlConditions = " ";
			$values = array();
			$validOper = array("LIKE",">=","<=","<>",">","<","=");
			foreach($conditions as $property => $val){
				$column = array_search($property, $this->columns);
				foreach($validOper as $oper){
					$operPos = stripos($val,$oper);
					if($operPos !== FALSE){
						$param = trim(substr($val, $operPos+strlen($oper), strlen($val)));
						if($first === FALSE){
							$sqlConditions.=" AND ";
						}
						$sqlConditions.=$column." ".$oper." ?";
						$param = str_replace("'", "", $param);
						$param = str_replace("\"", "", $param);
						array_push($values, $param);
						break;
					}
				}
				$first = FALSE;
			}
			if(strlen($sqlConditions) > 1){
				$sql .= " WHERE ".$sqlConditions;
			}else{
				throw new DAOException("Filtro nao reconhecido. (LIKE, >=, <=, <>, >, <, =)" );
			}

		}
		if($orderColumns != NULL){
			$first = TRUE;
			$sqlOrder = "";
			if(!is_array($orderColumns)){
				throw new DAOException("O parametro com colunas para ordem deve ser um array.");
			}
			foreach ($orderColumns as $col) {
				if(!$first){
					$sqlOrder.=",";
				}
				$sqlOrder .= array_search($col, $this->columns);
				$first = FALSE;
			}
			$sql.= " ORDER BY ".$sqlOrder;
			if($orderType != NULL){
				$sql.= " ".$orderType;
			}
		}
		$rows = array();
		$result = $this->base->consultar($sql, $values);
		foreach($result as $row){
			foreach($row as $obj){
				$obj = $this->bind($obj);
				$obj = $this->mapOneToMany($obj);
				$obj = $this->mapManyToOne($obj);
                array_push($rows, $obj);
			}
		}

		return $rows;
	}
    
    private function mapOneToMany($obj){
        if(is_array($this->oneMany)){
            foreach ($obj as $prop => $valor) {
                if(array_key_exists($prop, $this->oneMany)){
                    try{
                        $clazzName = $this->oneMany[$prop];
                        require_once $clazzName.".php";
                        $objClazz = new $clazzName();
                        if(!$objClazz instanceof Model){
                            throw new Exception($clazzName." classe incompativel.");
                        }
                        $objRelac = $objClazz->obter($valor);
                        $obj->$prop = $objRelac;
                    }catch(Exception $ex){
                        $this->base->logErro("Erro ao consultar objeto relacionado: ".$ex);
                    }
                }
            }
        }
        return $obj;
    }
    
    private function mapManyToOne($obj){
        if(is_array($this->manyOne)){
            foreach($this->manyOne as $prop => $clazzName){
                try{
                    $filtro = array($this->manyOneId => "=".$obj->id);
                    require_once $clazzName.".php";
                    $objClazz = new $clazzName();
                    if(!$objClazz instanceof Model){
                        throw new Exception($clazzName." classe incompativel.");
                    }
                    $obj->$prop = $objClazz->select($filtro);
                }catch(Exception $ex){
                    $this->base->logErro("Erro ao consultar lista relacionada: ".$ex);
                }
            }
        }
        return $obj;
    }

	protected function getById($id){
		$campoId = array_search("id", $this->columns);
		if($campoId !== FALSE){
			$condition = array($campoId."=".$id);
			$result = $this->select($condition);
			foreach($result as $obj){
				return $obj;
			}
		}
		return NULL;
	}

	protected final function parseBoolean($value){
		return ($value == 0) ? "FALSE" : "TRUE";
	}


}
