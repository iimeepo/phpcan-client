<?php

/**
 * ===============================================
 * PHPCAN微服务框架 - fpm版本
 * ===============================================
 * 版本：PHP7.0 +
 * 作者: suruibuas / 317953536@qq.com
 * 日期: 2018/3/23 13:09
 * 官网：http://www.phpcan.cn
 * ===============================================
 * 客户端SDK
 * ===============================================
 */

namespace phpcan;
use Ares333\Curl\Toolkit;

class Client{

    // 句柄
    private $_client;
    // 请求头
    private $_header = [];
    // 最大超时时间
    private $_timeOut;
    // 配置信息
    private $_conf;
    // 并发请求地址
    private $_multi;
    // 请求地址
    private $_url;

    /**
     * Client constructor.
     */
    public function __construct()
    {
        $this->_conf = [];
        // 判断是不是在PHPCAN框架下并开启了SOA支持
        if (defined('_SOA') && _SOA)
        {
            $conf = conf();
            $this->_conf['GATEWAY']     = _GATEWAY;
            $this->_conf['ENVIRONMENT'] = $conf['ENVIRONMENT'];
            $this->_conf['CLIENT']      = $conf['CLIENT'];
            $this->_conf['UNAME']       = $conf['SOAUNAME'];
            $this->_conf['PWORD']       = $conf['SOAPWORD'];
            $this->_conf['TIMEOUT']     = $conf['HTTP_TIMEOUT'];
            $this->_conf['COMPOSER']    = $conf['COMPOSER'];
        }
        else
        {
            $this->_conf = require 'Conf.php';
        }
        if ( ! isset($this->_conf['GATEWAY']) || ! $this->_conf['GATEWAY'])
        {
            $this->_error([
                'code' => 500,
                'msg'  => '网关地址未配置'
            ]);
        }
        if ( ! isset($this->_conf['ENVIRONMENT']) || ! in_array($this->_conf['ENVIRONMENT'], [0, 1]))
        {
            $this->_error([
                'code' => 500,
                'msg'  => '项目所在环境配置不正确，"0" 测试环境 "1" 生产环境'
            ]);
        }
        if ( ! isset($this->_conf['CLIENT']))
        {
            $this->_error([
                'code' => 500,
                'msg'  => '调用端未配置或配置不正确'
            ]);
        }
        if ( ! isset($this->_conf['UNAME']) || ! $this->_conf['UNAME'])
        {
            $this->_error([
                'code' => 500,
                'msg'  => '开发者账号未配置'
            ]);
        }
        if ( ! isset($this->_conf['PWORD']) || ! $this->_conf['PWORD'])
        {
            $this->_error([
                'code' => 500,
                'msg'  => '开发者密码未配置'
            ]);
        }
        $this->_client = new Toolkit();
        $this->_client = $this->_client->getCurl();
        $this->_client->onInfo = null;
        // 设置最大并发数
        $this->_client->maxThread = 10;
        // 初始化相关信息
        $this->_header  = [];
        $this->_timeOut = $this->_conf['TIMEOUT'];
    }

    /**
     * 描述：发送GET请求
     * @param string $url
     * @param array $params
     */
    public function get($url = '', $params = [], $cache = FALSE)
    {
        if ($url == '')
        {
            $this->_error([
                'code' => 500,
                'msg'  => '请求的服务地址不能为空'
            ]);
        }
        $this->_url = $url;
        // 组装URI
        $url = $this->_baseUrl($url, $params);
        // 组装HEADER头信息
        $this->_createHeader();
        // 初始化结果
        $response = [];
        // 允许网关缓存
        $cache = (is_bool($params)) ? $params : $cache;
        if ($cache)
            $this->_header[] = 'SOACACHE:1';
        // 发送请求
        $this->_client->add([
            'opt' => [
                CURLOPT_URL => $url,
                CURLOPT_TIMEOUT => $this->_timeOut,
                CURLOPT_HTTPHEADER => $this->_header,
                CURLOPT_FOLLOWLOCATION => FALSE,
                CURLOPT_CUSTOMREQUEST => 'GET'
            ]
        ],
        function($result) use (&$response){
            $response = $this->_response($result['body']);
        },
        function($result) use (&$response){
            // 错误
            $response = $this->_error($result);
        })->start();
        $this->_header = [];
        // 返回数据
        return $response;
    }

    /**
     * 描述：发送POST请求
     * @param string $url
     * @param array $data
     * @param array $params
     * @return array
     */
    public function post($url = '', $data = [], $params = [])
    {
        if ($url == '')
        {
            $this->_error([
                'code' => 500,
                'msg'  => '请求的服务地址不能为空'
            ]);
        }
        $this->_url = $url;
        // 组装URI
        $url = $this->_baseUrl($url, $params);
        // 组装HEADER头信息
        $this->_createHeader();
        // 初始化结果
        $response = [];
        // 发送请求
        $this->_client->add([
            'opt' => [
                CURLOPT_URL => $url,
                CURLOPT_TIMEOUT => $this->_timeOut,
                CURLOPT_HTTPHEADER => $this->_header,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_FOLLOWLOCATION => FALSE,
                CURLOPT_POST => TRUE,
                CURLOPT_POSTFIELDS => http_build_query($data)
            ]
        ],
        function($result) use (&$response){
            $response = $this->_response($result['body']);
        },
        function($result) use (&$response){
            // 错误
            $response = $this->_error($result);
        })->start();
        $this->_header = [];
        // 返回数据
        return $response;
    }

    /**
     * 描述：添加并发任务
     * @param string $key
     * @param array $params
     * @return object
     */
    public function add($key = '', $params = [])
    {
        if ( ! isset($params['url']))
        {
            $this->_error([
                'code' => 500,
                'msg'  => '请求的HTTP地址不能为空'
            ]);
        }
        $this->_multi[$key] = [
            'url'     => $params['url'],
            'query'   => (isset($params['query'])) ? $params['query'] : [],
            'post'    => (isset($params['post'])) ? $params['post'] : [],
            'timeout' => (isset($params['timeout'])) ? $params['timeout'] : $this->_timeOut,
            'header'  => (isset($params['header'])) ? $params['header'] : []
        ];
        return $this;
    }

    /**
     * 描述：执行并发任务
     */
    public function run()
    {
        if (empty($this->_multi))
        {
            $this->_error([
                'code' => 500,
                'msg'  => '并发任务不能为空'
            ]);
        }
        if (count($this->_multi) == 1)
        {
            $this->_error([
                'code' => 500,
                'msg'  => '当前只有一个任务，没有必要使用并发执行，请使用对应的GET或者POST方法执行单个请求'
            ]);
        }
        $response = [];
        foreach ($this->_multi as $key => $row)
        {
            $method = (isset($row['post']) && ! empty($row['post'])) ? 'POST' : 'GET';
            $opt = [];
            $opt['opt'] = [
                CURLOPT_FOLLOWLOCATION => FALSE,
                CURLOPT_URL => $this->_baseUrl($row['url'], $row['query']),
                CURLOPT_TIMEOUT => $row['timeout'],
                CURLOPT_HTTPHEADER => $this->_createHeader($row['header']),
                CURLOPT_CUSTOMREQUEST => $method
            ];
            if ($method == 'POST')
            {
                $opt['opt'][CURLOPT_POST] = TRUE;
                $opt['opt'][CURLOPT_POSTFIELDS] = http_build_query($row['post']);
            }
            $this->_client->add($opt,
                function($result) use (&$response, $key){
                    $response[$key] = $this->_response($result['body']);
                },
                function($result){
                    $this->_error($result);
                });
        }
        $this->_client->start();
        $this->_multi = [];
        return $response;
    }

    /**
     * 描述：设置请求超时时间
     * @param int $timeOut
     */
    public function timeout($timeOut)
    {
        $this->_timeOut = $timeOut;
        return $this;
    }

    /**
     * 描述：添加HEADER请求头信息
     * @param $header
     */
    public function header($header = [])
    {
        if (empty($header))
        {
            return TRUE;
        }
        foreach ($header as $key => $val)
            $this->_header[] = strtoupper($key).':'.$val;
        return $this;
    }

    /**
     * 描述：创建请求头信息
     * @param $header
     */
    private function _createHeader($headers = FALSE)
    {
        // 拆分获取命名空间
        $arr = explode('/', $this->_url);
        $namespace = $arr[1];
        // 判断是否已经配置服务编排
        if ( ! isset($this->_conf['COMPOSER'][$namespace]))
        {
            $this->_error([
                'code' => 500,
                'msg'  => '没有发现服务'.$namespace.'的编排信息'
            ]);
        }
        $composer = $this->_conf['COMPOSER'][$namespace];
        if ( ! isset($composer['APPID']))
        {
            $this->_error([
                'code' => 500,
                'msg'  => 'APPID未配置'
            ]);
        }
        if ( ! isset($composer['SECRET']))
        {
            $this->_error([
                'code' => 500,
                'msg'  => 'SECRET未配置'
            ]);
        }
        $nonce    = rand(100000, 999999);
        $curtime  = date('YmdHis');
        $header   = [];
        $header[] = 'CLIENT:'.strtoupper($this->_conf['CLIENT']);
        $header[] = 'APPID:'.$composer['APPID'];
        $header[] = 'NONCE:'.$nonce;
        $header[] = 'CURTIME:'.$curtime;
        $header[] = 'OPENKEY:'.md5($composer['APPID'].$nonce.$curtime.$composer['SECRET']);
        $header[] = 'ENV:'.$this->_conf['ENVIRONMENT'];
        $header[] = 'SOAUNAME:'.$this->_conf['UNAME'];
        $header[] = 'SOAPWORD:'.$this->_conf['PWORD'];
        if (isset($composer['VERSION']) && $composer['VERSION'] != '')
        {
            $header[] = 'VERSION:'.$composer['VERSION'];
        }
        if (isset($_SERVER['HTTP_TRACE']))
        {
            $header[] = 'TRACE:'.$_SERVER['HTTP_TRACE'];
        }
        if (isset($_SERVER['HTTP_PARENT']))
        {
            $header[] = 'PARENT:'.$_SERVER['HTTP_PARENT'];
        }
        // 并发的HEADER头自定义传递
        if ($headers !== FALSE)
        {
            return array_merge($header, $headers);
        }
        else
        {
            if (empty($this->_header))
            {
                $this->_header = $header;
            }
            else
            {
                $this->_header = array_merge($this->_header, $header);
            }
        }
    }

    /**
     * 描述：输出结果
     * @param $response
     * @return string
     */
    private function _response($response)
    {
        $data = json_decode($response, TRUE);
        if (JSON_ERROR_NONE !== json_last_error())
        {
            $data = [];
            $data['code'] = 100;
            $data['msg']  = '不是标准的JSON数据，已原样输出';
            $data['data'] = $response;
        }
        return $data;
    }

    /**
     * 描述：返回错误信息
     * @param array $return
     */
    private function _error($return = [])
    {
        exit(json_encode($return, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 描述：处理URL
     * @param string $url
     * @param array $params
     */
    private function _baseUrl($url = '', $query = [])
    {
        $serviceUrl  = rtrim($this->_conf['GATEWAY'],'/');
        $serviceUrl .= (preg_match('#^/.*#', $url)) ? $url : '/'.$url;
        // 解析参数
        if ( ! is_array($query) || empty($query))
        {
            return $serviceUrl;
        }
        $doc = (strpos($serviceUrl, '?') !== FALSE) ? '&' : '?';
        foreach($query as $key => $val)
        {
            $serviceUrl .= $doc.$key.'='.$val;
            $doc = '&';
        }
        return $serviceUrl;
    }

}