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
 * The base class.
 */

abstract class Base implements BaseInterface
{
    /**
     * The config.
     *
     * @var Config
     */

    protected $config;

    /**
     * An instance of PDO.
     *
     * @var \PDO
     */

    protected $pdo;

    /**
     * Tables in the database.
     *
     * @var \PDOStatement
     */

    protected $tables;

    /**
     * File pointer.
     *
     * @var resource
     */

    protected $file;

    /**
     * Path to the temporary file.
     *
     * @var string
     */

    protected $temp;

    /**
     * Compress the dump file.
     *
     * @var bool
     */

    protected $compress = false;

    /**
     * The query delimiter.
     *
     * @var   string
     * @since 2.5.0
     */

    protected $delimiter = ';';

    /**
     * The current database name.
     *
     * @var   string
     * @since 2.7.0
     */

    protected $database;

    /**
     * Server version number.
     *
     * @var   string
     * @since 2.7.0
     */

    protected $version;

    /**
     * {@inheritdoc}
     */

    public function __construct(Dump $config)
    {
        $this->config = $config;
        $this->compress = pathinfo($this->config->file, PATHINFO_EXTENSION) === 'gz';
        $this->init();
    }

    /**
     * {@inheritdoc}
     */

    public function __destruct()
    {
        $this->close();
        $this->clean();
        $this->unlock();
    }

    /**
     * {@inheritdoc}
     */

    public function connect()
    {
        if ($this->config->dsn === null && $this->config->db !== null) {
            trigger_error('Config::$db is deprecated, see Config::$dsn.', E_USER_DEPRECATED);
            $this->config->dsn("mysql:dbname={$this->config->db};host={$this->config->host}");
        }

        try {
            $this->pdo = new \PDO(
                $this->config->dsn,
                $this->config->user,
                $this->config->pass
            );

            $this->pdo->exec('SET NAMES '.$this->config->encoding);

            foreach ($this->config->attributes as $name => $value) {
                $this->pdo->setAttribute($name, $value);
            }

            $sth = $this->pdo->query('SELECT DATABASE() FROM DUAL');
            $database = $sth->fetch();
            $this->database = end($database);
            $this->version = (string) $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\PDOException $e) {
            throw new Exception('Connecting to database failed with message: '.$e->getMessage());
        }
    }

    /**
     * Gets tables.
     */

    protected function getTables()
    {
        $this->tables = $this->pdo->prepare('SHOW FULL TABLES');
    }

    /**
     * Locks all tables.
     *
     * @return bool
     */

    protected function lock()
    {
        $this->tables->execute();
        $table = array();

        while ($a = $this->tables->fetch(\PDO::FETCH_ASSOC)) {
            $table[] = current($a);
        }

        return !$table || $this->pdo->exec('LOCK TABLES `'.implode('` WRITE, `', $table).'` WRITE');
    }

    /**
     * Unlocks all tables.
     *
     * @return bool
     */

    protected function unlock()
    {
        return $this->pdo->exec('UNLOCK TABLES');
    }

    /**
     * Gets a path to a temporary file acting as a buffer.
     *
     * @throws Exception
     * @since  2.4.0
     */

    protected function tmpFile()
    {
        if (($this->temp = tempnam($this->config->tmp, 'Rah_Danpu_')) === false) {
            throw new Exception('Unable to create a temporary file, check the configured tmp directory.');
        }
    }

    /**
     * Cleans left over temporary file trash.
     *
     * @since 2.4.0
     */

    protected function clean()
    {
        if (file_exists($this->temp)) {
            unlink($this->temp);
        }
    }

    /**
     * Opens a file for writing.
     *
     * @param  string $filename The filename
     * @param  string $flags    Flags
     * @throws Exception
     */

    protected function open($filename, $flags)
    {
        if (is_file($filename) === false || ($this->file = fopen($filename, $flags)) === false) {
            throw new Exception('Unable to open the target file.');
        }
    }

    /**
     * Closes a file pointer.
     */

    protected function close()
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    /**
     * Writes a line to the file.
     *
     * @param string $string  The string to write
     * @param bool   $format  Format the string
     */

    protected function write($string, $format = true)
    {
        if ($format) {
            $string .= $this->delimiter;
        }

        $string .= "\n";

        if (fwrite($this->file, $string, strlen($string)) === false) {
            throw new Exception('Unable to write '.strlen($string).' bytes to the dumpfile.');
        }
    }

    /**
     * Moves a temporary file to the final location.
     *
     * @return bool
     * @throws Exception
     */

    protected function move()
    {
        if ($this->compress) {
            $gzip = new Compress($this->config);
            $gzip->pack($this->temp, $this->config->file);
            unlink($this->temp);
            return true;
        }

        if (@rename($this->temp, $this->config->file)) {
            return true;
        }

        if (@copy($this->temp, $this->config->file) && unlink($this->temp)) {
            return true;
        }

        throw new Exception('Unable to move the temporary file.');
    }

    /**
     * Gets a SQL delimiter.
     *
     * Gives out a character sequence that isn't
     * in the given query.
     *
     * @param  string      $delimiter Delimiter character
     * @param  string|null $query     The query to check
     * @return string      Unique delimiter character sequence
     * @since  2.7.0
     */

    protected function getDelimiter($delimiter = ';', $query = null)
    {
        while (1) {
            if ($query === null || strpos($query, $delimiter) === false) {
                return $delimiter;
            }

            $delimiter .= $delimiter;
        }
    }

    /**
     * {@inheritdoc}
     */

    public function __toString()
    {
        return (string) $this->config->file;
    }
}
