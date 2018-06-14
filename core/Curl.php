<?php
    require_once 'Scheduler.php';
    require_once 'SysCall.php';

    class CurlResult
    {
        private $val;

        public function __construct($url, $rs){
            $this->val = [$url => $rs];
        }

        public function getValue(){
            return $this->val;
        }
    }

    class CurlException
    {
        private $val;

        public function __construct($url, $info){
            $this->val = [$url => $info];
        }

        public function getValue(){
            return $this->val;
        }
    }

    class Curl
    {
        private $urls = [];

        /**
         * @param $urls [
         *      {
         *          "url": "",  //请求地址, 必填
         *          "post": 0,      //post: 1, get: 0, 可选
         *          "data": [],     //请求的参数,数组, 可选
         *          "timeout": 200,  //超时时间,单位ms, 可选
         *          "key": ''   //返回值里的key,如果没填则用url替代
         *      }
         * ]
         */
        public function add($urls){
            foreach($urls as $url){
                if(empty($url['url'])){
                    exit('url不能为空');
                }else{
                    $this->urls[] = [
                        'url' => $url['url'],
                        'post' => $url['post'] ?? 0,
                        'data' => $url['data'] ?? [],
                        'timeout' => $url['timeout'] ?? null,
                        'key' => $url['key'] ?? $url['url']
                    ];
                }
            }
        }

        public function multi_exec($timeout = null){
            if(empty($this->urls)){
                exit('urls不能为空,请先调用add方法添加');
            }

            $scheduler = new Scheduler;
            foreach($this->urls as $url){
                $scheduler->newTask($this->exec($url['url'], $url['post'], $url['data'], is_null($timeout) ? $url['timeout'] : $timeout, $url['key']));
            }
            $ret = $scheduler->run();

            return $ret;
        }

        private function exec($url, $post = 0, $data = [], $timeout = null, $key = ''){
            try{
                $urlArr = parse_url($url);

                //创建连接
                switch($urlArr['scheme']){
                    case 'http':
                        $fp = @stream_socket_client(sprintf('tcp://%s:%s', gethostbyname($urlArr['host']), 80), $errno, $errstr, 30);
                        break;
                    case 'https':
                        $context = stream_context_create([
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false
                            ]
                        ]);
                        $fp = @stream_socket_client(sprintf('ssl://%s:%s', gethostbyname($urlArr['host']), 443), $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
                        break;
                    default:
                        throw new exception('only http and https protocols are supported');
                }
                if(!$fp){
                    throw new exception('create socket failed: ' . $errstr);
                }
                //设置读写超时时间，但是没有生效
                if(!is_null($timeout)){
                    stream_set_blocking($fp, false);
                    stream_set_timeout($fp, 0, $timeout);
                }

                //查询
                empty($urlArr['query']) || parse_str($urlArr['query'], $query);
                $query = http_build_query(array_merge($query ?? [], $data));

                //请求数据包
                $request = sprintf("%s %s HTTP/1.1\r\n", $post ? 'POST' : 'GET', $urlArr['path'] . (!$post && !empty($query) ? '?' . $query : ''));
                $request .= "Connection: Close\r\n";
                $request .= sprintf("Host: %s\r\n", $urlArr['host']);
                $request .= "User-Agent: Mozilla\r\n";
                $post && $request .= "Content-type: application/x-www-form-urlencoded\r\n";
                $post && $request .= sprintf("Content-length: %s\r\n", strlen($query));
                $request .= "\r\n";
                $post && !empty($query) && $request .= sprintf("%s\r\n", $query);

                fwrite($fp, $request);

                //将连接添加到读监听池中，并让出cpu
                yield $this->addToReadPoll($fp);

                //处理响应
                $ret = '';
                while(!feof($fp)){
                    $ret .= fgets($fp, 8192);
                }

                @list($repHeader, $repBody) = explode("\r\n\r\n", $ret);
                if(strpos($repHeader, 'chunked')){  //用块传输的时候，会在body前面加上内容长度同时在最后加上0代表结束
                    $body = explode("\r\n", $repBody);
                    array_shift($body);
                    array_pop($body);
                    $repBody = implode("\r\n", $body);
                }

                fclose($fp);

                yield new CurlResult($key, $repBody);

            }catch(Exception $e){
                yield new CurlException($key, $e->getMessage());
            }
        }

        private function addToReadPoll($fp){
            return new SysCall(function(Task $task, Scheduler $scheduler)use($fp){
                $scheduler->addToReadPoll($fp, $task);
            });
        }
    }

