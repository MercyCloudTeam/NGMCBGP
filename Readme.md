# Readme

This is an experimental project

## 简介

迫真BGP实现，用于给服务优化路由和一种曲线救国方案，以及一种测试性项目。


## 功能

被动建立BGP Session.

收到的路由将自动打好Community（地区代码）

然后发送给其他对等的人

只实现了BGP的部分功能

支持4Byte ASN！（不会吧，不会吧，0202年了还有不支持的嘛？

## 依赖

PHP7.0+
Swoole扩展
Redis
GeoIP/其他IP数据库的 .mmdb文件一份

## 废话

随手写的项目，主要用于满足自己的需求，有些功能不一定需要就没写。

采用原生PHP+面向过程（意呆利面式代码~（其实核心就一个文件

代码写的非常摸鱼，就像是那种想到哪写到哪，不存在什么code style

很多功能是迫真/压根没实现

随手写的，已知BUG挺多的（又不是不能用）

断开会话 删除路由的时候处理的有些问题！

收路由表量比较大的时候也会出现问题！


## Community社区规则

通过IP数据库，区分收到的IP国家，并在发送给其他人的时候打上 配置的ASN:ISO国家代码

例如美国IP 114514:840 

## 如何使用

本项目会将收到的路由自动发送给peer的人，项目会将路由表存在redis里，mmdb用于给路由自动配置community

git clone git@github.com:MercyCloudTeam/NGMCBGP.git
cd NGMCBGP
composer install

下载一份mmdb文件存放到./mmdb

配置文件
vim config.php

启动项目
php bgp.php 

## 联系

YFsama[yf@rbq.ai]

VampireOo[oo@vampire.cloud]

## 感谢

MercyCloud

## 许可

MIT