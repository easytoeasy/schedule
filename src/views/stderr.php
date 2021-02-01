<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:meld="https://github.com/Supervisor/supervisor">

<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title id="title">Guarder Status</title>
</head>

<body>

	<div id="content">

		<?php
		$program = isset($_GET['program']) ? $_GET['program'] : '';
		if (!isset($this->taskers[$program])) {
			echo 'program undefined';
			return;
		}
		$c = $this->taskers[$program];
		$logfile = $c->logfile;
		if (empty($logfile) || !is_file($logfile)) {
			echo 'has no such file or directory: ' . $logfile;
			return;
		}
		$size = isset($_GET['size']) && $_GET['size'] > 0 ? intval($_GET['size']) : 100;
		echo '<pre>';
		echo `tail -{$size}  $logfile`;
		echo '</pre>';
		?>

	</div>

</body>

</html>