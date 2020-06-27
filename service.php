<?php
require "./config.php";
require "./vendor/autoload.php";

use GeoIp2\Database\Reader;

//启动服务器
function startServer()
{
    //清空Redis
    Co\run(function () {
        $redis = new Swoole\Coroutine\Redis;
        $status = $redis->connect(REDIS_SERVER, REDIS_PORT);
        if ($status) {
            $redis->flushAll("*");
        }
    });

    $serv = new Swoole\Server("0.0.0.0", 179, SWOOLE_PROCESS, SWOOLE_TCP);
    //配置
    $serv->set(array(
        // 粘包？粘就粘了，大不了建立不成功
        'worker_num' => WORKER_NUM, //设置进程
        //    'open_length_check' => true,
        //    'package_max_length' => 1024 * 1024 * 65,//BGP数据包最大为65k
        'package_length_type' => 'N', //see php pack()
        //    'package_length_offset' => 17,
        //    'package_body_offset' => 20,
        'task_worker_num' => TASKER_NUM,
        'enable_coroutine' => true, //启动协程
        'open_tcp_keepalive' => 1, //回复keepalive
        'tcp_keepidle' => 120, //120s没有数据传输就进行检测
        'tcp_keepinterval' => 60, //60s探测一次
        'tcp_keepcount' => 3, //探测的次数，超过3次后还没回包close此连接
        'task_enable_coroutine ' => true, //开启Task协程支持
        // 'heartbeat_check_interval' => 60,//维持Keepalive
        // 'heartbeat_idle_time' => 60,
    ));

    //监听连接进入事件
    $serv->on('Connect', function ($serv, $fd) {
        $clientContent = $serv->getClientInfo($fd);;
        $remoteIp = $clientContent['remote_ip'] ?? "未知";
        echo "[WorkNUM#{$serv->worker_id}] IP: {$remoteIp} \t TCP连接建立.\n";
    });
    //监听数据接收事件
    $serv->on('Receive', function ($serv, $fd, $from_id, $data) {
        $redis = getRedis();
        $clientContent = $serv->getClientInfo($fd);;
        //不要问为啥是这个，暴力测试了一堆测出来的
        $arr = unpack('H*', $data);
        $str = $arr[1];;
        //处理数据包头
        $length = base_convert(substr($str, 32, 4), 16, 10);
        $type = base_convert(substr($str, 36, 2), 16, 10);
        //Keeplive包内容（写死）
        $keepalive = "ffffffffffffffffffffffffffffffff001304";
        $remoteIp = $clientContent['remote_ip'] ?? "未知";

        switch ($type) {
            case 1:
                //处理包内容
                //TODO： 低于BGP4的自动爬开，asn不需要管的，holdtime以后再说吧
                // $version =  base_convert(substr($str,38,2),16,10);
                $asn = base_convert(substr($str, 40, 4), 16, 10);
                // $holdTime = base_convert(substr($str,44,4),16,10);
                // $bgpId = base_convert(substr($str,48,8),16,10);
                $bgpID = routerIDtoStr(substr($str, 48, 8));
                echo "[WorkNUM#{$serv->worker_id}] IP: {$remoteIp} \t 发送OPEN数据包\n";
                //回复Open数据包
                if (MYASN > 65535) { //支持4Byte ASN
                    $myASN = sprintf("%04x", 23456);
                } else {
                    $myASN = sprintf("%04x", MYASN);
                }
                $option = makeOpenOption();
                $optionLen = strlen($option) / 2;

                //获取ASN(支持4byte ASN)
                if ($asn == 23456) {
                    $startASN = stripos($str, "4104", 56);
                    if ($startASN !== false) {
                        $asn = base_convert(substr($str, $startASN + 4, 8), 16, 10);
                    }
                }

                $length = 19 + 9 + $optionLen;
                $sendData = "ffffffffffffffffffffffffffffffff" . sprintf("%04x", $length) . "0104" . $myASN . sprintf("%04x", HOLD_TIME) . routerIDtoHex() . $option;
                //发送OPEN包
                $serv->send($fd, pack('H*', $sendData));
                $serv->send($fd, pack('H*', $keepalive));
                //添加进Peer
                actionPeers('add', $remoteIp, $redis, [
                    'status' => 'OpenConfirm',
                    'address' => $remoteIp,
                    'asn' => $asn,
                    'fd' => $fd,
                ]);
                //30s后检测 定时器，如果成功建立会话则开始发送IP表
                $serv->after(30000, function () use ($serv, $fd, $remoteIp) {
                    $redis =  getRedis();
                    $peer = actionPeers('get', $remoteIp, $redis);
                    if (!empty($peer['status']) && $peer['status'] == "Established") {
                        actionPeers('update', $remoteIp, $redis, [
                            'SendRoute' => 1,
                            'StartTime' => time(),
                        ]);
                        //     //获取要发送的路由
                        $routesGroup = $redis->keys('PrefixGroup-*');
                        //     //制作更新包
                        $chunkArr = array_chunk($routesGroup, 100); //切片
                        foreach ($chunkArr as $chunk) {
                            $updateMessage = "";
                            foreach ($chunk as $group) {
                                $tempGroup = $redis->get($group);
                                $tempGroupKeys = json_decode($tempGroup, true);
                                $tempKey = end($tempGroupKeys);
                                $data = json_decode($redis->get($tempKey), true);
                                //in_array($peer['asn'],$data['info']['as_path']['asn'])
                                if (!empty($data['routes']) && in_array($peer['asn'], $data['as_path']['asn'])) {
                                    // echo "不发送路由";
                                } else {
                                    $updateMessage .= makeAddUpdate($group, $redis);
                                }
                            }
                            //发送更新报文
                            if (!empty($updateMessage)) {
                                $serv->send($fd, pack('H*', $updateMessage));
                                echo "[TaskNUM#{$serv->worker_id}] 定时器After \t 向" . $peer['address'] . "发送路由同步更新  \n";
                            }
                        }
                    }
                });
                break;
            case 2:
                echo "[WorkNUM#{$serv->worker_id}] IP: {$remoteIp} \t 发送UPDATE数据包\n";
                // 过滤Update包（只从配置的上游接受路由更新
                if (!empty(UPSTEAM_IP) && is_array(UPSTEAM_IP)) {
                    if (!in_array('0.0.0.0/0', UPSTEAM_IP)) { // 允许任何上游
                        if (!in_array($remoteIp, UPSTEAM_IP)) {
                            echo "[WorkNUM#{$serv->worker_id}] IP: {$remoteIp} \t 不在白名单内无权限发送UPDATE数据包\n";
                            break;
                        }
                    }
                }
                if (!empty($redis)) { //如果Redis连接成功，那么开始往Redis里面存路由
                    $update = [];
                    $reader = new Reader(MMDB_PATH); //初始化读取MMDB
                    $hexLength = base_convert(substr($str, 32, 4), 16, 10); //获取第一个包的长度
                    $startStr = $hexLength * 2; //开始计算第二个包

                    //处理第一个数据包
                    $update[] = processingPathAttribute(substr($str, 0, $startStr));
                    if ($startStr < strlen($str)) {
                        EXECUPDATEHEX:
                        // echo "处理Update联合数据包\n";
                        // 则说明要处理的不止一个更新包(截取第二个/第三个更新包)
                        $nextHex = substr($str, $startStr);
                        $nextLength = base_convert(substr($nextHex, 32, 4), 16, 10); //获取第二个包的长度

                  

                        $tempNextLength = $nextLength * 2;
                        if($tempNextLength > strlen($nextHex)){
                            //数据包不完整，根本就无法解析
                            echo "[WorkNUM#{$serv->worker_id}] IP: {$remoteIp} \t UPDATE数据包不完整~跳过！\n";
                        }else{
                            $update[] = processingPathAttribute(substr($nextHex, 0, $tempNextLength));
                        }
                        //截取出新包
                        if ($tempNextLength  < strlen($nextHex)) { //如果还有包
                            $startStr += $tempNextLength;
                            goto EXECUPDATEHEX;
                        }
                        // 咱居然还能用到GOTO，啊 青春回来了
                    }
                    foreach ($update as $tempP) {
                        $tempP['info']['src_ip'] = $remoteIp; //记录是谁发过来的

                        //删除路由
                        if (!empty($tempP['withdrawnRoutes'])) {
                            //    if(){
                            foreach ($tempP['withdrawnRoutes'] as $key => $route) {
                                if ($redis->exists($route)) {
                                    //验证该撤销路由请求是否有权限分发(目前只验证在as—path里的peer能删掉这路由)
                                    $routeArr = $redis->get($route);
                                    $routeArr = json_decode($routeArr, true);
                                    $peer = $redis->get('peer-' . $remoteIp);
                                    $peer = json_decode($peer);
                                    if ($peer && $routeArr) {
                                        if (in_array($peer->asn, $routeArr['as_path']['asn'])) {
                                            $redis->del($route); //立即过期
                                        } else {
                                            unset($tempP['withdrawnRoutes'][$key]);
                                        }
                                    } else {
                                        unset($tempP['withdrawnRoutes'][$key]);
                                    }
                                } else {
                                    unset($tempP['withdrawnRoutes'][$key]);
                                }
                            }
                            //给其他Peer对象下发取消路由请求
                            if (!empty($tempP['withdrawnRoutes'])) {
                                $task_id = $serv->task($tempP);
                            }
                            //    }
                            // $tempP[]
                        } elseif (!empty($tempP['routes'])) {
                            //如果AS_PATH和本地ASN一样则不进行操作，无视这个更新
                            // var_dump($tempP);
                            if (!in_array(MYASN, $tempP['info']['as_path']['asn'])) {
                                foreach ($tempP['routes'] as $key => $route) { //开始解析IP

                                    if (!$redis->exists($route) || empty($tempP['info']['community'])) {
                                        //查询数据库获取IP所属国家/并打上Community
                                        $prefix = explode('/', $route);

                                        try {
                                            $record = $reader->country($prefix[0]);
                                            // var_dump($record->country->isoCode);
                                            if(!empty($record->country->isoCode)){
                                                $country = country($record->country->isoCode) ?? null;
                                                $communityCode = $country->getIsoNumeric() ?? 65535;
                                            }
                                            !empty($communityCode)?:$communityCode=65535;

                                            $tempCommunity =  COMMUNITY_ASN . ":" . intval($communityCode);

                                            // var_dump($tempP['info']['community']);
                                            if (empty($tempP['info']['community']) || !in_array($tempCommunity, $tempP['info']['community'])) {
                                                $tempP['info']['community'][] = COMMUNITY_ASN . ":" . intval($communityCode);
                                                $tempP['info']['country'] = $record->country->isoCode;
                                            }
                                            // array_push($tempP['info']['community'], );
                                            // var_dump($tempP['info']['community']);
                                        } catch (Exception $e) {
                                            echo "[WorkNUM#{$serv->worker_id}] IP段" . $route . "\t 通过IP数据库获取信息失败 \n";
                                        }
                                        $redis->set($route, json_encode($tempP['info']));
                                    } else {
                                        //对比谁的AS_PATH最短
                                        $route = $redis->get($route);
                                        $tempData = json_decode($route, true);
                                        $asnNum = count($tempP['info']['as_path']['asn']);
                                        if ($tempData) {
                                            if ($asnNum < $tempData['as_path']['asn']) {
                                                $redis->set($route, json_encode($tempP['info']));
                                            } else {
                                                // echo("非法Prefix".$tempP['routes'][$key]);
                                                unset($tempP['routes'][$key]);
                                            }
                                        }
                                    }
                                    //写入Redis
                                }

                                //TODO 优化这里这个明明规则
                                //以Group的方式再扔一份进Redis 
                                if (!empty($tempP['routes'])) {
                                    //记录这个ASN全部发送的Prefix
                                    $peer = $redis->get('peer-' . $remoteIp);
                                    if ($peer = json_decode($peer)) {
                                        $keyName = 'prefix-' . $remoteIp;
                                        if ($redis->exists($keyName)) {
                                            $json = $redis->get($keyName);
                                            if ($json = json_decode($json, true)) {
                                                $newArr = array_replace($json, $tempP['routes']);
                                                $redis->set($keyName, json_encode($newArr));
                                            }
                                        } else {
                                            $redis->set($keyName, json_encode($tempP['routes']));
                                        }
                                    }

                                    $tempRoute = explode('/', current($tempP['routes']));
                                    $redis->set("PrefixGroup-{$tempRoute[0]}-" . count($tempP['routes']), json_encode($tempP['routes']));
                                    //给其他Peer对象下发更新路由请求
                                    $task_id = $serv->task($tempP); //呜呜呜，开发环境的swoole太老了，只能换种实现方法了
                                }
                            }
                        }
                    }
                }
                break;
            case 3:
                $code = base_convert(substr($str, 38, 2), 16, 10);
                $subcode = base_convert(substr($str, 40, 2), 16, 10);
                echo "[WorkNUM#{$serv->worker_id}] IP: {$remoteIp} \t 收到NOTIFICATION数据包（有内鬼，停止交易！，Peer出错） ERROR CODE:{$code} SUBCODE: {$subcode} \n";
                //Idle那些就不记录了
                actionPeers('del', $remoteIp, $redis);
                break;
            case 4:
                // TODO 改为使用定时器（既然对方会和咱保持连接，那咱收到恁保持连接的包再发回一个给你不就好了
                echo "[WorkNUM#{$serv->worker_id}] IP: {$remoteIp} \t 回复KEEPALIVE数据包维持连接\n";
                actionPeers('update', $remoteIp, $redis, ['status' => 'Established']); //确定连接建立成功
                $serv->send($fd, pack('H*', $keepalive));
                break;
            case 5:
                //TODO 处理刷新路由数据包
                $afi = base_convert(substr($str, 38, 4), 16, 10);
                $safi =  base_convert(substr($str, 44, 2), 16, 10);
                break;
        }
    });
    //监听连接关闭事件
    $serv->on('Close', function ($serv, $fd) {
        $clientContent = $serv->getClientInfo($fd);;
        $remoteIp = $clientContent['remote_ip'] ?? "未知";
        echo "[WorkNUM#{$serv->worker_id}]IP: {$remoteIp} TCP断开连接.\n";
        $redis = getRedis();
        if ($redis->exists('prefix-' . $remoteIp)) {
            $routes = $redis->get('prefix-' . $remoteIp);
            $redis->del('peer-' . $remoteIp);//会话不存在了
            $routes = json_decode($routes,true);
            if(!empty($routes)){
                foreach($routes as $route){
                    $redis->del($route);//从路由表删除路由
                }
                $task_id = $serv->task(['withdrawnRoutes'=>$routes]);
            
            }
            

        }
        //TODO 删除在数据库的全部Prefix以及下发给其他对等联系人

    });

    //处理异步任务(此回调函数在task进程中执行)
    //下发更新数据
    $serv->on('Task', function ($serv, $task_id, $from_id, $data) {
        if (!empty($data['withdrawnRoutes'])) {
            $routesHex = "";
            foreach ($data['withdrawnRoutes'] as $route) { //转换请求路由成十六进制

                $routeArr = explode('/', $route);
                $prefixLength = $routeArr[1];
                if ($prefixLength === 0) {
                    $prefix = "";
                } elseif ($prefixLength > 0 && $prefixLength <= 8) {
                    $prefix = routertoHex(substr($routeArr[0], 0, -2));
                } elseif ($prefixLength > 8 && $prefixLength <= 16) {
                    $prefix = routertoHex(substr($routeArr[0], 0, -4));
                } elseif ($prefixLength > 16 && $prefixLength <= 24) {
                    $prefix = routertoHex(substr($routeArr[0], 0, -2));
                } elseif ($prefixLength > 24 && $prefixLength <= 32) {
                    $prefix = routertoHex($routeArr[0]);
                }
                $routesHex .=  sprintf("%02x", $prefixLength) . $prefix;
            }
            $withdrawnLength = strlen($routesHex) / 2;
            $length = 19 + 2 + $withdrawnLength + 2;
            $hex = "ffffffffffffffffffffffffffffffff" . sprintf("%04x", $length) . "02" . sprintf("%04x", $withdrawnLength) . $routesHex . "0000";
            //给其他Peer对象下发取消路由请求
            $data['withdrawnRoutesHex'] = $hex;
        }

        //返回任务执行的结果
        $serv->finish($data);
    });

    //下发数据
    //处理异步任务的结果(此回调函数在worker进程中执行)
    //因为咱Swoole版本太老，有的没法实现，只能曲线救国再这里实现了
    $serv->on('finish', function ($serv, $task_id, $data) {
        // echo "AsyncTask[$task_id] Finish: $data" . PHP_EOL;
        $redis = getRedis();

        if (!empty($data['withdrawnRoutesHex'])) {
            $hex = $data['withdrawnRoutesHex'];
        } elseif (!empty($data['routes'])) {
            $key = end($data['routes']);
            $tempRoute = explode('/', $key);
            $keyName = "PrefixGroup-{$tempRoute[0]}-" . count($data['routes']);
            if ($redis->exists($keyName)) {
                $hex = makeAddUpdate($keyName, $redis);
            }
        }

        $sendNum = 0;
        $peers = $redis->keys("peer-*.*.*.*");
        if (!empty($peers) && !empty($hex)) {
            // $serv->send()
            foreach ($peers as $keyName) {
                $peer = $redis->get($keyName);
                $peer = json_decode($peer);
                //如果最后收到的ASN和要发送给人的AS一样也取消发送
                if (!empty($data['routes']) && in_array($peer->asn, $data['info']['as_path']['asn'])) {
                    // 跳过
                } else {
                    $sendNum++;
                    $serv->send($peer->fd, pack('H*', $hex));
                    echo "[TaskNUM#{$serv->worker_id}] 任务ID:{$task_id} \t 向{$peer->address}发送路由更新  \n";
                }
            }
        }
        if(!empty($sendNum)){
            echo "[TaskNUM#{$serv->worker_id}] 任务ID:{$task_id} \t 执行完毕(向{$sendNum}个会话发送了更新)  \n";
        }
    });

    $serv->on('WorkerStart', function ($server, $worker_id) { //只打印一次
        $pid = posix_getpid();
        if ($server->taskworker) {
            echo "[TaskNUM#{$worker_id}] Task进程启动成功 \t PID:{$pid} \n";
        } else {
            echo "[WorkNUM#{$worker_id}] Worker进程启动成功 \t PID:{$pid} \n";
        }

        if ($worker_id == 0) {
            Swoole\Timer::tick(60000, function () {
                $redis = getRedis();
                //路由表
                $routes = $redis->keys("*.*.*.*/*");
                $peers = $redis->keys("peer-*.*.*.*");
                echo "\n服务状态：\n";
                echo "当前路由表内拥有路由：" . count($routes) . "\n";
                echo "当前Peer数量：" . count($peers) . "\n";
                foreach ($peers as $keyName) {
                    $peer = $redis->get($keyName);
                    $peer = json_decode($peer);
                    echo "IP:" . $peer->address . " \t ASN:" . $peer->asn . "\t 状态：" . $peer->status . "\n";
                }
                echo "\n";
            });
        }
    });

    $serv->on('Start', function ($server) {
        echo "[MercyCloud] 主进程&服务启动 \t PID:" . $server->master_pid . " \n";
    });

    $serv->start();

    //主动Peer别人，想摸鱼，以后再填
    // echo "客户端服务准备启动" . PHP_EOL;

    //解析要Peer的名单
    // if (file_exists("./peer.json")) {
    //     if (!empty(json_decode(file_get_contents("./peer.json")))) {
    //     //创建请求协程
    //     Co\run(function () {
    //         $client = new Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
    //         $peers = json_decode(file_get_contents("./peer.json"));
    //             foreach ($peers as $peer) {
    //                 if (!$client->connect($peer->address, 179, 5)) {
    //                     echo "无法连接IP:{$peer->address}: {$client->errCode}\n";
    //                 }
    //                 if (MYASN < 65535) { //支持4Byte ASN
    //                     $myASN = sprintf("%04x", 23456);
    //                 } else {
    //                     $myASN = sprintf("%04x", MYASN);
    //                 }
    //                 echo "开始向IP:{$peer->address}发送Open数据包";
    //                 $option = makeOpenOption();
    //                 $optionLen = strlen($option) / 2;
    //                 $length = 19 + 9 + $optionLen;
    //                 $sendData = "ffffffffffffffffffffffffffffffff" . sprintf("%04x", $length) . "0104" . $myASN . sprintf("%04x", HOLD_TIME) . routerIDtoHex() . $option;
    //                 $client->send(pack('H*', $sendData)); //主动发送Open包
    //                 echo $client->recv();
    //                 $client->close();
    //             }
    //     });
    //     }
}

//字符串转换成16进制
function strToHex($str)
{
    $hex = "";
    for ($i = 0; $i < strlen($str); $i++)
        $hex .= dechex(ord($str[$i]));
    $hex = strtoupper($hex);
    return $hex;
}


//OPEN包支持特性
function makeOpenOption()
{
    //假装自己能支持很多种特性（nmd 为什么bird默认比frr的短一半（bird垃圾，暴论
    $hostname = strToHex(HOSTNAME);
    $len = strlen($hostname) / 2;
    $result = "02060104000100010202800002020200020641" .
        "04" . sprintf("%08x", MYASN) //老娘可以支持4b asn
        . "020645040001010102" . sprintf("%02x", $len + 4) . "49" . sprintf("%02x", $len + 2) . sprintf("%02x", $len) . strToHex(strtolower(HOSTNAME)) . "00020440020078";

    $length = strlen($result) / 2;
    $result = sprintf("%01x", $length) . $result;
    return $result;
}

//ROUTE ID转换成十六进制
function routerIDtoHex()
{
    return routertoHex(ROUTERID);
}
// Route转化为数据包格式
function routertoHex($route)
{
    $arr = explode('.', $route);
    $str = "";
    foreach ($arr as $temp) {
        $str .= sprintf("%02x", $temp);
    }
    return $str;
}

//ROUTE ID 十六进制转换成字符串
function routerIDtoStr($routerID)
{
    $arr = str_split($routerID, 2);
    $str = "";
    foreach ($arr as $temp) {
        $str .= base_convert($temp, 16, 10) . ".";
    }
    return substr($str, 0, strlen($str) - 1);
}

//写这份代码的时候其实，感觉就是为了爽才写的，感觉就像是在发泄，咱什么时候才能实现财务自由呀
//处理路由信息 （只处理一个更新包）
function processingPathAttribute($hex)
{
    $hexLength = base_convert(substr($hex, 32, 4), 16, 10);
    $str = substr($hex, 38);

    //TODO 处理删除路由
    $withdrawnRoutesLength = base_convert(substr($str, 0, 4), 16, 10);
    if (!empty($withdrawnRoutesLength)) {
        $withdrawnRoutes = fuckRoutes(substr($str, 4, $withdrawnRoutesLength * 2), $withdrawnRoutesLength);
    }

    //处理路由属性
    $routesArrributes = 4 + $withdrawnRoutesLength * 2;
    $totalRoutesLength = base_convert(substr($str, $routesArrributes, 4), 16, 10);
    $pathAttributes = substr($str, $routesArrributes + 4, $totalRoutesLength * 2);
    $info = [];
    $readStr = 0;
    while (true) {
        if ($readStr >= $totalRoutesLength * 2) {
            break;
        }
        $flag = base_convert(substr($pathAttributes, $readStr, 2), 16, 10);
        $readStr += 2;
        $type = base_convert(substr($pathAttributes, $readStr, 2), 16, 10);
        $readStr += 2;

        switch ($flag) {
            case 16:
            case 48:
            case 112:
            case 144:
            case 240:
            case 80:
                $length = base_convert(substr($pathAttributes, $readStr, 4), 16, 10);
                $readStr += 4;
                break;
            default:
                $length = base_convert(substr($pathAttributes, $readStr, 2), 16, 10);
                $readStr += 2;
        }

        switch ($type) {
            case 1: //收到路由类型
                $origin = base_convert(substr($pathAttributes, $readStr, 2), 16, 10);
                switch ($origin) {
                    case 0:
                        $content = "IGP";
                        break;
                    case 1:
                        $content = "EGP";
                        break;
                    case 2:
                        $content = "incomplete";
                        break;
                }
                $info['origin'] = $content;
                $readStr += 2;
                break;
            case 2:
                $segmentType = base_convert(substr($pathAttributes, $readStr, 2), 16, 10);
                //  echo substr($pathAttributes,$readStr,2);
                $readStr += 2;
                $segmentLength = base_convert(substr($pathAttributes, $readStr, 2), 16, 10);
                $readStr += 2;
                $asn = [];
                for ($i = 1; $i <= $segmentLength; $i++) {
                    $asn[] =  base_convert(substr($pathAttributes, $readStr, 8), 16, 10);
                    $readStr += 8;
                }
                $content = [
                    'segmentType' => $segmentType,
                    'segmentLength' => $segmentLength,
                    'asn' => $asn
                ];
                $info['as_path'] = $content;
                break;
            case 3:
                $info['next_hop'] = routerIDtoStr(substr($pathAttributes, $readStr, 8));
                $readStr += $length * 2;
                break;
            case 4:
                $info['metric'] = base_convert(substr($pathAttributes, $readStr, 8), 16, 10);
                $readStr += 8;
                break;
            case 8:
                $community = [];
                for ($i = 0; $i < $length; $i += 4) {
                    $temp1 =  base_convert(substr($pathAttributes, $readStr, 4), 16, 10);
                    $readStr += 4;
                    $temp2 =  base_convert(substr($pathAttributes, $readStr, 4), 16, 10);
                    $readStr += 4;
                    //VULTR NMLGCB 不发全表就算了，还发过来的社区有些是有问题的
                    if ($temp1 !== 0 && $temp2 !== 0 && $temp1 <= 65535 && $temp2 <= 65535) {
                        $community[] = "{$temp1}:{$temp2}";
                    }
                }
                $info['community'] = $community;
                break;
                //支持各种乱七八糟的社区
            // case 23:
            //     $largeCommunity = [];

            //     for ($i = 0; $i < $length; $i += 12) {
            //         $temp1 =  base_convert(substr($pathAttributes, $readStr, 8), 16, 10);
            //         $readStr += 8;
            //         $temp2 =  base_convert(substr($pathAttributes, $readStr, 8), 16, 10);
            //         $readStr += 8;
            //         $temp3 =  base_convert(substr($pathAttributes, $readStr, 8), 16, 10);
            //         $readStr += 8;
            //         $largeCommunity[] = "{$temp1}:{$temp2}:{$temp3}";
            //     }
            //     $info['largeCommunity'] = $largeCommunity;
            // case 16:
                $extendedCommunity = [];
                for ($i = 0; $i < $length; $i += 8) {
                    $temp1 =  base_convert(substr($pathAttributes, $readStr, 2), 16, 10);
                    $readStr += 2;
                    $temp2 =  base_convert(substr($pathAttributes, $readStr, 2), 16, 10);
                    $readStr += 2;
                    $temp3 =  base_convert(substr($pathAttributes, $readStr, 4), 16, 10);
                    $readStr += 4;
                    $temp4 =  base_convert(substr($pathAttributes, $readStr, 8), 16, 10);
                    $readStr += 8;
                    
                    $extendedCommunity[] = "{$temp3}:{$$temp4}";
                }
                $info['largeCommunity'] = $extendedCommunity; 
            default:
                $content = substr($pathAttributes, $readStr, $length * 2);
                //   echo $length;
                $readStr += $length * 2;
                // $info["Type" . $type] = $content;
                break;
        }
    }
    //19(BGP包头) 4（两个固定写长度的）+ 路由属性 + 路由 + 撤销路由（可选）
    //处理新增路由
    $useLength = 19 + 4 + $totalRoutesLength + $withdrawnRoutesLength;
    $length = $hexLength - $useLength;
    $routesHex = substr($hex, $useLength * 2);
    // $routesLen = strlen($routesHex);
    $routes = fuckRoutes($routesHex, $length);

    //关联路由
    // $info['unino'] = $routes;
    return [
        'routes' => $routes,
        'info' => $info,
        'withdrawnRoutes' => $withdrawnRoutes
    ];
}

//分离出路由
//起这个名字，主要是因为测试时开了个傻逼vu，发默认路由就算了，还发了一堆/32
function fuckRoutes($hex, $length)
{

    $start = 0;
    $routes = [];
    while (true) {
        if ($start >= $length * 2) {
            break;
        }

        $prefixLength = base_convert(substr($hex, $start, 2), 16, 10);
        $start += 2;

        if ($prefixLength === 0) {
            $prefix = "0.0.0.0";
        } elseif ($prefixLength > 0 && $prefixLength <= 8) {
            $prefix = routerIDtoStr(substr($hex, $start, 2)) . ".0.0.0";
            $start += 2;
        } elseif ($prefixLength > 8 && $prefixLength <= 16) {
            $prefix = routerIDtoStr(substr($hex, $start, 4)) . ".0.0";
            $start += 4;
        } elseif ($prefixLength > 16 && $prefixLength <= 24) {
            $prefix = routerIDtoStr(substr($hex, $start, 6)) . ".0";
            $start += 6;
        } elseif ($prefixLength > 24 && $prefixLength <= 32) {
            $prefix = routerIDtoStr(substr($hex, $start, 8));
            $start += 8;
        }

        $routes[] = $prefix . "/{$prefixLength}";
    }
    return $routes;
}

//生成一个UPDATE包（添加路由
function makeAddUpdate($group, $redis)
{
    $groupKeys = $redis->get($group);
    $groupKeys = json_decode($groupKeys, true);

    if ($groupKeys !== false) {
        $NLRLHex = "";
        foreach ($groupKeys as $routeKey) {
            if ($redis->exists($routeKey)) {
                $routeArr = explode('/', $routeKey);
                $prefixLength = $routeArr[1];
                if ($prefixLength === 0) {
                    $prefix = "";
                } elseif ($prefixLength > 0 && $prefixLength <= 8) {
                    $prefix = routertoHex(substr($routeArr[0], 0, -2));
                } elseif ($prefixLength > 8 && $prefixLength <= 16) {
                    $prefix = routertoHex(substr($routeArr[0], 0, -4));
                } elseif ($prefixLength > 16 && $prefixLength <= 24) {
                    $prefix = routertoHex(substr($routeArr[0], 0, -2));
                } elseif ($prefixLength > 24 && $prefixLength <= 32) {
                    $prefix = routertoHex($routeArr[0]);
                }
                $NLRLHex .=  sprintf("%02x", $prefixLength) . $prefix;
            } else {
                //如果不存在
                $key = array_search($routeKey, $groupKeys);
                if ($key !== false) {
                    // echo "删除Prefix";
                    unset($groupKeys[$key]);
                    $redis->set($group, json_encode($groupKeys));
                }
            }
        }

        if (empty($groupKeys)) { //如果一个能用的都没有，那么这个GroupKeys没存在的必要
            $redis->del($group);
        }

        $getKey = end($groupKeys);
        if ($redis->exists($getKey)) {
            $routeValue = $redis->get($getKey);
            $info = json_decode($routeValue, true);
            $hex = "";

            //路由类型
            switch ($info['origin']) {
                case "IGP":
                    $content = "0";
                    break;
                case "EGP":
                    $content = "1";
                    break;
                case "incomplete":
                    $content = "2";
                    break;
            }
            $hex .= "400101" . sprintf("%02x", $content);
            //处理AS PATH
            $hex .= "5002";
            // 删除不想要的AS_PATH
            foreach (REMOVE_AS_PATH as $asn) {
                $key = array_search($asn,  $info['as_path']['asn']);
                if ($key !== false) {
                    unset($info['as_path']['asn'][$key]);
                }
            }
            // $info['as_path']['asn'] .= MYASN;
            array_unshift($info['as_path']['asn'], MYASN); //将自己的ASN加进去
            //处理路径 十六进制
            $path = "";
            foreach ($info['as_path']['asn'] as $asn) {
                $path .=  sprintf("%08x", $asn);
            }
            //计算长度
            $asnNum = count($info['as_path']['asn']);
            $length = 2 + $asnNum * 4;

            $hex .= sprintf("%04x", $length) . sprintf(
                "%02x",
                $info['as_path']['segmentType']
            ) .
                sprintf("%02x", $asnNum) . $path;

            //处理NEXT—HOP
            // foreach($info)
            $hex .= "400304" . routertoHex(NEXT_HOP);

            //处理Community
            if (!empty($info['community'])) {
                $info['community'] = array_unique($info['community']);
                if (!empty($info['community'])) {
                    $communityHex = "";
                    foreach ($info['community'] as $community) {
                        $config = explode(':', $community);
                        $communityHex .=  sprintf("%04x", $config[0]) . sprintf("%04x", $config[1]);
                    }
                }
                $length = count($info['community']) * 4;
                $hex .= "c008" . sprintf("%02x", $length) . $communityHex;
            }

            //处理Meric
            // if (!empty($info['metric'])) {
            //     $hex .=  "8004" . sprintf("%08x", $info['metric']);
            // }
        } else {
            //生成空更新包
            $totalAttributeLength = 0;
            $NLRLLength = 0;
            $hex = "";
            $NLRLHex = "";
        }
    }
    $totalAttributeLength = strlen($hex) / 2;
    $NLRLLength = strlen($NLRLHex) / 2;
    $length = $totalAttributeLength + $NLRLLength + 19 + 4;
    $sendData = "ffffffffffffffffffffffffffffffff" . sprintf("%04x", $length)
        . "020000"
        . sprintf("%04x", $totalAttributeLength)
        . $hex
        . $NLRLHex;
    return $sendData;
}

//维护Peer表
function actionPeers(string $action, string $ip, $redis, array $data = null)
{
    $keyName = "peer-{$ip}";
    switch ($action) {
        case "add":
            $redis->set($keyName, json_encode($data));
            break;
        case "update":
            if ($redis->exists($keyName)) {
                $oldData = $redis->get($keyName);
                $oldData = json_decode($oldData, true);
            }
            $redis->set($keyName, json_encode(array_replace($oldData, $data)));
            break;
        case "del":
            $redis->del($keyName);
            break;
        case 'get':
            if ($redis->exists($keyName)) {
                $data = $redis->get($keyName);
                $data = json_decode($data, true);
            } else {
                $data = false;
            }
            return $data;
            break;
    }
}
//获取REDIS客户端
function getRedis()
{
    $redis = new Swoole\Coroutine\Redis;
    $status = $redis->connect(REDIS_SERVER, REDIS_PORT);
    if (!$status) {
        echo "REDIS连接失败!\n";
    }
    return $redis;
}
