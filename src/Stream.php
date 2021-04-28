<?php

namespace pzr\schedule;

use Closure;

class Stream
{
	protected $fd;
	protected $client;

	public function __construct()
	{
		if (($fd = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
			$this->errstr($fd);
		}

		socket_set_option($fd, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_set_option($fd, SOL_SOCKET, SO_REUSEPORT, 1);
		socket_set_option($fd, SOL_SOCKET, SO_KEEPALIVE, 1);
		if (socket_set_nonblock($fd) === false) {
			$this->errstr($fd);
		}

		if (socket_bind($fd, HOST, PORT) === false) {
			$this->errstr($fd);
		}

		if (socket_listen($fd, BACKLOG) === false) {
			$this->errstr($fd);
		}
		$this->fd = $fd;
		$this->client = [$fd];
	}

	public function accept($sec, Closure $callback)
	{
		$read = $this->client;
		$write = $except = [];
		if (socket_select($read, $write, $except, $sec) < 1) return;
		if (in_array($this->fd, $read)) {
			$cfd = socket_accept($this->fd);
			$this->client[] = $cfd;
		}
		foreach ($read as $fd) {
			if ($fd == $this->fd) continue;
			$header = socket_read($fd, 4096);
			if (empty($header)) {
				$index = array_search($fd, $this->client);
				socket_close($fd);
				unset($this->client[$index]);
				continue;
			}
			Http::parse_http($header);
			$response = $callback();
			socket_write($fd, $response);
			// $index = array_search($fd, $this->client);
			// socket_close($fd);
			// unset($this->client[$index]);
		}
		unset($response, $_GET, $_SERVER, $header);
	}

	private function errstr($fd)
	{
		Logger::error(socket_strerror(socket_last_error($fd)));
		exit(4);
	}

	public function __destruct()
	{
		$this->fd && socket_close($this->fd);
	}
}
