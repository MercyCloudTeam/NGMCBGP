<?php
//恁的ASN
define("MYASN",65001);
//主机名称
define("HOSTNAME","SBYF");
//启动工作进程数量
define("WORKER_NUM",2);
//TASK进程数量
define("TASKER_NUM",2);
//RouteID
define("ROUTERID","192.168.50.47");
//KEEPALIVE时间
define("KEEPALIVE",60);
//HOLD_TIME时间
define("HOLD_TIME",180);
//连接REDIS服务器
define('REDIS_SERVER',"127.0.0.1");
define('REDIS_PORT',6379);
//自动REMOVE掉一定的AS_PATH
define('REMOVE_AS_PATH',['9886']);
//MMDB路径
define('MMDB_PATH','./mmdb/GeoLite2-Country.mmdb');
//指定上游（只允许范围内上游进行Update）
define('UPSTEAM_IP',['0.0.0.0/0']);
//NEXT_HOP路由的下一跳地址
define('NEXT_HOP','192.168.50.1');
//默认社区ASN,用65535来标记社区
define('COMMUNITY_ASN',65009);