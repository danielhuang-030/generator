<?php

// 設定
require(sprintf('%s/config/setting.php', __DIR__));

// 連接資料庫
$dbConfig = require(sprintf('%s/config/db.php', __DIR__));
$dbType = 'mysql';
$dbLocation = 'wms';
try {
    $db = new PDO(sprintf('%s:dbname=%s;host=%s', $dbType, $dbConfig[$dbType][$dbLocation]['db'], $dbConfig[$dbType][$dbLocation]['host']), $dbConfig[$dbType][$dbLocation]['user'], $dbConfig[$dbType][$dbLocation]['pass'], [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"]);
} catch (PDOException $e) {
    exit($e->getMessage());
}

/**
 * 取得建立 table SQL
 * 
 * @param PDO $db
 * @return string
 */
function getTableData(&$db = null, $table = '') {
    $tableData = [];
    
    $table = trim($table);
    if (empty($table) || !$db instanceof PDO) {
        return $tableData;
    }
    
    $sql = sprintf("SHOW FULL FIELDS FROM `%s`", $table);
    $sth = $db->prepare($sql);
    $sth->execute();
    $dataList = $sth->fetchAll(PDO::FETCH_ASSOC);
    if (empty($dataList)) {
        return $tableData;
    }

    foreach ($dataList as $data) {
        $pattern = '#(\w*)(?:\((\d+)\))?(?: (\w*))?#i';
        if (!preg_match($pattern, $data['Type'], $types)) {
            continue;
        }
        
        $tableData[] = [
            'field' => $data['Field'],
            'type' => !empty($types[1]) ? $types[1] : '',
            'length' => !empty($types[2]) ? $types[2] : 0,
            'property' => !empty($types[3]) ? $types[3] : '',
            'collation' => $data['Collation'],
            'null' => 'NO' == $data['Null'] ? false : true,
            'key' => $data['Key'],
            'default' => $data['Default'],
            'extra' => $data['Extra'],
            'privileges' => $data['Privileges'],
            'comment' => $data['Comment'],
        ];
    }
    return $tableData;
}

/**
 * camel case replace
 * 
 * @param string $string
 * @return string
 */
function camelCaseReplace($string = '') {
    return preg_replace_callback(
        "/(_([a-z]))/",
        function($match) {
            return strtoupper($match[2]);
        },
        $string
    );
}

/**
 * camelcase replace to underline
 * 
 * @param string $string
 * @return string
 */
function camelcaseToUnderline($string = '') {
    return preg_replace_callback(
        "/(_([a-z]))/",
        function($match) {
            return strtoupper($match[2]);
        },
        $string
    );
}

/**
 * underline replace to camelcase
 * 
 * @param string $string
 * @param boolean $isUcfirst
 * @return string
 */
function underlineToCamelcase($string = '', $isUcfirst = false) {
    $string = ucwords(str_replace('_', ' ', $string));
    $string = str_replace(' ', '', lcfirst($string));
    return $isUcfirst ? ucfirst($string) : $string;
}

if (!empty($_POST)) {
    $result = [
        'isError' => false,
        'message' => 'success',
        'html' => '',
    ];

    // SQL 必填
    if (empty(trim($_POST['table']))) {
        $result['isError'] = true;
        $result['message'] = 'table name can not be empty';
        exit(json_encode($result));
    }
  
    // 取得 table
    $table = trim($_POST['table']);
    $className = underlineToCamelcase($table, true);
    $voName = preg_replace('#s$#i', '', $className);
    $cacheConstName = sprintf("CACHE_%s", strtoupper($table));
    $cacheName = strtolower($cacheConstName);
    $tableData = getTableData($db, $table);
    if (empty($tableData)) {
        $result['isError'] = true;
        $result['message'] = 'can not get table data';
        exit(json_encode($result));
    }
    
    // map row
    $mapRowStr = <<<EOT

    /**
     * get db object by record
     *
     * @param array \$row
     * @return $voName
     */
    public function mapRow(\$row = [])
    {
        \$vo = new $voName();
EOT;
    foreach ($tableData as $data) {
        $setMethod = sprintf("set%s", underlineToCamelcase($data['field'], true));
        $key = $data['field'];
        if (in_array($data['type'], ['timestamp', 'datetime', 'date'])) {
            $mapRowStr .= <<<EOT

        \$vo->$setMethod(strtotime(\$row['$key']));
EOT;
        } elseif ('properties' == $key) {
            $mapRowStr .= <<<EOT

        \$vo->$setMethod(unserialize(\$row['$key']));
EOT;
        } else {
            $mapRowStr .= <<<EOT

        \$vo->$setMethod(\$row['$key']);
EOT;
        }
    }
    $mapRowStr .= <<<EOT

        return \$vo;
    }
EOT;

    // validate
    $validateStr = '';
    foreach ($tableData as $data) {
        $key = underlineToCamelcase($data['field']);
        $fieldName = $data['field'];
        $validateStr .= <<<EOT

                '$key' => '$fieldName',
EOT;
    }
    
    // where
    $whereStr = '';
    foreach ($tableData as $data) {
        $key = underlineToCamelcase($data['field']);
        if (in_array($data['field'], ['text'])) {
            $whereStr .= <<<EOT

        if (isset(\$opt['$key'])) {
            \$select->where->and->like(\$field['$key'], '%' . \$opt['$key'] . '%');
        }
EOT;
        } else {
            $whereStr .= <<<EOT

        if (isset(\$opt['$key'])) {
            \$select->where->and->equalTo(\$field['$key'], \$opt['$key']);
        }
EOT;
        }
    }
    
    $html = <<<EOT

<?php
/**
 * $className
 *
 * @author $author
 * @since $since
 */
class $className extends ZendModel
{
    /**
     * cache
     *
     * @var string
     */
    const $cacheConstName = '$cacheName';

    /**
     * table name
     *
     * @var string
     */
    protected \$tableName = '$table';

    /**
     * get method
     *
     * @var string
     */
    protected \$getMethod = 'get$voName';

$mapRowStr
        
    /**
     * add $voName
     *
     * @param $voName \$vo
     * @return $voName
     */
    public function add$voName($voName \$vo = null)
    {
        \$insertId = (int) \$this->addObject(\$vo, true);
        if (0 === \$insertId) {
            return null;
        }

        \$vo = \$this->get$voName(\$insertId);
        if (!\$vo instanceof $voName) {
            return null;
        }
        return \$vo;
    }

    /**
     * update $voName
     *
     * @param $voName \$vo
     * @return boolean
     */
    public function update$voName($voName \$vo = null)
    {
        try {
            \$this->updateObject(\$vo);
        } catch (Exception \$ex) {
            // \$ex->getMessage();
            return false;
        }

        \$this->preChangeHook(\$vo);
        return true;
    }

    /**
     * delete $voName
     *
     * @param int \$id
     * @return boolean
     */
    public function delete$voName(\$id = 0)
    {
        \$vo = \$this->get$voName(\$id);
        if (!\$vo instanceof $voName) {
            return true;
        }
        if (!\$this->deleteObject(\$id)) {
            return false;
        }

        \$this->preChangeHook(\$vo);
        return true;
    }

    /**
     * pre change hook, first remove cache, second do something more about update, delete
     * 
     * @param $voName \$vo
     */
    public function preChangeHook($voName \$vo = null)
    {
        // first, remove cache
        \$this->removeCache(\$vo);
    }

    /**
     * remove cache
     *
     * @param $voName \$vo
     */
    protected function removeCache($voName \$vo = null)
    {
        if (0 >= \$vo->getId()) {
            return;
        }

        \$cacheKey = \$this->getFullCacheKey(\$vo->getId(), static::$cacheConstName);
        CacheBrg::remove(\$cacheKey);
    }

    /**
     * get $voName by id
     *
     * @param int \$id
     * @return $voName
     */
    public function get$voName(\$id = 0)
    {
        \$vo = \$this->getObject('id', \$id, static::$cacheConstName);
        if (!\$vo instanceof $voName) {
            return null;
        }
        return \$vo;
    }

    /**
     * find many $voName
     *
     * @param array \$opt
     * @return {$voName}[]
     */
    public function find$className(\$opt = [])
    {
        \$opt += [
            '_order' => 'id,DESC',
            '_page' => 1,
            '_itemsPerPage' => conf('db.items_per_page')
        ];
        return \$this->find{$className}Real(\$opt);
    }

    /**
     * get count by "find$className" method
     *
     * @return int
     */
    public function numFind$className(\$opt = [])
    {
        return (int) \$this->find{$className}Real(\$opt, true);
    }

    /**
     * find$className by option
     *
     * @return {$voName}[] || int
     */
    protected function find{$className}Real(\$opt = [], \$isGetCount = false)
    {
        // validate 欄位 白名單
        \$list = [
            'fields' => [$validateStr
            ],
            'option' => [
                '_order',
                '_page',
                '_itemsPerPage',
                '_serverType',
            ]
        ];

        ZendModelWhiteListHelper::validateFields(\$opt, \$list);
        ZendModelWhiteListHelper::filterOrder(\$opt, \$list);
        ZendModelWhiteListHelper::fieldValueNullToEmpty(\$opt);

        \$select = \$this->getDbSelect();

        /*
        TODO: 組合式的請不要在這裡使用
        \$select
            ->from(
                array('main' => \$this->tableName),
                array()
            )
            ->join(
                array('t2' => 'table_2'),
                'main.id = t2.main_id',
                array()
            ->join(
                array('t3' => 'table_3'),
                'main.id = t3.main_id',
                array()
            )
        ;
        */

        // field
        \$field = \$list['fields'];

        /*
        \$select->where->and
            ->in(\$field['tags'], explode(',', \$opt['tags']));
            ->like(\$field['name'], '%' . \$opt['name'].'%');
            ->equalTo(\$field['id'], \$opt['id']);

            lessThan                <
            lessThanOrEqualTo       <=
            greaterThan             >
            greaterThanOrEqualTo    >=

        \$select->where->and
            ->nest
                ->like(\$field['favor'], '%'. \$favors[0] .'%')
                ->or
                ->like(\$field['favor'], '%'. \$favors[1] .'%')
            ->unnest
        */
$whereStr

        if (!\$isGetCount) {
            return \$this->findObjects(\$select, \$opt);
        }
        return (int) \$this->numFindObjects(\$select, \$opt);
    }

}

EOT;

    // $result['html'] = nl2br(str_replace(' ', '&nbsp', htmlspecialchars($html)));
    $result['html'] = htmlspecialchars($html);
    exit(json_encode($result));
    
}

?>

<!DOCTYPE html>
<html lang="zh-Hant">
  <head>
    <meta charset="utf-8">
    <title>generator</title>
    <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap-theme.min.css">
    <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/highlight.js/9.6.0/styles/default.min.css">
    <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css">
    <style>
    /* Echo out a label for the example */
    .bs-example {
        border-color: #e5e5e5 #eee #eee;
        border-style: solid;
        border-width: 1px 0;
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.05) inset;
        margin: 0 -15px 15px;
        padding: 45px 15px 15px;
        position: relative;
    }
    .bs-example::after {
        color: #959595;
        content: "Example";
        font-size: 12px;
        font-weight: bold;
        left: 15px;
        letter-spacing: 1px;
        position: absolute;
        text-transform: uppercase;
        top: 15px;
    }
    </style>
    
    <script src="//code.jquery.com/jquery-2.2.4.min.js" integrity="sha256-BbhdlvQf/xTY9gja0Dq3HiwQF8LaCRTXxZKRutelT44=" crossorigin="anonymous"></script>
    <script src="//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <script src="//cdn.jsdelivr.net/clipboard.js/1.5.12/clipboard.min.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/highlight.js/9.6.0/highlight.min.js"></script>

    <script type="text/javascript">
    $(function() {

    // 送出
    $("input").keydown(function(event) {
        if (event.which == 13) {
            $("#submit").trigger("click");
            return false;
        }
    });
    $("body").on("click", "#submit", function() {
      $("#resultBlock").hide();
    
      $.post(
        document.URL,
        $(this).parents("form").serialize(),
        function(json) {
          if (!json.isError) {
            $("#resultBlock").html(json.html).fadeIn();
            $(".copyClipboard").fadeIn();
            $('pre code').each(function(i, block) {
              hljs.highlightBlock(block);
            });
          } else {
            alert(json.message);
          }
        },
        "json"
      );
      
    });

    // copy to clipboard
    new Clipboard('.copyClipboard', {
      text: function(trigger) {
        return trigger.getAttribute('aria-label');
      }
    });

    });
    </script>
    
  </head>
  <body>
    <div class="container">
      <?php require(sprintf("%s/block/tab.php", __DIR__)); ?>
      <h3><?php echo $action; ?> generator</h3>
      <form class="well" method="post" id="sqlForm">
        <div class="form-group">
          <label for="comment">table</label>
          <input class="form-control" id="table" name="table" required="required" />
        </div>
        <div class="form-group">
          <div class="form-inline">
            <button type="button" id="submit" class="btn btn-default"><span class="icon icon-copy"></span>Submit</button>
            <button type="button" class="copyClipboard btn btn-default" data-clipboard-target="#resultBlock" style="display: none; "><i class="fa fa-copy"></i></button>
          </div>
        </div>
      </form>
      
      <div class="bs-example" <?php /*style="display: none; "*/ ?>><pre><code id="resultBlock" class="php"></code></pre></div>
    </div>
  </body>
</html>
