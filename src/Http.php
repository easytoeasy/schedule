<?php

namespace pzr\schedule;

class Http
{
    public $basePath = __DIR__ . '/views';
    public $max_age = 120; //秒

    /*
    *  函数:     parse_http
    *  描述:     解析http协议
    */
    public function parse_http($http)
    {
        // 初始化
        // $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION = $_FILES =  array();
        // $GLOBALS['HTTP_RAW_POST_DATA'] = '';
        $_GET = $_SERVER = [];
        // 需要设置的变量名
        $_SERVER = array(
            'QUERY_STRING' => '',
            'REQUEST_METHOD' => '',
            'REQUEST_URI' => '',
            'SERVER_PROTOCOL' => '',
            'SERVER_SOFTWARE' => '',
            'SERVER_NAME' => '',
            'HTTP_HOST' => '',
            'HTTP_USER_AGENT' => '',
            'HTTP_ACCEPT' => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
            'HTTP_ACCEPT_ENCODING' => '',
            'HTTP_COOKIE' => '',
            'HTTP_CONNECTION' => '',
            'REMOTE_ADDR' => '',
            'REMOTE_PORT' => '0',
            'SCRIPT_NAME' => '',
            'HTTP_REFERER' => '',
            'CONTENT_TYPE' => '',
            'HTTP_IF_NONE_MATCH' => '',
        );

        // 将header分割成数组
        list($http_header, $http_body) = explode("\r\n\r\n", $http, 2);
        $header_data = explode("\r\n", $http_header);

        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ', $header_data[0]);
        $suffix = '';
        if (strpos($_SERVER['REQUEST_URI'], '.') !== false)
            list($prefix, $suffix) = explode('.', $_SERVER['REQUEST_URI']);
        switch ($suffix) {
            case 'css':
                $_SERVER['CONTENT_TYPE'] = 'text/css';
                break;
            case 'gif':
                $_SERVER['CONTENT_TYPE'] = 'image/gif';
                break;
            case 'png':
                $_SERVER['CONTENT_TYPE'] = 'image/png';
                break;
            default:
                break;
        }

        unset($header_data[0]);
        foreach ($header_data as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            list($key, $value) = explode(':', $content, 2);
            $key = strtolower($key);
            $value = trim($value);
            switch ($key) {
                case 'host':
                    $_SERVER['HTTP_HOST'] = $value;
                    $tmp = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                case 'cookie':
                    $_SERVER['HTTP_COOKIE'] = $value;
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
                case 'user-agent':
                    $_SERVER['HTTP_USER_AGENT'] = $value;
                    break;
                case 'accept':
                    $_SERVER['HTTP_ACCEPT'] = $value;
                    break;
                case 'accept-language':
                    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $value;
                    break;
                case 'accept-encoding':
                    $_SERVER['HTTP_ACCEPT_ENCODING'] = $value;
                    break;
                case 'connection':
                    $_SERVER['HTTP_CONNECTION'] = $value;
                    break;
                case 'referer':
                    $_SERVER['HTTP_REFERER'] = $value;
                    break;
                case 'if-modified-since':
                    $_SERVER['HTTP_IF_MODIFIED_SINCE'] = $value;
                    break;
                case 'if-none-match':
                    $_SERVER['HTTP_IF_NONE_MATCH'] = $value;
                    break;
                case 'content-type':
                    if (!preg_match('/boundary="?(\S+)"?/', $value, $match)) {
                        $_SERVER['CONTENT_TYPE'] = $value;
                    } else {
                        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
                        $http_post_boundary = '--' . $match[1];
                    }
                    break;
            }
        }

        // script_name
        $_SERVER['SCRIPT_NAME'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if ($_SERVER['QUERY_STRING']) {
            // $GET
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        } else {
            $_SERVER['QUERY_STRING'] = '';
        }
        // REQUEST
        // $_REQUEST = array_merge($_GET, $_POST);
        // return array('get' => $_GET, 'post' => $_POST, 'cookie' => $_COOKIE, 'server' => $_SERVER, 'files' => $_FILES);
    }

    public function status_404()
    {
        return <<<EOF
HTTP/1.1 404 OK
content-type: text/html

EOF;
    }

    public function status_301($location)
    {
        return <<<EOF
HTTP/1.1 301 Moved Permanently
Content-Length: 0
Content-Type: text/plain
Location: $location
Cache-Control: no-cache

EOF;
    }

    public function status_304()
    {
        return <<<EOF
HTTP/1.1 304 Not Modified
Content-Length: 0

EOF;
    }

    public function status_200($response)
    {
        $contentType = $_SERVER['CONTENT_TYPE'];
        $length = strlen($response);
        $header = $contentType ? 'Cache-Control: max-age=180' : '';
        // $etag = md5($response);
        // ETag: $etag
        return <<<EOF
HTTP/1.1 200 OK
Content-Type: $contentType
Content-Length: $length
$header

$response
EOF;

    }
}
