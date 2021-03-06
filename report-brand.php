<?
require 'scat.php';
require 'lib/item.php';

$sql_criteria= "1=1";
if (($items= $_REQUEST['items'])) {
  list($sql_criteria, $x)= item_terms_to_sql($db, $_REQUEST['items'],
                                             FIND_OR|FIND_ALL);
}

$begin= $_REQUEST['begin'];
$end= $_REQUEST['end'];

if (!$begin) {
  $begin= date('Y-m-d', time());
} else {
  $begin= $db->escape($begin);
}

if (!$end) {
  $end= date('Y-m-d', time());
} else {
  $end= $db->escape($end);
}

head("Brand Sales @ Scat", true);
?>
<form id="report-params" class="form-horizontal" role="form"
      action="<?=$_SERVER['PHP_SELF']?>">
  <div class="form-group">
    <label for="datepicker" class="col-sm-2 control-label">
      Dates
    </label>
    <div class="col-sm-10">
      <div class="input-daterange input-group" id="datepicker">
        <input type="text" class="form-control" name="begin"
               value="<?=ashtml($begin)?>" />
        <span class="input-group-addon">to</span>
        <input type="text" class="form-control" name="end"
               value="<?=ashtml($end)?>" />
      </div>
    </div>
  </div>
  <div class="form-group">
    <label for="items" class="col-sm-2 control-label">
      Items
    </label>
    <div class="col-sm-10">
      <input id="items" name="items" type="text"
             class="form-control" style="width: 20em"
             value="<?=ashtml($items)?>">
    </div>
  </div>
  <div class="form-group">
    <div class="col-sm-offset-2 col-sm-10">
      <input type="submit" class="btn btn-primary" value="Show">
    </div>
  </div>
</form>
<div id="results">
<?
/* Current */
$q= "CREATE TEMPORARY TABLE current
       (item INT UNSIGNED PRIMARY KEY,
        brand INT UNSIGNED NOT NULL,
        units INT NOT NULL,
        amount DECIMAL(9,2) NOT NULL,
        KEY (brand))
     SELECT
            item, 0 brand,
            SUM(-1 * allocated) units,
            SUM(-1 * allocated * sale_price(txn_line.retail_price,
                                            txn_line.discount_type,
                                            txn_line.discount)) amount
       FROM txn
       LEFT JOIN txn_line ON txn.id = txn_line.txn
      WHERE type = 'customer'
        AND ($sql_criteria)
        AND filled BETWEEN '$begin' AND '$end' + INTERVAL 1 DAY
        AND item IS NOT NULL
      GROUP BY 1";

$db->query($q) or die('Line : ' . __LINE__ . $db->error);

$q= "UPDATE current
        SET brand = IFNULL((SELECT brand FROM item
                             WHERE item.id = current.item),
                           0)";

$db->query($q) or die('Line : ' . __LINE__ . $db->error);

/* Previous */
$q= "CREATE TEMPORARY TABLE previous
       (item INT UNSIGNED PRIMARY KEY,
        brand INT UNSIGNED NOT NULL,
        units INT NOT NULL,
        amount DECIMAL(9,2) NOT NULL,
        KEY (brand))
     SELECT
            item, 0 brand,
            SUM(-1 * allocated) units,
            SUM(-1 * allocated * sale_price(txn_line.retail_price,
                                            txn_line.discount_type,
                                            txn_line.discount)) amount
       FROM txn
       LEFT JOIN txn_line ON txn.id = txn_line.txn
      WHERE type = 'customer'
        AND ($sql_criteria)
        AND filled BETWEEN '$begin' - INTERVAL 1 YEAR
                       AND '$end' + INTERVAL 1 DAY - INTERVAL 1 YEAR
        AND item IS NOT NULL
      GROUP BY 1";

$db->query($q) or die('Line : ' . __LINE__ . $db->error);

$q= "UPDATE previous
        SET brand = IFNULL((SELECT brand FROM item
                             WHERE item.id = previous.item),
                           0)";

$db->query($q) or die('Line : ' . __LINE__ . $db->error);

/* Report */
$q= "SELECT
            name, slug, 0,
            (SELECT SUM(amount) FROM current WHERE brand = id)
              AS current_amount,
            (SELECT SUM(amount) FROM previous WHERE brand = id)
              AS previous_amount
       FROM brand 
      ORDER BY name";

$r= $db->query($q) or die($db->error);

$cat= "";
$parent= 0;
?>
<table class="table table-striped sortable">
 <thead>
  <tr>
   <th>Category</th>
   <th align="right">Current</th>
   <th align="right">Previous</th>
   <th align="right">Change</th>
 </thead>
 <tbody>
<?
while ($row= $r->fetch_assoc()) {
  if ($row['parent'] && !$row['previous_amount'] && !$row['current_amount']) {
    continue;
  }

  if ($row['previous_amount'] == 0) {
    $change = 0;
  } else {
    $change= (($row['current_amount'] - $row['previous_amount']) / $row['previous_amount']) * 100;
  }
?>
  <tr class="XXX<?=($change < 0) ? 'danger' : ($change > 100) ? 'success' : ''?>">
   <td><?=$row['parent'] ? ' &nbsp; ' . ashtml($row['name']) : '<b> ' . ashtml($row['name']) . '</b>' ?></td>
   <td align="right"><?=amount($row['current_amount'])?></td>
   <td align="right"><?=amount($row['previous_amount'])?></td>
   <td align="right"><?=sprintf("%.1f%%", $change)?></td>
  </tr>
<?}?>
 </tbody>
</table>

<?
foot();
?>
<script>
$(function() {
  $('#report-params .input-daterange').datepicker({
      format: "yyyy-mm-dd",
      todayHighlight: true
  });
});
</script>

