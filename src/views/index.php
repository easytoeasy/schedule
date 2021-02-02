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
      <div class="status_msg">serverId:<?= $this->serverId ?>, outofMin: <?=$this->outofMin?></div>
      <div class="status_msg"><?= $this->message ?></div>

      <ul class="clr" id="buttons">
        <li class="action-button"><a href="tail.php" target="_blank">logfile</a></li>
        <li class="action-button"><a href="index.html?action=clear">clear</a></li>
        <li class="action-button"><a href="index.html?action=flush">flush cache</a></li>
        <li class="action-button">
          <select id='tagid' onchange="tagChange()">
            <option value="0">全部</option>
            <?php
            $searchTagid = isset($_GET['tagid']) ? $_GET['tagid'] : 0;
            foreach ($this->servTags as $id => $name) {
              $selected = '';
              if ($searchTagid == $id) {
                $selected = 'selected';
              }
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

                                use pzr\schedule\db\Job;
                                use pzr\schedule\State;

          $id = isset($_GET['id']) ? $_GET['id'] : 0;
          $rows = 0;
          if ($this->taskers)
            /** @var Job $c */
            foreach ($this->taskers as $c) {
              $rows++;
              if ($searchTagid && $c->tag_id != $searchTagid) {
                continue;
              }
              if ($id && $c->id != $id) continue;
          ?>
            <tr class="<?= $rows % 2 == 1 ? '' : 'shade' ?>">
              <td class="status"><span class="status<?= State::css($c->state) ?>"><?= State::desc($c->state) ?></span></td>
              <td> <span>pid <?= $c->pid ?>, <?= $c->uptime ?>, refcount: <?= $c->refcount ?></span> </td>
              <td><a>id:<?= $c->id ?> <?= $c->name ?> outofCron:<?=$c->outofCron?></a></td>
              <td class="action">
                <ul>
                  <?php if (in_array($c->state, State::runingState())) { ?>
                    <li>
                      <a href="index.html?md5=<?= $c->md5 ?>&action=stop" name="Stop">Stop</a>
                    </li>
                  <?php } else { ?>
                    <li>
                      <a href="index.html?md5=<?= $c->md5 ?>&action=start" name="Start">Start</a>
                    </li>
                  <?php } ?>
                  <li>
                    <a href="stderr.php?md5=<?= $c->md5 ?>" name="Tail -f Stderr" target="_blank">Tail -f Stderr</a>
                  </li>
                  <li>
                    <a href="index.html?md5=<?= $c->md5 ?>&action=clear" name="Clear Stderr">Clear Stderr</a>
                  </li>
                </ul>
              </td>
            </tr>
            <tr>
              <td colspan="4">
                <font style="color: gray;margin-left:77px;"><?= $c->command ?></font>
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