<?php

namespace Yurun\Util\YurunHttp;

/**
 * 所有属性的常量定义.
 *
 * PRIVATE_ 开头的为内部属性，请勿使用
 */
abstract class Attributes
{
    /**
     * 客户端参数.
     */
    const OPTIONS = 'options';

    /**
     * 全局默认 UserAgent.
     */
    const USER_AGENT = 'userAgent';

    /**
     * 重试次数.
     */
    const RETRY = 'retry';

    /**
     * 下载文件保存路径.
     */
    const SAVE_FILE_PATH = 'saveFilePath';

    /**
     * 保存文件的模型.
     */
    const SAVE_FILE_MODE = 'saveFileMode';

    /**
     * 允许重定向.
     */
    const FOLLOW_LOCATION = 'followLocation';

    /**
     * 最大允许重定向次数.
     */
    const MAX_REDIRECTS = 'maxRedirects';

    /**
     * 是否验证 CA 证书.
     */
    const IS_VERIFY_CA = 'isVerifyCA';

    /**
     * CA 证书.
     */
    const CA_CERT = 'caCert';

    /**
     * SSL 证书类型.
     */
    const CERT_TYPE = 'certType';

    /**
     * SSL 证书路径.
     */
    const CERT_PATH = 'certPath';

    /**
     * SSL 证书密码
     */
    const CERT_PASSWORD = 'certPassword';

    /**
     * SSL 密钥类型.
     */
    const KEY_TYPE = 'keyType';

    /**
     * SSL 密钥路径.
     */
    const KEY_PATH = 'keyPath';

    /**
     * SSL 密钥密码
     */
    const KEY_PASSWORD = 'keyPassword';

    /**
     * 使用代理.
     */
    const USE_PROXY = 'useProxy';

    /**
     * 代理类型.
     */
    const PROXY_TYPE = 'proxy.type';

    /**
     * 代理服务器地址
     */
    const PROXY_SERVER = 'proxy.server';

    /**
     * 代理服务器端口.
     */
    const PROXY_PORT = 'proxy.port';

    /**
     * 代理用户名.
     */
    const PROXY_USERNAME = 'proxy.username';

    /**
     * 代理密码
     */
    const PROXY_PASSWORD = 'proxy.password';

    /**
     * 代理的 Basic 认证配置.
     */
    const PROXY_AUTH = 'proxy.auth';

    /**
     * 认证用户名.
     */
    const USERNAME = 'username';

    /**
     * 认证密码
     */
    const PASSWORD = 'password';

    /**
     * 超时时间.
     */
    const TIMEOUT = 'timeout';

    /**
     * 连接超时.
     */
    const CONNECT_TIMEOUT = 'connectTimeout';

    /**
     * 保持长连接.
     */
    const KEEP_ALIVE = 'keep_alive';

    /**
     * 下载限速
     */
    const DOWNLOAD_SPEED = 'downloadSpeed';

    /**
     * 上传限速
     */
    const UPLOAD_SPEED = 'uploadSpeed';

    /**
     * 使用自定义重定向操作.
     */
    const CUSTOM_LOCATION = 'customLocation';

    /**
     * http2 请求不调用 recv().
     */
    const HTTP2_NOT_RECV = 'http2_not_recv';

    /**
     * 启用 Http2 pipeline.
     */
    const HTTP2_PIPELINE = 'http2_pipeline';

    /**
     * 启用连接池.
     */
    const CONNECTION_POOL = 'connection_pool';

    /**
     * 重试计数.
     */
    const PRIVATE_RETRY_COUNT = '__retryCount';

    /**
     * 重定向计数.
     */
    const PRIVATE_REDIRECT_COUNT = '__redirectCount';

    /**
     * WebSocket 请求
     */
    const PRIVATE_WEBSOCKET = '__websocket';

    /**
     * Http2 流ID.
     */
    const PRIVATE_HTTP2_STREAM_ID = '__http2StreamId';

    /**
     * 是否为 Http2.
     */
    const PRIVATE_IS_HTTP2 = '__isHttp2';

    /**
     * 是否为 WebSocket.
     */
    const PRIVATE_IS_WEBSOCKET = '__isWebSocket';

    /**
     * 连接对象
     */
    const PRIVATE_CONNECTION = '__connection';

    /**
     * 连接池的键.
     */
    const PRIVATE_POOL_KEY = '__poolKey';
}
