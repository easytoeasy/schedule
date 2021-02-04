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
      <div class="status_msg">
        <?= $this->message ?>
        <span style="float:right"> <font style="color: gray;">
          serverId:<?= $this->serverId ?>, ppid:<?= getmypid() ?>, createAt:<?= $this->createAt ?>, outofMin: <?= $this->outofMin ?>, beDelIds:<?= count($this->beDelIds) ?>, childPids:<?= count($this->childPids) ?>
        </font></span>
      </div>

      <ul class="clr" id="buttons">
        <li class="action-button"><a href="tail.php" target="_blank">tail stderr</a></li>
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
        <li class="action-button"><a href="index.html?action=clear">clear log</a></li>
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

          $id = isset($_GET['id']) ? $_GET['id'] : 0;
          if ($this->taskers)
            /** @var Job $c */
            foreach ($this->taskers as $c) {
              if ($searchTagid && $c->tag_id != $searchTagid) {
                continue;
              }
              if ($id && $c->id != $id) continue;
          ?>
            <tr class="shade">
              <td class="status"><span class="status<?= State::css($c->state) ?>"><?= State::desc($c->state) ?></span></td>
              <td>
                <span>pid <?= $c->pid ?>, <?= $c->uptime ?>, refcount: <?= $c->refcount ?></span>
              </td>
              <td><?= $c->id ?>, overtime:<?= $c->outofCron ?>, <a href="stderr.php?md5=<?= $c->md5 ?>&type=1" target="_blank"><?= $c->name ?></a> </td>
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
                    <a href="stderr.php?md5=<?= $c->md5 ?>&type=2" name="Tail -f Stderr" target="_blank">Tail -f Stderr</a>
                  </li>
                </ul>
              </td>
            </tr>
            <tr>
              <td colspan="4">
                <font style="color: gray;margin-left:77px;"><?= $c->cron ?> <?= $c->command ?></font>
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
    var index = obj.value; // 选中值
    window.location.href = 'index.php?tagid=' + index;
  }
</script>

</html>