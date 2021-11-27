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
 * Restores a database from a SQL dump file.
 *
 * <code>
 * use Rah\Danpu\Dump;
 * use Rah\Danpu\Import;
 * $config = new Dump;
 * $config
 *    ->file('/path/to/target/dump/file.sql')
 *    ->dsn('mysql:dbname=database;host=localhost')
 *    ->user('username')
 *    ->pass('password')
 *    ->tmp('/tmp');
 *
 * new Import($config);
 * </code>
 */

class Import extends Base
{
    /**
     * {@inheritdoc}
     */

    public function init()
    {
        $this->connect();
        $this->tmpFile();

        if (!is_file($this->config->file) || !is_readable($this->config->file)) {
            throw new Exception('Unable to access the source file.');
        }

        if ($this->compress) {
            $gzip = new Compress();
            $gzip->unpack($this->config->file, $this->temp);
        } else {
            copy($this->config->file, $this->temp);
        }

        $this->open($this->temp, 'r');
        $this->import();
        $this->close();
        $this->clean();
    }

    /**
     * Processes the SQL file.
     *
     * Reads a SQL file by line by line. Expects that
     * individual queries are separated by semicolons,
     * and that quoted values are properly escaped,
     * including newlines.
     *
     * Queries themselves can not contain any comments.
     * All comments are stripped from the file.
     */

    protected function import()
    {
        $query = '';

        while (!feof($this->file)) {
            $line = fgets($this->file);
            $trim = trim($line);

            if ($trim === '' || strpos($trim, '--') === 0 || strpos($trim, '/*') === 0) {
                continue;
            }

            if (strpos($trim, 'DELIMITER ') === 0) {
                $this->delimiter = substr($trim, 10);
                continue;
            }

            $query .= $line;

            if (substr($trim, strlen($this->delimiter) * -1) === $this->delimiter) {
                $this->pdo->exec(substr(trim($query), 0, strlen($this->delimiter) * -1));
                $query = '';
            }
        }
    }
}
