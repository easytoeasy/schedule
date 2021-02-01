<?php

namespace pzr\schedule;

use Closure;
use ErrorException;

class Stream
{
	protected $socket;
	protected $timeout = 2; //s
	protected $client;

	const TCP = 'tcp';
	const UDP = 'udp';

	public function __construct($host, $port)
	{
		$host = sprintf("%s://%s:%s", self::TCP, $host, $port);
		$this->socket = $this->connect($host);
	}

	public function connect($host)
	{
		$context_option = [
			'socket' => ['backlog' => 10240,], //等待处理连接的队列
		];
		$context = stream_context_create($context_option);
		stream_context_set_option($context, 'socket', 'so_reuseaddr', 1);
		stream_context_set_option($context, 'socket', 'so_reuseport', 1);
		$socket = stream_socket_server($host, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
		// $socket = stream_socket_server($host, $errno, $errstr);
		if (!$socket) {
			throw new ErrorException(sprintf("stream bind error, code:%s, errstr:%s", $errno, $errstr));
		}
		stream_set_timeout($socket, $this->timeout);
		stream_set_chunk_size($socket, 1024);
		stream_set_blocking($socket, false);
		$this->client = [$socket];
		return $socket;
	}

	public function accept($sec, Closure $callback)
	{
		// 时间校准
		$read = $this->client;
		if (@stream_select($read, $write, $except, $sec) < 1) return;
		if (in_array($this->socket, $read)) {
			$cs = stream_socket_accept($this->socket);
			$this->client[] = $cs;
		}
		foreach ($read as $s) {
			if ($s == $this->socket) continue;
			$header = fread($s, 1024);
			if (empty($header)) {
				$index = array_search($s, $this->client);
				if ($index)
					unset($this->client[$index]);
				$this->endConn($s);
				continue;
			}
			Http::parse_http($header);
			$md5 = isset($_GET['md5']) ? $_GET['md5'] : '';
			$action = isset($_GET['action']) ? $_GET['action'] : '';
			$response = $callback($md5, $action);
			$this->write($s, $response);
			$index = array_search($s, $this->client);
			if ($index)
				unset($this->client[$index]);
			$this->endConn($s);
		}
	}

	public function write($socket, $response)
	{
		$ret = fwrite($socket, $response, strlen($response));
	}

	public function endConn($cliSock)
	{
		$flag = fclose($cliSock);
		return $flag;
	}

	public function close()
	{
		return $this->endConn($this->socket);
	}

	



	/**
	 * Get the value of socket
	 */
	public function getSocket()
	{
		return $this->socket;
	}
}
