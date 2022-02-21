<?php
namespace booosta\graphql;

use GraphQL\Error\ClientAware;

use \booosta\Framework as b;
b::init_module('graphql');

class Graphql extends \booosta\webapp\Webapp
{
  use moduletrait_graphql;

  protected $datafields = [];
  protected $public_datafields = [];

  protected $editfields = [];
  protected $public_editfields = [];

  protected $coordinates_columns = [];

  protected $blacklist = [];
  protected $subitems = [];
  protected $foreignkeys = [];
  protected $orderby = [];

  protected $publicuser_filter = [];
  protected $user_filter = [];

  protected $sent_authtoken;
  protected $schemafile;
  protected $use__call = false;  // use the __call() overloading method
  protected $user_id, $user_type;


  public function __construct()
  {
    parent::__construct();
    if(is_string($this->datafields)) $this->datafields = explode(',', $this->datafields);
    if(is_string($this->public_datafields)) $this->public_datafields = explode(',', $this->public_datafields);
    if(is_string($this->editfields)) $this->editfields = explode(',', $this->editfields);
    if(is_string($this->public_editfields)) $this->public_editfields = explode(',', $this->public_editfields);

    foreach($this->blacklist as $table => $column)
      if(is_string($column))
        $this->blacklist[$table] = [$column];

    foreach($this->subitems as $table => $column)
      if(is_string($column))
        $this->subitems[$table] = [$column];

    foreach($this->foreignkeys as $table => $column)
      if(is_string($column))
        $this->foreignkeys[$table] = [$column];
  }

  public function __call($name, $args)
  {
    #debug("in $name()");
    $name = str_replace('exec__', '', $name);
    #debug($args);
    if(is_numeric($args[0])) $args = [['id' => $args[0], '__mode' => 'single_result']];
    #debug($args);

    if(in_array($name, $this->datafields)):
      $this->db_auth();
      $table = $name;
    elseif(in_array($name, $this->public_datafields)):
      $table = $name;
    else:
      throw new GraphqlException("Method $name not found or field $name not present in datafields");
    endif;

    $clause = [];
    $vals = [];
    foreach($args[0] as $var => $val):
      if(substr($var, 0, 2) == '__') continue;

      $clause[] = " `$var`=?";
      $vals[] = $val;
    endforeach;

    $filtervar = "{$this->user_type}_filter";
    #debug("filtervar: $filtervar"); debug($this->$filtervar);
    foreach($this->$filtervar as $filtered_table => $filter):
      if($filtered_table != $table) continue;

      foreach($filter as $field => $value):
        $value = str_replace('{user_id}', $this->user_id, $value);
        $clause[] = " `$field`=? ";
        $vals[] = $value;
      endforeach;
    endforeach;

    $operator = $args['__operator'] ?? 'and';

    if(sizeof($clause)) $whereclause = ' where ' . implode(" $operator ", $clause);
    else $whereclause = '';

    if($order = $args['__orderby']):
      $orderclause = " order by $order ";
    elseif($order = $this->orderby[$table]):
      $orderclause = " order by $order ";
    else:
      $orderclause = '';
    endif;

    if($this->debugmode) $limit = "limit 0, 100"; else $limit = '';
    #debug("table: $table, clause: $whereclause"); debug($vals);
    #debug($args);
    if($args[0]['__mode'] == 'single_result'):
      $result = $this->DB->query_list("select *, concat('$table-', id) as graphqlid from `$table` $whereclause", $vals);
      $result = $this->adjust_result($result, $table);
    else:
      $result = $this->DB->query_arrays("select *, concat('$table-', id) as graphqlid from `$table` $whereclause $orderclause $limit", $vals);
      foreach($result as $idx => $res) $result[$idx] = $this->adjust_result($res, $table);
    endif;

    #debug('result:'); debug($result);
    return $result;
  }

  protected function prepare_edit($field, $args)
  {
    #debug("field: $field"); debug($args);
    $this->VAR = $args['data'];
    if($this->VAR['id']) $this->VAR['object_id'] = $this->VAR['id'];
    $this->name = $this->classname = $field;
    $this->no_output = true;
    $this->use_form_token = false;
  }

  protected function create__record($field, $args)
  {
    #debug("field: $field"); debug($args);
    $this->prepare_edit($field, $args);
    $this->action_newdo();
    return $this->$field($this->newid);
  }

  protected function update__record($field, $args)
  {
    $this->prepare_edit($field, $args);
    $this->id = $args['data']['id'];
    $this->action_editdo();
    return $this->$field($this->id);
  }

  protected function delete__record($field, $args)
  {
    #debug("field: $field"); debug($args);
    $this->prepare_edit($field, $args);
    $this->id = $args['id'];
    #debug("field: $field, id: $this->id");
    $this->action_deleteyes();
    return ['status' => 'OK'];
  }

  public function raise_error($message, $backpage = null, $translate = true)
  {
    throw new GraphqlException($message);
  }

  protected function adjust_result($result, $table)
  {
    if($result === null) return null;

    if(is_array($this->blacklist[$table]))
        foreach($this->blacklist[$table] as $column)
          unset($result[$column]);

    foreach($this->coordinates_columns as $cc)
      if(array_key_exists($cc, $result)):
        $coord = $this->DB->get_geo_coordinates($table, $result['id'], $cc);
        $result[$cc] = $coord ? ['lat' => $coord[0], 'lon' => $coord[1]] : null;
      endif;

    if(is_array($this->subitems[$table]))
      foreach($this->subitems[$table] as $column):
        $dbfield = null;
        if(strstr($column, '/')) list($column, $dbfield) = explode('/', $column);
        $dbfield ??= $table;

        $id = $result['id'];
        #debug("table: $table, column: $column, dbfield: $dbfield, id: $id");
        $result[$column] = function() use ($id, $dbfield, $column) { return $this->$column([$dbfield => $id]); };

        $dbfield = null;
      endforeach;

    if(is_array($this->foreignkeys[$table]))
      foreach($this->foreignkeys[$table] as $column):
        if(strstr($column, '/')) list($ftable, $column) = explode('/', $column);
        $ftable ??= $column;

        $id = $result[$column];
        $result[$column] = function() use ($id, $ftable) { return $this->$ftable(['id' => $id, '__mode' => 'single_result']); };

        $ftable = null;
      endforeach;

    return $result;
  }

  protected function init()
  {
    parent::init();
    if($this->VAR['authtoken']) $this->sent_authtoken = $this->VAR['authtoken'];
    elseif($_SERVER['HTTP_AUTHORIZATION']) $this->sent_authtoken = $_SERVER['HTTP_AUTHORIZATION'];
    #\booosta\debug($_SERVER);

    $this->db_auth(false);   // false = do not require authentification at this point
  }

  public function execute()
  {
    #\booosta\debug($_SERVER);
    #\booosta\debug(file_get_contents('php://input'));

    try {
      $schemafile = $this->schemafile ?? 'incl/schema.graphql';
      $schema = \GraphQL\Utils\BuildSchema::build(file_get_contents($schemafile));
      #debug(111);
      $rootValue = $this->get_rootValue();
  
      $rawInput = file_get_contents('php://input');
      $input = json_decode($rawInput, true);
      #\booosta\debug($input);
      $query = $input['query'];
      $variableValues = isset($input['variables']) ? $input['variables'] : null;

      $result = \GraphQL\GraphQL::executeQuery($schema, $query, $rootValue, null, $variableValues);

    } catch (\Exception $e) {
      #debug('exception'); debug($e->getMessage());
      $result = [ 'errors' => [ 'message' => $e->getMessage() ] ];
    }

    #debug($result);
    $this->response($result);
  }

  public function __invoke() { $this->execute(); }

  protected function get_rootValue()
  {
    $result = [];
    $datafields = array_merge($this->datafields, $this->public_datafields);
    #debug($datafields);

    foreach($datafields as $field):
      $result ["get_$field"] = $result[$field] = 
        function($rootValue, $args, $context) use ($field) {
          $funcname = strtolower("resolve__$field");
          $funcname1 = strtolower($field);
          $funcname2 = strtolower("exec__$field");

          if(method_exists($this, $funcname)) return call_user_func_array([$this, $funcname], [$rootValue, $args, $context]);
          elseif(method_exists($this, $funcname1)) return call_user_func_array([$this, $funcname1], [$args]);
          elseif(method_exists($this, $funcname2) || $this->use__call) return call_user_func_array([$this, $funcname2], [$args]);
          else return [];
        };

      $result ["fetch_$field"] = 
        function($rootValue, $args, $context) use ($field) {
          $funcname = strtolower("resolve__$field");
          $funcname1 = strtolower($field);
          $funcname2 = strtolower("exec__$field");

          $args['__mode'] = 'single_result';
          if(method_exists($this, $funcname)) return call_user_func_array([$this, $funcname], [$rootValue, $args, $context]);
          elseif(method_exists($this, $funcname1)) return call_user_func_array([$this, $funcname1], [$args]);
          elseif(method_exists($this, $funcname2) || $this->use__call) return call_user_func_array([$this, $funcname2], [$args]);
          else return [];
        };
    endforeach;

    $editfields = array_merge($this->editfields, $this->public_editfields);
    foreach($editfields as $field):
      $result ["create_$field"] = 
        function($rootValue, $args, $context) use ($field) {
          $funcname = strtolower("create_$field");

          if(method_exists($this, $funcname)) return call_user_func_array([$this, $funcname], [$args]);
          elseif($this->use__call) return call_user_func_array([$this, 'create__record'], [$field, $args]);
          else return [];
        };

      $result ["update_$field"] = 
        function($rootValue, $args, $context) use ($field) {
          $funcname = strtolower("update_$field");

          if(method_exists($this, $funcname)) return call_user_func_array([$this, $funcname], [$args]);
          elseif($this->use__call) return call_user_func_array([$this, 'update__record'], [$field, $args]);
          else return [];
        };

      $result ["delete_$field"] = 
        function($rootValue, $args, $context) use ($field) {
          $funcname = strtolower("delete_$field");

          if(method_exists($this, $funcname)) return call_user_func_array([$this, $funcname], [$args]);
          elseif($this->use__call) return call_user_func_array([$this, 'delete__record'], [$field, $args]);
          else return [];
        };
    endforeach;
    return $result;
  }

  protected function response($result)
  {
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: X-PINGOTHER, Content-Type, Authorization');

    print json_encode($result);

    $this->no_output = true;
  }

  public function graphql_auth($proper_token = null)
  {
    if($proper_token) $this->check_auth($proper_token);
    elseif(($proper_token = $this->config('api_authtoken'))) $this->check_auth($proper_token);
    return true;   // no auth per default
  }

  public function db_auth($required = true)
  {
    #\booosta\debug($_SERVER);
    if($required && !$this->sent_authtoken) $this->auth_fail();

    $crypterclass = $this->config('crypter_class') ? $this->config('crypter_class') : 'aes256';
    $crypter = $this->makeInstance($crypterclass);

    $token = $crypter->encrypt($this->sent_authtoken);
    $id = $publicuser_id = $this->DB->query_value("select id from publicuser where token=? and active='1'", $token);
    if(!$publicuser_id) $id = $this->DB->query_value("select id from user where token=? and active='1'", $token);

    if($required && !$id) $this->auth_fail();
    $this->user_id = $id;
    $this->user_type = $this->user_class = $publicuser_id ? 'publicuser' : 'user';
  }

  // can be overridden
  protected function check_auth($proper_token)
  {
    if($proper_token != $this->sent_authtoken) $this->auth_fail();
  }

  protected function auth_fail($msg = '')
  {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: X-PINGOTHER, Content-Type, Authorization');
    header('HTTP/1.0 401 Unauthorized');

    print json_encode(['code' => '401', 'message' => 'Unauthorized', 'status' => 'Error']);
    exit;
  }

  protected function fail($msg = '')
  {
    $this->response(['status' => 'ERROR', 'message' => $msg]);
    exit;
  }

  protected function chkerror($obj, $info = null)
  {
    if(!is_object($obj) || !is_callable([$obj, 'get_error'])) return false;

    if($error = $obj->get_error()) throw new GraphqlException($error, $info);
    return true;
  }

  protected function force_usertype($usertype)
  {
    $this->db_auth();
    if(!is_array($usertype)) $usertype = [$usertype];
    if(!in_array($this->user_type, $usertype)) throw new GraphqlException("Access denied for usertype $this->user_type", 'Authorization failure');
  }
}


class GraphqlException extends \Exception implements ClientAware
{
  protected $info;
  
  public function __construct($message = null, $info = null)
  {
    $this->info = $info;
    parent::__construct($message);
  }

  public function isClientSafe()
  {
    return true;
  }

  public function getCategory()
  {
    return $this->info ?? 'Application Exception';
  }
}
