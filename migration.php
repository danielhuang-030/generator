<?php

// 設定
require(sprintf('%s/config/setting.php', __DIR__));

// 連接資料庫
$dbConfig = require(sprintf('%s/config/db.php', __DIR__));
$dbType = 'mysql';
$dbLocation = 't2_wms';
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
function getGreateSql(&$db = null, $table = '') {
    $table = trim($table);
    if (empty($table) || !$db instanceof PDO) {
        return '';
    }
    
    $createSql = sprintf("SHOW CREATE TABLE `%s`", $table);
    $sth = $db->prepare($createSql);
    $sth->execute();
    $dataList = $sth->fetchAll(PDO::FETCH_ASSOC);
    if (empty($dataList)) {
        return '';
    }
    $data = current($dataList);
    return $data['Create Table'];
}

if (!empty($_POST)) {
    $result = [
        'isError' => false,
        'message' => 'success',
        'html' => '',
    ];

    // SQL 必填
    if (empty(trim($_POST['sql']))) {
        $result['isError'] = true;
        $result['message'] = 'sql can not be empty';
        exit(json_encode($result));
    }
  
    // 解析 SQL
    $sql = $upSql = trim($_POST['sql']);
    $sql = preg_replace('#\s+#', ' ', $sql);
    $downSql = '';
    
    // 取得 table name
    $pattern = '#TABLE.*?`(\w+)`#i';
    if (!preg_match($pattern, $sql, $matches)) {
        $result['isError'] = true;
        $result['message'] = 'sql can not get table name';
        exit(json_encode($result));
    }
    $tableName = $matches[1];
    $className = sprintf('Version%s_%s', date('Ymd'), implode('_', array_map('ucfirst', explode('_', $tableName))));
    
    // 建立 table
    $pattern = '#CREATE TABLE#i';
    if (preg_match($pattern, $sql, $matches)) {
        $downSql .= sprintf("DROP TABLE `%s`;\n", $tableName);
    }
    
    // 刪除 table
    $pattern = '#DROP TABLE#i';
    if (preg_match($pattern, $sql, $matches)) {
        // 取得 CREATE 語法
        $createSql = getGreateSql($db, $tableName);
        if (!empty($createSql)) {
            $downSql .= sprintf("%s;\n", $createSql);
        }
    }

    // 修改欄位
    $pattern = '#ALTER TABLE(.*?;)#i';
    if (preg_match_all($pattern, $sql, $alterMatches, PREG_SET_ORDER)) {
        $alterSqlList = [];
        foreach ($alterMatches as $alter) {
            $alterSql = $alter[1];
            $createSql = getGreateSql($db, $tableName);

            // 新增修改欄位
            $pattern = '#(ADD|CHANGE) `(\w+)`(?: `(\w+)`)* (.+?)(,|;)#i';
            if (preg_match_all($pattern, $alterSql, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $type = strtoupper($match[1]);
                    switch ($type) {
                        // 新增
                        case 'ADD':
                            $alterSqlList[] = sprintf(" DROP `%s`", $match[2]);
                            break;

                        // 修改
                        case 'CHANGE':
                        case 'MODIFY':
                            // 取得 CREATE 語法
                            $createSql = getGreateSql($db, $tableName);
                            if (!empty($createSql)) {
                                $createPattern = sprintf("#`%s` (.*?)(?:,|\s\))#i", $match[2]);
                                if (preg_match($createPattern, $createSql, $createMatches)) {
                                    $alterSqlList[] = sprintf(" CHANGE `%s` `%s` %s", $match[3], $match[2], $createMatches[1]);
                                }
                            }
                            break;
                    }
                }
            }

            // 刪除欄位
            $pattern = '#(DROP) `(\w+)`(,|;)#i';
            if (preg_match_all($pattern, $alterSql, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    // 取得 CREATE 語法
                    $createSql = getGreateSql($db, $tableName);
                    if (!empty($createSql)) {
                        $createPattern = sprintf("#`%s` (.*?)(?:,|\s\))#i", $match[2]);
                        if (preg_match($createPattern, $createSql, $createMatches)) {
                            $addSql = sprintf(" ADD `%s` %s", $match[2], $createMatches[1]);
                            $createAfterPattern = sprintf("#,\s+`(\w+)`.*,\s+%s#i", str_replace('ADD ', '', preg_quote($addSql)));
                            if (preg_match($createAfterPattern, $createSql, $createAfterMatches)) {
                                $addSql .= sprintf(" AFTER `%s`", $createAfterMatches[1]);
                            }
                            $alterSqlList[] = $addSql;
                        }
                    }
                }
            }

            // 新增修改屬性
            $pattern = '#(ADD|DROP).+?(KEY|INDEX) `(\w+)`#i';
            if (preg_match_all($pattern, $alterSql, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $type = strtoupper($match[1]);
                    switch ($type) {
                        // 新增
                        case 'ADD':
                            $alterSqlList[] = sprintf(" DROP INDEX `%s`", $match[3]);
                            break;

                        // 刪除
                        case 'DROP':
                            // 取得 CREATE 語法
                            $createSql = getGreateSql($db, $tableName);
                            if (!empty($createSql)) {
                                $createPattern = sprintf("#(\w*? KEY `%s` \(`\w+`\))#i", $match[3]);
                                if (preg_match($createPattern, $createSql, $createMatches)) {
                                    $alterSqlList[] = sprintf("ADD %s", $createMatches[1]);
                                }
                            }
                            break;
                    }
                }

            }
        }
        
        // 重組 ALTER SQL
        if (!empty($alterSqlList)) {
            $downSql .= sprintf("ALTER TABLE `%s`%s;\n", $tableName, implode(', ', $alterSqlList));
        }
    }
    // exit('none');
    
    $html = <<<EOT
    
<?php
namespace DoctrineMigrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * $className
 *
 * @author $author
 * @since $since
 */
class $className extends AbstractMigration
{
    /**
     * Run the migrations
     *
     * @param Schema \$schema
     */
    public function up(Schema \$schema)
    {
        \$sql = <<<EOD

$upSql

EOD;
        \$this->addSql(\$sql);
        
    }

    /**
     * Reverse the migrations
     *
     * @param Schema \$schema
     */
    public function down(Schema \$schema)
    {
        \$sql = <<<EOD

$downSql

EOD;
        \$this->addSql(\$sql);

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
          <label for="comment">SQL</label>
          <textarea class="form-control" rows="5" id="sql" name="sql" required="required"></textarea>
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
