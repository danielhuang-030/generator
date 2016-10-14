<?php
$tabList = [
    'index', 
    'dao',
    'vo',
    'migration',
];

$action = 'index';
$pattern = '#/(\w+?).php#i';
if (preg_match($pattern, $_SERVER['REQUEST_URI'], $matches)) {
    $action = $matches[1];
}
$projectName = '';
$pattern = '#^/(\w*)/?#i';
if (preg_match($pattern, $_SERVER['REQUEST_URI'], $matches)) {
    $projectName = $matches[1];
}
// print_r($projectName);exit;
?>

<ul class="test1 nav nav-tabs" style="margin: 20px 0px 22px; visibility: visible; ">
<?php foreach ($tabList as $tab) { ?>
  <li class="<?php if ($tab == $action) { ?>active<?php } ?>"><a href="<?php echo sprintf("/%s/%s.php", $projectName, $tab); ?>" aria-expanded="false"><?php echo $tab; ?></a></li>
<?php } ?>
</ul>