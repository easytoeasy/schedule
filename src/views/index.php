<!DOCTYPE html>
<html lang="en">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title>cron定时任务进程管理</title>
  <link href="css/supervisor.css" rel="stylesheet" type="text/css">
  <link href="images/icon.png" rel="icon" type="image/png">
</head>


<body>
  <div id="wrapper">

    <div id="header">
      <img alt="Supervisor status" src="images/supervisor.gif">
    </div>

    <div>
      <div class="status_msg"><?= $this->message ?></div>

      <ul class="clr" id="buttons">
        <li class="action-button"><a href="tail.php" target="_blank">logfile</a></li>
        <li class="action-button"><a href="index.html?action=clear">clear</a></li>
        <li class="action-button">
          <select id='tagid' onchange="tagChange()">
            <option value="0">全部</option>
            <?php
            $searchTagid = isset($_GET['tagid']) ? $_GET['tagid'] : 0;
            foreach ($this->servTags as $id => $name) {
              $selected = '';
              if ($searchTagid == $id) {$selected = 'selected';}
              echo sprintf("<option %s value=%s>%s</option>", $selected, $id, $name);
            }
            ?>
          </select>
        </li>
      </ul>

      <table cellspacing="0">
        <thead>
          <tr>
            <th class="state">State</th>
            <th class="desc">Description</th>
            <th class="name">Name</th>
            <th class="action">Action</th>
          </tr>
        </thead>

        <tbody>
          <?php

          use pzr\schedule\State;

          
          if ($this->taskers)
            foreach ($this->taskers as $c) {
              if ($searchTagid && $c->tag_id != $searchTagid) {
                continue;
              }
          ?>
            <tr class="">
              <td class="status"><span class="status<?= State::css($c->state) ?>"><?= State::desc($c->state) ?></span></td>
              <td><span>pid <?= $c->pid ?>, <?= $c->uptime ?>, <?= $c->refcount ?></span></td>
              <td><a>id:<?= $c->id ?> <?= $c->name ?> <?= $c->tag_id ?></a></td>
              <td class="action">
                <ul>
                  <li>
                    <a href="stderr.php?md5=<?= $c->md5 ?>" name="Tail -f Stderr" target="_blank">Tail -f Stderr</a>
                  </li>
                  <li>
                    <a href="index.html?md5=<?= $c->md5 ?>&action=clear" name="Clear Stderr">Clear Stderr</a>
                  </li>
                </ul>
              </td>
            </tr>
          <?php  } ?>
        </tbody>
      </table>

    </div>
</body>
<script>
  function tagChange() {
    var obj = document.getElementById('tagid'); //定位id
    var index = obj.selectedIndex; // 选中索引
    window.location.href = 'index.php?tagid=' + index;
  }
</script>

</html>