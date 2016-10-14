<!DOCTYPE html>
<html lang="zh-Hant">
  <head>
    <meta charset="utf-8">
    <title>index</title>
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
 
  </head>
  <body>
    <div class="container">
      <?php require(sprintf("%s/block/tab.php", __DIR__)); ?>
      <h3>It works!</h3>
    </div>
  </body>
</html>
