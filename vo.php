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
    $className = underlineToCamelcase(preg_replace('#s$#i', '', $table), true);
    $tableData = getTableData($db, $table);
    if (empty($tableData)) {
        $result['isError'] = true;
        $result['message'] = 'can not get table data';
        exit(json_encode($result));
    }
    
    // set and get
    $setAndGetStr = '';
    foreach ($tableData as $data) {
        $setMethod = sprintf("set%s(\$value)", underlineToCamelcase($data['field'], true));
        $getMethod = sprintf("get%s()", underlineToCamelcase($data['field'], true));
        $setAndGetStr .= <<<EOT

 * @method void $setMethod
EOT;
        if (in_array($data['type'], ['tinyint', 'int', 'smallint', 'bigint', 'float', 'decimal', 'timestamp', 'datetime', 'date'])) {
            $setAndGetStr .= <<<EOT

 * @method int $getMethod
EOT;
        } else {
            $setAndGetStr .= <<<EOT

 * @method string $getMethod
EOT;
        }
    }
        
    // const
    $constStr = '';
    foreach ($tableData as $data) {
        if (in_array($data['type'], ['tinyint'])) {
            $constDesc = str_replace('_', ' ', $data['field']);
            $constName = strtoupper($data['field']);
            $constStr .= <<<EOT

    /**
     * $constDesc disable
     * 
     * @var int
     */
    const {$constName}_DISABLE = 0;
    
    /**
     * $constDesc enable
     * 
     * @var int
     */
    const {$constName}_ENABLE = 1;
    
    /**
     * $constDesc delete
     * 
     * @var int
     */
    const {$constName}_DELETE = 2;
    
EOT;
        }
    }
        
    // tableDefinition
    $tableDefinitionStr = <<<EOT
    /**
     * get table definition
     *
     * @return array
     */
    public static function getTableDefinition()
    {
        return [
EOT;
    foreach ($tableData as $data) {
        $key = $data['field'];
        $index = underlineToCamelcase($key);
        if (in_array($key, ['properties'])) {
            $tableType = 'string';
            $tableFilters = "array('arrayval')";
        } elseif (in_array($data['type'], ['tinyint', 'int', 'smallint', 'bigint'])) {
            $tableType = 'integer';
            $tableFilters = "array('intval')";
        } elseif (in_array($data['type'], ['float'])) {
            $tableType = 'float';
            $tableFilters = "array('floatval')";
        } elseif (in_array($data['type'], ['decimal'])) {
            $tableType = 'string';
            $tableFilters = "array('trim')";
        } elseif (in_array($data['type'], ['varchar', 'text'])) {
            $tableType = 'string';
            $tableFilters = "array('strip_tags', 'trim')";
        } elseif (in_array($data['type'], ['char'])) {
            $tableType = 'string';
            $tableFilters = "array('strip_tags')";
        } elseif (in_array($data['type'], ['timestamp', 'datetime', 'date'])) {
            $tableType = 'timestamp';
            $tableFilters = "array('dateval')";
        } else {
            $tableType = sprintf("??? // %s", $data['type']);
            $tableFilters = "array('???')";
        }
        $tableStorage = sprintf('get%s', underlineToCamelcase($key, true));
        $tableField = $key;
        $tableValueStr = '';
        if (in_array($data['type'], ['createTime', 'updateTime'])) {
            $tableValueStr = sprintf("'value' => time(),");
        } elseif (in_array($data['type'], ['timestamp', 'datetime', 'date'])) {
            $tableValueStr = sprintf("'value' => strtotime('1970-01-01 00:00:01'),");
        } elseif ('status' == $key) {
            $tableValueStr = sprintf("'value' => static::STATUS_DISABLE,");
        } elseif (in_array($data['type'], ['tinyint'])) {
            $tableValueStr = sprintf("'value' => static::%s_ENABLE,", strtoupper($key));
        }
        if (!empty($tableValueStr)) {
            $tableValueStr = <<<EOT

                $tableValueStr
EOT;
        }
        
        $tableDefinitionStr .= <<<EOT

            '$index' => [
                'type' => '$tableType',
                'filters' => $tableFilters,
                'storage' => '$tableStorage',
                'field' => '$tableField',$tableValueStr
            ],
EOT;
    }
    $tableDefinitionStr .= <<<EOT
        
        ];
    }
EOT;
    
    // validate
    $validateStr = <<<EOT

    /**
     * validate
     *
     * @return array
     */
    public function validate()
    {
        \$messages = [];
EOT;
    
    foreach ($tableData as $data) {
        $key = $data['field'];
        $getMethod = sprintf('get%s()', underlineToCamelcase($key, true));
        if ('key' == $key) {
            $validateStr .= <<<EOT


        // 予許空值, 但是不予許錯誤的格式
        if (\$this->getKey() && !preg_match('/^[0-9a-z_\-]+$/is', \$this->getKey() )) {
            \$messages['$key'] = '該欄位必須是英文或數字, 不可以使用特殊符號';
        }
EOT;
        } elseif ('email' == $key) {
            $validateStr .= <<<EOT


        // email
        \$result = filter_var(\$this->getEmail(), FILTER_VALIDATE_EMAIL);
        if (!\$result) {
            \$messages['$key'] = 'The Email is validation fails.';
        }
EOT;
        } elseif (in_array($key, ['url', 'link'])) {
            $validateStr .= <<<EOT


        // url
        \$result = filter_var(\$this->getUrl(), FILTER_VALIDATE_URL);
        if (!\$result) {
            \$messages['$key'] = 'The field is validation fails';
        }
EOT;
        } elseif (in_array($key, ['name', 'title', 'topic'])) {
            $validateStr .= <<<EOT


        if (empty(\$this->$getMethod)) {
            \$messages['$key'] = 'The field is required.';
        } elseif (!TextValidator::validateNormalTopic(\$this->$getMethod)) {
            \$messages['$key'] = 'The field is validation fails.';
        }
EOT;
        } elseif (in_array($data['type'], ['varchar'])) {
            $validateStr .= <<<EOT


        if (empty(\$this->$getMethod)) {
            \$messages['$key'] = 'The field is required.';
        }
EOT;
        } elseif (in_array($data['type'], ['tinyint'])) {
            $validateStr .= <<<EOT


        // choose value
        \$result = false;
        foreach (cc('attribList', \$this, '$key') as \$name => \$value) {
            if (\$this->$getMethod === \$value) {
                \$result = true;
                break;
            }
        }
        if (!\$result) {
            \$messages['$key'] = '$key incorrect';
        }
EOT;
        }
    }
    $validateStr .= <<<EOT

    }
EOT;
    
    // get vo
    $getVoStr = '';
    foreach ($tableData as $data) {
        $key = $data['field'];
        $pattern = '#(\w+?)_id#i';
        if (!preg_match($pattern, $key, $matches)) {
            continue;
        }
        $relatedName = $matches[1];
        $relatedTableName = sprintf("%ss", $relatedName);
        $relatedTableData = getTableData($db, $relatedTableName);
        if (empty($relatedTableData)) {
            continue;
        }
        $voName = underlineToCamelcase($relatedName, true);
        $variableName = underlineToCamelcase($relatedName);
        $relatedClassName = underlineToCamelcase($relatedTableName, true);
        $getVoStr .= <<<EOT

    /**
     * $voName
     * 
     * @var $voName
     */
    protected $$variableName;
    
    /**
     * get $variableName object
     *
     * @param boolean \$isCacheBuffer
     * @return $voName
     */
    public function get$voName(\$isCacheBuffer = false)
    {
        if (!\$isCacheBuffer) {
            \$this->$variableName = null;
        }
        if (\$this->$variableName instanceof $voName) {
            return \$this->$variableName;
        }

        \$id = (int) \$this->get{$voName}Id();
        if (0 === \$id) {
            return null;
        }
        \${$variableName}s = new $relatedClassName();
        $$variableName = \${$variableName}s->get{$voName}(\$id);

        if (\$isCacheBuffer) {
            \$this->$variableName = $$variableName;
        }
        return $$variableName;
    }
            
EOT;
        
    }

    $html = <<<EOT
    
<?php
/**
 * $className value object
 * $setAndGetStr 
 * @author $author
 * @since $since
 */
class $className extends BaseObject
{
$constStr
$tableDefinitionStr
$validateStr

    /**
     * Disabled methods
     *
     * @return array
     */
    public static function getDisabledMethods()
    {
        return [];
    }
$getVoStr

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
    <script src="//maxcdn.bootstrapcdn.com/bootstrap/3.3.1/js/bootstrap.min.js"></script>
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
          <button type="button" id="submit" class="btn btn-default">Submit</button>
          <button type="button" class="copyClipboard btn btn-default" data-clipboard-target="#resultBlock" style="display: none; "><i class="fa fa-copy"></i></button>
        </div>
      </form>
      
      <div class="bs-example" <?php /*style="display: none; "*/ ?>><pre><code id="resultBlock" class="php"></code></pre></div>
    </div>
  </body>
</html>
