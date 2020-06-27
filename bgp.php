<?php
require "./service.php";

//无能狂怒
echo "Welcome Use Mercycloud PoZhen BGP Service".PHP_EOL;
echo "Warning:本项目代码过于垃圾，请一定要在生产环境使用！",PHP_EOL;
echo "Warning:项目内不对输入参数等进行校验（逃）！",PHP_EOL;

if(!extension_loaded('swoole')){
    die('本项目依赖 Swoole 扩展 ！'.PHP_EOL);
}
// 开启迫真服务器
startServer();