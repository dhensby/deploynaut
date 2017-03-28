<?php

class RoutingTest extends FunctionalTest
{

	protected $usesDatabase = true;

	protected $autoFollowRedirection = false;

	public function setUpOnce() {
		parent::setUpOnce();
		\Injector::inst()->registerService(null, 'PingdomService');
	}

	public function testLoggedInRootRoute() {
		$this->logInWithPermission("ADMIN");
		$actual = $this->get('/naut/projects/');
		$this->assertEquals(200, $actual->getStatusCode());
	}

	public function testNotLoggedInRedirection() {
		$actual = $this->get('/naut/projects/');
		$this->assertEquals(302, $actual->getStatusCode());
		$this->assertContains('/Security/login', $actual->getHeader('Location'));
	}

	public function testProjectRoutes() {
		$this->logInWithPermission("ADMIN");

		$project = DNProject::create();
		$project->Name = 'amoeba';
		$project->write();

		$actual = $this->get('/naut/project/amoeba');
		$this->assertEquals(200, $actual->getStatusCode());

		$actual = $this->get('/naut/project/proteus');
		$this->assertEquals(404, $actual->getStatusCode());
	}

	public function testEnvironmentRoutes() {
		$this->logInWithPermission("ADMIN");

		$env = DNEnvironment::create();
		$env->Name = 'prod';
		$env->write();

		$project = DNProject::create();
		$project->Name = 'amoeba';
		$project->Environments()->add($env);
		$project->write();

		$actual = $this->get('/naut/project/amoeba/environment/prod');
		$this->assertEquals(200, $actual->getStatusCode());

		$actual = $this->get('/naut/project/amoeba/environment/uat');
		$this->assertEquals(404, $actual->getStatusCode());
	}

	public function testEnvironmentDeployRoutes() {
		$env = DNEnvironment::create();
		$env->Name = 'prod';
		$env->write();

		$project = DNProject::create();
		$project->Name = 'amoeba';
		$project->Environments()->add($env);
		$project->write();

		$actual = $this->get('/naut/project/amoeba/environment/prod/deploys/history');
		$this->assertEquals(302, $actual->getStatusCode());
		$this->assertContains('/Security/login', $actual->getHeader('Location'));

		$this->logInWithPermission("ADMIN");

		$actual = $this->get('/naut/project/amoeba/environment/prod/deploys/history');
		$this->assertEquals(200, $actual->getStatusCode());

		$actual = $this->get('/naut/project/amoeba/environment/uat/deploys/history');
		$this->assertEquals(404, $actual->getStatusCode());
	}


}
