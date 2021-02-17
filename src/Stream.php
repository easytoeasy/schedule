<?php

namespace pzr\schedule;

use Closure;
use ErrorException;

class Stream
{
	protected $socket;
	protected $timeout = 2; //s
	protected $client;
	// protected $logger;
	protected $http;

	const TCP = 'tcp';
	const UDP = 'udp';

	public function __construct($host, $port)
	{
		//$this->logger = Helper::getLogger('Stream', '/var/log/schedule99.log');
		//$this->logger->debug('stream start:' . memory_get_usage());
		$host = sprintf("%s://%s:%s", self::TCP, $host, $port);
		$this->socket = $this->connect($host);
		$this->http = new Http();
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
		if (!$socket) {
			throw new ErrorException(sprintf("stream bind error, code:%s, errstr:%s", $errno, $errstr));
		}
		stream_set_timeout($socket, $this->timeout);
		// stream_set_chunk_size($socket, 1024);
		stream_set_blocking($socket, false);
		$this->client = [$socket];
		return $socket;
	}

	public function accept($sec, Closure $callback)
	{
		$read = $this->client;
		$write = $except = [];
		if (@stream_select($read, $write, $except, $sec) < 1) return;
		//$this->logger->info('before accept:' . memory_get_usage());
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
			$this->http->parse_http($header);
			$md5 = isset($_GET['md5']) ? $_GET['md5'] : '';
			$action = isset($_GET['action']) ? $_GET['action'] : '';
			$response = $callback($md5, $action);
			$this->write($s, $response);
			$index = array_search($s, $this->client);
			if ($index)
				unset($this->client[$index]);
			$this->endConn($s);
		}
		unset($response, $_GET, $_SERVER, $header);
		//$this->logger->debug('after accept:' . memory_get_usage());
	}

	public function write($socket, $response)
	{
		return fwrite($socket, $response, strlen($response));
	}

	public function endConn($cliSock)
	{
		return fclose($cliSock);
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

	/**
	 * Get the value of client
	 */ 
	public function getClient()
	{
		return $this->client;
	}

	public function __destruct()
	{
		$this->close();
	}
}
