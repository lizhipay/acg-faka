<?php

/**
 * Danpu - Database backup library
 *
 * @author  Jukka Svahn
 * @license MIT
 * @link    https://github.com/gocom/danpu
 */

/*
 * Copyright (C) 2018 Jukka Svahn
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 * CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Rah\Danpu;

/**
 * Dump config.
 *
 * Configure and pass to a worker class as
 * the constructors first argument.
 *
 * The preferred way of creating a new configuration
 * is with the Dump class. Initialize it and set
 * the properties.
 *
 * <code>
 * $dump = new \Rah\Danpu\Dump();
 * $dump
 *    ->file('/path/to/target/dump/file.sql')
 *    ->dsn('mysql:dbname=database;host=localhost')
 *    ->user('username')
 *    ->pass('password')
 *    ->tmp('/tmp');
 * </code>
 *
 * When done pass the instance to a worker class such as Export
 * through the constructor.
 *
 * <code>
 * new \Rah\Danpu\Export($dump);
 * </code>
 *
 * Alternative to Dump class, the Config class can be extended.
 *
 * <code>
 * namespace App\Dump;
 * class Config extends \Rah\Danpu\Config
 * {
 *     public $file = '/path/to/dump.sql';
 *     public $dsn = 'mysql:dbname=database;host=localhost';
 *     public $user = 'username';
 *     public $pass = 'password';
 *     public $tmp = '/tmp';
 * }
 * </code>
 *
 * Extending could be used to generate application wide
 * pre-populated configuration sets. Just pass an instance of
 * your config class to a worker class through Dump:
 *
 * <code>
 * use App\Dump\Config;
 * use Rah\Danpu\Export;
 * use Rah\Danpu\Dump;
 * new Export(new Dump(new Config));
 * </code>
 *
 * @since 2.3.0
 * @see   Dump
 */

class Config
{
    /**
     * Data source name.
     *
     * The DSN used to connect to the database. Basically specifies
     * the database location. In general, a DSN consists of the PDO driver name,
     * followed by a colon, followed by the PDO driver-specific connection syntax.
     *
     * For instance to connect to a MySQL database hosted locally:
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->dsn('mysql:dbname=database;host=localhost');
     * </code>
     *
     * Where 'database' is the name of the database and 'localhost'
     * is the hostname.
     *
     * @var   string
     * @since 2.2.0
     * @link  https://www.php.net/manual/en/ref.pdo-mysql.connection.php
     */

    public $dsn;

    /**
     * The username used to connect to the database.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->user('DatabaseUsername');
     * </code>
     *
     * @var string
     */

    public $user;

    /**
     * The password used to connect to the database.
     *
     * Database user's password. Defaults to
     * an empty string.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->password('DatabasePassword');
     * </code>
     *
     * @var string
     */

    public $pass = '';

    /**
     * Connection attributes.
     *
     * An array of driver-specific connection options. This
     * affect to the connection that is used for taking
     * the backup.
     *
     * For instance, you can use this to increase the
     * timeout limit if its too little.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->attributes(array(
     *     \PDO::ATTR_TIMEOUT => 900,
     * ));
     * </code>
     *
     * @var array
     */

    public $attributes = array();

    /**
     * Database encoding.
     *
     * Set this to what your data in your
     * database uses. Defaults to 'utf8'.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->encoding('utf16');
     * </code>
     *
     * To minimize issues, don't mix different encodings
     * in your database. All data should be encoded
     * using the same.
     *
     * @var string
     */

    public $encoding = 'utf8';

    /**
     * An array of ignored tables, views and triggers based on the target table.
     *
     * This can be used to exclude confidential or temporary
     * data from the backup, like passwords and sessions values.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->ignore(array('user_sessions', 'user_credentials'));
     * </code>
     *
     * @var   array
     * @since 2.1.0
     */

    public $ignore = array();

    /**
     * A prefix used by tables, views and triggers based on the target table.
     *
     * Taken backup will only include items that start
     * with the prefix.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->prefix('user_');
     * </code>
     *
     * @var   string
     * @since 2.6.0
     */

    public $prefix;

    /**
     * Temporary directory.
     *
     * Absolute path to the temporary directory without
     * trailing slash. Defaults to '/tmp'.
     *
     * This directory is used as a temporary storage for
     * writing the backup, a on-disk buffer so to speak.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->tmp('/path/to/temporary/directory');
     * </code>
     *
     * This directory must be writable and private. You
     * may not want to use a virtual one stored in memory,
     * given that we are writing your database backup
     * in there, and it might be a large one.
     *
     * @var string
     */

    public $tmp = '/tmp';

    /**
     * The target SQL dump file.
     *
     * Your backup is written to the specified
     * location. To enable Gzipping, add '.gz' extension
     * to the filename.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->file('/path/to/dump.sql');
     * </code>
     *
     * @var string
     */

    public $file;

    /**
     * Dump table data.
     *
     * Set FALSE to only dump structure. No
     * data and inserts will be added.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->data(false);
     * </code>
     *
     * @var   bool
     * @since 2.4.0
     */

    public $data = true;

    /**
     * Dump table structure.
     *
     * Set FALSE to only dump table data.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->structure(false);
     * </code>
     *
     * @var   bool
     * @since 2.7.0
     */

    public $structure = true;

    /**
     * Dump triggers.
     *
     * Set FALSE to skip triggers. The dump
     * file will not contain any triggers.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->trigger(false);
     * </code>
     *
     * @var   bool
     * @since 2.5.0
     */

    public $triggers = true;

    /**
     * Dump events.
     *
     * Set FALSE to skip events. The dump
     * file will not contain any events.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->events(false);
     * </code>
     *
     * @var   bool
     * @since 2.7.0
     */

    public $events = true;

    /**
     * Enables dumping the database create statement.
     *
     * Set to TRUE to add create database statement
     * to the created SQL dump file.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->createDatabase(true);
     * </code>
     *
     * @var   bool
     * @since 2.7.0
     */

    public $createDatabase = false;

    /**
     * Disables foreign key checks.
     *
     * Set TRUE to disable checks. The generated dump
     * file will contain statements that temporarily disable
     * unique key checks. This will speed up large data
     * imports to InnoDB tables.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->disableForeignKeyChecks(true);
     * </code>
     *
     * @var   bool
     * @since 2.6.0
     */

    public $disableForeignKeyChecks = false;

    /**
     * Disables unique key checks.
     *
     * Set TRUE to disable checks. The generated dump
     * file will contain statements that temporarily disable
     * unique key checks. This will speed up large data
     * imports to InnoDB tables.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->disableUniqueKeyChecks(true);
     * </code>
     *
     * @var   bool
     * @since 2.6.0
     */

    public $disableUniqueKeyChecks = false;

    /**
     * Disables auto-commit mode.
     *
     * Set TRUE to disable automatic commits. This will speed up
     * large data imports to InnoDB tables as each commit is not
     * written to the disk right after.
     *
     * When the generated dump is imported, MySQL is instructed
     * to do the actions in memory and write them to the disk
     * only once the dump has been successfully processed. This
     * option will not work if the import is larger than there
     * is memory to be allocated on the system where the
     * resulting backup is ran at.
     *
     * <code>
     * $dump = new \Rah\Danpu\Dump();
     * $dump->disableAutoCommit(true);
     * </code>
     *
     * @var   bool
     * @since 2.6.0
     */

    public $disableAutoCommit = false;

    /**
     * The database name.
     *
     * @var        string
     * @deprecated 2.2.0
     */

    public $db;

    /**
     * The hostname.
     *
     * @var        string
     * @deprecated 2.2.0
     */

    public $host = 'localhost';
}
