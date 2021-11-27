<?php

namespace Yurun\Util\YurunHttp\Stream;

/**
 * 流访问类型.
 */
abstract class StreamMode
{
    /**
     * 只读方式打开，指针指向开头.
     */
    const READONLY = 'r';

    /**
     * 读写方式打开，指针指向开头.
     */
    const READ_WRITE = 'r+';

    /**
     * 写入方式打开，将文件指针指向文件头并将文件大小截为零。如果文件不存在则尝试创建之。
     */
    const WRITE_CLEAN = 'w';

    /**
     * 读写方式打开，将文件指针指向文件头并将文件大小截为零。如果文件不存在则尝试创建之。
     */
    const READ_WRITE_CLEAN = 'w+';

    /**
     * 写入方式打开，将文件指针指向文件末尾。如果文件不存在则尝试创建之。
     */
    const WRITE_END = 'a';

    /**
     * 读写方式打开，将文件指针指向文件末尾。如果文件不存在则尝试创建之。
     */
    const READ_WRITE_END = 'a+';

    /**
     * 创建并以写入方式打开，将文件指针指向文件头。如果文件已存在，则 fopen() 调用失败并返回 FALSE，并生成一条 E_WARNING 级别的错误信息。如果文件不存在则尝试创建之。
     * 这和给底层的 open(2) 系统调用指定 O_EXCL|O_CREAT 标记是等价的。
     * 仅能用于本地文件。
     */
    const CREATE_WRITE = 'x';

    /**
     * 创建并以读写方式打开，将文件指针指向文件头。如果文件已存在，则 fopen() 调用失败并返回 FALSE，并生成一条 E_WARNING 级别的错误信息。如果文件不存在则尝试创建之。
     * 这和给底层的 open(2) 系统调用指定 O_EXCL|O_CREAT 标记是等价的。
     * 仅能用于本地文件。
     */
    const CREATE_READ_WRITE = 'x+';
}
