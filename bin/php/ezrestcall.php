#!/usr/bin/env php
<?php
/**
 * File containing the ezrestcall.php script.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package kernel
 */
 
// Load existing class autoloads
require('autoload.php');

// The code is in extension/ezprestapi/classes/runnable/commands/php_ezrestcall.php (#207); this file is the entry point.
\Exponential\Command\Extension\Ezprestapi\Ezrestcall::main( __FILE__ );
