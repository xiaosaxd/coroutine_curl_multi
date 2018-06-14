<?php
    include 'Curl.php';

    function yield_curl_multi($urls){
        $curl = new Curl();
        $curl->add($urls);

        return $curl->multi_exec();
    }

    function curl_multi($urls){
        $mh = curl_multi_init();

        $chs = [];
        foreach($urls as $url){
            $ch = curl_init();
            if(!empty($url['post'])){
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($url['data']));
            }
            if(strpos($url['url'], 'https') === 0){
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
            }
            curl_setopt($ch, CURLOPT_URL, $url['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla']);
            curl_multi_add_handle($mh, $ch);
            $chs[$url['url']] = $ch;
        }

        do{
            $mrc = curl_multi_exec($mh, $active);
        }while($active == CURLM_CALL_MULTI_PERFORM);

        while($active && $mrc == CURLM_OK){
            if(curl_multi_select($mh) != -1){
                do{
                    $mrc = curl_multi_exec($mh, $active);
                }while($active == CURLM_CALL_MULTI_PERFORM);
            }
        }

        $ret = [];
        foreach($chs as $url => $ch){
            $ret[$url] = curl_multi_getcontent($ch);
        }

        return $ret;
    }






    $urls = [
        //get http
        [
            'url' => 'http://ip.taobao.com/service/getIpInfo.php?ip=127.0.0.1'
        ],
        //get https
        [
            'url' => 'https://sp0.baidu.com/5a1Fazu8AA54nxGko9WTAnF6hhy/su?wd=yield',
            'key' => 'yield'
        ],
        //post http
        [
            'url' => 'http://www.w3school.com.cn/ajax/demo_post2.asp',
            'post' => 1,
            'data' => ['fname' => 'xd', 'lname' => 'lee']
        ],
        //post https
        [
            'url' => 'https://www.chhblog.com/add_article_comment',
            'post' => 1,
            'data' => ['article_id' => 411, 'content' => 'test', 'email' => 'aaa@baidu.com', 'nick' => 'xx'],
            'key' => 'post_https'
        ]
    ];

    $start = microtime(true);

    $ret = yield_curl_multi($urls);
//    $ret = curl_multi($urls);

    $end = microtime(true);
    printf("cost time: %sms, the ret is:\r\n", round(($end-$start)*1000, 2));
    print_r($ret);

    exit();
