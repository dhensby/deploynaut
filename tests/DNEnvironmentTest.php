<?php
/**
 *
 */
class DNEnvironmentTest extends DeploynautTest {
	/**
	 * @var string
	 */
	protected static $fixture_file = 'DNEnvironmentTest.yml';

	public function testPermissions() {
		$environment = $this->objFromFixture('DNEnvironment', 'uat');

		$viewer1 = $this->objFromFixture('Member', 'allowNonProdDeployment');
		$viewer2 = $this->objFromFixture('Member', 'allowProdDeployment');

		$random = new Member(array('Email' => 'random@example.com'));
		$random->write();

		$this->assertFalse($environment->canView($random));
		$this->assertTrue($environment->canView($viewer1));
		$this->assertTrue($environment->canView($viewer2));
	}

	private function checkSnapshots($assert, $env, $member) {
		$this->$assert($env->canRestore($member));
		$this->$assert($env->canBackup($member));
		$this->$assert($env->canDownloadArchive($member));
		$this->$assert($env->canDeleteArchive($member));
	}

	public function testAllows() {
		$prod = $this->objFromFixture('DNEnvironment', 'prod');
		$uat = $this->objFromFixture('DNEnvironment', 'uat');
		$this->assertTrue($prod->canDeploy($this->objFromFixture('Member', 'allowProdDeployment')));
		$this->assertFalse($prod->canDeploy($this->objFromFixture('Member', 'allowNonProdDeployment')));
		$this->assertFalse($uat->canDeploy($this->objFromFixture('Member', 'allowProdDeployment')));
		$this->assertTrue($uat->canDeploy($this->objFromFixture('Member', 'allowNonProdDeployment')));

		$this->checkSnapshots('assertTrue', $prod, $this->objFromFixture('Member', 'allowProdSnapshot'));
		$this->checkSnapshots('assertFalse', $prod, $this->objFromFixture('Member', 'allowNonProdSnapshot'));
		$this->checkSnapshots('assertFalse', $uat, $this->objFromFixture('Member', 'allowProdSnapshot'));
		$this->checkSnapshots('assertTrue', $uat, $this->objFromFixture('Member', 'allowNonProdSnapshot'));
	}

	public function testBackendIdentifierField() {
		// Two backends means that there will be a dropdown field
		$backends = array(
			'BackendOne' => 'One',
			'BackendTwo' => 'Two',
		);

		Config::inst()->remove('DNEnvironment', 'allowed_backends');
		Config::inst()->update('DNEnvironment', 'allowed_backends', $backends);

		$environment = $this->objFromFixture('DNEnvironment', 'uat');
		$fields = $environment->getCMSFields();
		$this->assertEquals($backends, $fields->dataFieldByName('BackendIdentifier')->getSource());

		// One backend means that there won't
		Config::inst()->remove('DNEnvironment', 'allowed_backends');
		Config::inst()->update('DNEnvironment', 'allowed_backends', array('BackendOne' => 'One'));

		$fields = $environment->getCMSFields();
		$this->assertNull($fields->dataFieldByName('BackendIdentifier'));
	}
}

class BackendOne extends Object implements DeploymentBackend, TestOnly {

	public function planDeploy(\DNEnvironment $environment, $options) {
		// noop
	}

	public function deploy(
		\DNEnvironment $environment,
		\DeploynautLogFile $log,
		\DNProject $project,
		$options
	) {
		// noop
	}

	public function getDeployOptions(\DNEnvironment $environment) {
		// noop
	}

	public function dataTransfer(\DNDataTransfer $dataTransfer, \DeploynautLogFile $log) {
		// noop
	}

	public function enableMaintenance(\DNEnvironment $environment, \DeploynautLogFile $log, \DNProject $project) {
		// noop
	}

	public function disableMaintenance(\DNEnvironment $environment, \DeploynautLogFile $log, \DNProject $project) {
		// noop
	}

	public function ping(\DNEnvironment $environment, \DeploynautLogFile $log, \DNProject $project) {
		// noop
	}

	public function letmein(\DNEnvironment $environment, \DeploynautLogFile $log, $username, $password) {
		// noop
	}

}

class BackendTwo extends Object implements DeploymentBackend, TestOnly {

	public function planDeploy(\DNEnvironment $environment, $options) {
		// noop
	}

	public function deploy(
		\DNEnvironment $environment,
		\DeploynautLogFile $log,
		\DNProject $project,
		$options
	) {
		// noop
	}

	public function getDeployOptions(\DNEnvironment $environment) {
		// noop
	}

	public function dataTransfer(\DNDataTransfer $dataTransfer, \DeploynautLogFile $log) {
		// noop
	}

	public function enableMaintenance(\DNEnvironment $environment, \DeploynautLogFile $log, \DNProject $project) {
		// noop
	}

	public function disableMaintenance(\DNEnvironment $environment, \DeploynautLogFile $log, \DNProject $project) {
		// noop
	}

	public function ping(\DNEnvironment $environment, \DeploynautLogFile $log, \DNProject $project) {
		// noop
	}

	public function letmein(\DNEnvironment $environment, \DeploynautLogFile $log, $username, $password) {
		// noop
	}

}
