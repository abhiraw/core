<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
use OCA\Files_Sharing\Migration;

$installedVersion = \OC::$server->getConfig()->getAppValue('files_sharing', 'installed_version');

// Migration OC8.2 -> OC9
if (version_compare($installedVersion, '0.9.0', '<')) {
	$m = new Migration(\OC::$server->getDatabaseConnection());
	$m->removeReShares();
}

\OC::$server->getJobList()->add('OCA\Files_sharing\Lib\DeleteOrphanedSharesJob');
\OC::$server->getJobList()->add('OCA\Files_sharing\ExpireSharesJob');
