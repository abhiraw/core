<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
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
namespace OCA\Files_external\Tests\Service;

use \OC\Files\Filesystem;

use OCA\Files_external\Service\GlobalStoragesService;
use OCA\Files_external\Service\StoragesService;
use \OCA\Files_external\Service\UserStoragesService;
use \OCA\Files_external\NotFoundException;
use \OCA\Files_external\Lib\StorageConfig;
use Test\Traits\UserTrait;

/**
 * @group DB
 */
class UserStoragesServiceTest extends StoragesServiceTest {
	use UserTrait;

	private $user;

	private $userId;

	/**
	 * @var StoragesService
	 */
	protected $globalStoragesService;

	public function setUp() {
		parent::setUp();

		$this->globalStoragesService = new GlobalStoragesService($this->backendService, $this->dbConfig);

		$this->userId = $this->getUniqueID('user_');
		$this->createUser($this->userId, $this->userId);
		$this->user = \OC::$server->getUserManager()->get($this->userId);

		/** @var \OCP\IUserSession|\PHPUnit_Framework_MockObject_MockObject $userSession */
		$userSession = $this->getMock('\OCP\IUserSession');
		$userSession
			->expects($this->any())
			->method('getUser')
			->will($this->returnValue($this->user));

		$this->service = new UserStoragesService($this->backendService, $this->dbConfig, $userSession);
	}

	private function makeTestStorageData() {
		return $this->makeStorageConfig([
			'mountPoint' => 'mountpoint',
			'backendIdentifier' => 'identifier:\OCA\Files_External\Lib\Backend\SMB',
			'authMechanismIdentifier' => 'identifier:\Auth\Mechanism',
			'backendOptions' => [
				'option1' => 'value1',
				'option2' => 'value2',
				'password' => 'testPassword',
			],
			'mountOptions' => [
				'preview' => false,
			]
		]);
	}

	public function testAddStorage() {
		$storage = $this->makeTestStorageData();

		$newStorage = $this->service->addStorage($storage);

		$id = $newStorage->getId();

		$newStorage = $this->service->getStorage($id);

		$this->assertEquals($storage->getMountPoint(), $newStorage->getMountPoint());
		$this->assertEquals($storage->getBackend(), $newStorage->getBackend());
		$this->assertEquals($storage->getAuthMechanism(), $newStorage->getAuthMechanism());
		$this->assertEquals($storage->getBackendOptions(), $newStorage->getBackendOptions());
		$this->assertEquals(0, $newStorage->getStatus());

		// hook called once for user
		$this->assertHookCall(
			current(self::$hookCalls),
			Filesystem::signal_create_mount,
			$storage->getMountPoint(),
			\OC_Mount_Config::MOUNT_TYPE_USER,
			$this->userId
		);

		$nextStorage = $this->service->addStorage($storage);
		$this->assertEquals($id + 1, $nextStorage->getId());
	}

	public function testUpdateStorage() {
		$storage = $this->makeStorageConfig([
			'mountPoint' => 'mountpoint',
			'backendIdentifier' => 'identifier:\OCA\Files_External\Lib\Backend\SMB',
			'authMechanismIdentifier' => 'identifier:\Auth\Mechanism',
			'backendOptions' => [
				'option1' => 'value1',
				'option2' => 'value2',
				'password' => 'testPassword',
			],
		]);

		$newStorage = $this->service->addStorage($storage);

		$backendOptions = $newStorage->getBackendOptions();
		$backendOptions['password'] = 'anotherPassword';
		$newStorage->setBackendOptions($backendOptions);

		self::$hookCalls = [];

		$newStorage = $this->service->updateStorage($newStorage);

		$this->assertEquals('anotherPassword', $newStorage->getBackendOptions()['password']);
		$this->assertEquals([$this->userId], $newStorage->getApplicableUsers());
		// these attributes are unused for user storages
		$this->assertEmpty($newStorage->getApplicableGroups());
		$this->assertEquals(0, $newStorage->getStatus());

		// no hook calls
		$this->assertEmpty(self::$hookCalls);
	}

	/**
	 * @dataProvider deleteStorageDataProvider
	 */
	public function testDeleteStorage($backendOptions, $rustyStorageId, $expectedCountAfterDeletion) {
		parent::testDeleteStorage($backendOptions, $rustyStorageId, $expectedCountAfterDeletion);

		// hook called once for user (first one was during test creation)
		$this->assertHookCall(
			self::$hookCalls[1],
			Filesystem::signal_delete_mount,
			'/mountpoint',
			\OC_Mount_Config::MOUNT_TYPE_USER,
			$this->userId
		);
	}

	public function testHooksRenameMountPoint() {
		$storage = $this->makeTestStorageData();
		$storage = $this->service->addStorage($storage);

		$storage->setMountPoint('renamedMountpoint');

		// reset calls
		self::$hookCalls = [];

		$this->service->updateStorage($storage);

		// hook called twice
		$this->assertHookCall(
			self::$hookCalls[0],
			Filesystem::signal_delete_mount,
			'/mountpoint',
			\OC_Mount_Config::MOUNT_TYPE_USER,
			$this->userId
		);
		$this->assertHookCall(
			self::$hookCalls[1],
			Filesystem::signal_create_mount,
			'/renamedMountpoint',
			\OC_Mount_Config::MOUNT_TYPE_USER,
			$this->userId
		);
	}

	/**
	 * @expectedException \OCA\Files_external\NotFoundException
	 */
	public function testGetAdminStorage() {
		$backend = $this->backendService->getBackend('identifier:\OCA\Files_External\Lib\Backend\SMB');
		$authMechanism = $this->backendService->getAuthMechanism('identifier:\Auth\Mechanism');

		$storage = new StorageConfig();
		$storage->setMountPoint('mountpoint');
		$storage->setBackend($backend);
		$storage->setAuthMechanism($authMechanism);
		$storage->setBackendOptions(['password' => 'testPassword']);
		$storage->setApplicableUsers([$this->userId]);

		$newStorage = $this->globalStoragesService->addStorage($storage);

		$this->assertInstanceOf('\OCA\Files_external\Lib\StorageConfig', $this->globalStoragesService->getStorage($newStorage->getId()));

		$this->service->getStorage($newStorage->getId());
	}
}
