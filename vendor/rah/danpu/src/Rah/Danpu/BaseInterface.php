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
 * Base interface.
 *
 * @since 2.6.0
 */

interface BaseInterface
{
    /**
     * Constructor.
     *
     * @param Dump $config The config
     */

    public function __construct(Dump $config);

    /**
     * Initializes the action.
     *
     * @throws Exception
     * @internal
     */

    public function init();

    /**
     * Destructor.
     *
     * Cleans trash and safely destructs the instance.
     */

    public function __destruct();

    /**
     * Connects to the database.
     *
     * Populates $this->pdo with new
     * PDO connection instance.
     *
     * @see    \PDO
     * @throws Exception
     * @internal
     */

    public function connect();

    /**
     * Returns a path to the target file.
     *
     * @return string The path
     */

    public function __toString();
}
