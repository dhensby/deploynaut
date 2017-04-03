<?php

class DNDataArchiveTest extends DeploynautTest {

	protected static $fixture_file = 'DNDataArchiveTest.yml';

	public function testGenerateFilePath() {
		// SS_Datetime::mock_now('2010-01-01 23:23:23');
		$project1 = $this->objFromFixture('DNProject', 'project1');
		$project1uatEnv = $this->objFromFixture('DNEnvironment', 'project1-uat');

		$dataTransfer = DNDataTransfer::create();
		$dataTransfer->Direction = 'get';
		$dataTransfer->Mode = 'all';
		$dataTransfer->write();

		$archive = DNDataArchive::create();
		$archive->OriginalEnvironmentID = $project1uatEnv->ID;
		$archive->write();

		$filepath1 = $archive->generateFilepath($dataTransfer);
		$this->assertNotNull($filepath1);
		$this->assertContains('project-1', $filepath1);
		$this->assertContains('uat', $filepath1);
		$this->assertContains('transfer-' . $dataTransfer->ID, $filepath1);
	}

	public function testGenerateFileName() {
		$project1 = $this->objFromFixture('DNProject', 'project1');
		$project1uatEnv = $this->objFromFixture('DNEnvironment', 'project1-uat');

		$dataTransfer = DNDataTransfer::create();
		$dataTransfer->Direction = 'get';
		$dataTransfer->Mode = 'all';
		$dataTransfer->write();

		$archive = DNDataArchive::create();
		$archive->OriginalEnvironmentID = $project1uatEnv->ID;
		$archive->write();

		$filename = $archive->generateFilename($dataTransfer);
		$this->assertNotNull($filename);
		$this->assertContains('project-1', $filename);
		$this->assertContains('uat', $filename);
		$this->assertContains('all', $filename);
	}

	public function testValidateArchiveContentsAll() {
		$archive = DNDataArchive::create();
		$archive->ArchiveFile()->Filename = __DIR__.'/sspaks/all.sspak';
		$result = $archive->validateArchiveContents('all');
		$this->assertTrue($result->valid());
	}

	public function testValidateArchiveContentsDB() {
		$archive = DNDataArchive::create();
		$archive->ArchiveFile()->Filename = __DIR__.'/sspaks/all.sspak';
		$result = $archive->validateArchiveContents('db');
		$this->assertTrue($result->valid());

		$archive->ArchiveFile()->Filename = __DIR__.'/sspaks/db.sspak';
		$result = $archive->validateArchiveContents('db');
		$this->assertTrue($result->valid());
	}

	public function testValidateArchiveContentsDBFails() {
		$archive = DNDataArchive::create();
		$archive->ArchiveFile()->Filename = __DIR__.'/sspaks/assets.sspak';
		$result = $archive->validateArchiveContents('db');
		$this->assertFalse($result->valid());
		$this->assertEquals('The snapshot is missing the database.', current($result->messageList()));
	}

	public function testValidateArchiveContentsAssets() {
		$archive = DNDataArchive::create();
		$archive->ArchiveFile()->Filename = __DIR__.'/sspaks/all.sspak';
		$result = $archive->validateArchiveContents('assets');
		$this->assertTrue($result->valid());

		$archive->ArchiveFile()->Filename = __DIR__.'/sspaks/assets.sspak';
		$result = $archive->validateArchiveContents('assets');
		$this->assertTrue($result->valid());
	}

	public function testValidateArchiveContentsAssetsFails() {
		$archive = DNDataArchive::create();
		$archive->ArchiveFile()->Filename = __DIR__.'/sspaks/db.sspak';
		$result = $archive->validateArchiveContents('assets');
		$this->assertFalse($result->valid());
		$this->assertEquals('The snapshot is missing assets.', current($result->messageList()));
	}

	public function testValidateArchiveContentsFileMissingFails() {
		$archive = DNDataArchive::create();
		$filename = __DIR__.'/sspaks/not.found.sspak';
		$archive->ArchiveFile()->Filename = $filename;
		$result = $archive->validateArchiveContents('all');
		$this->assertFalse($result->valid());
		$this->assertEquals('SSPak file "'.$filename.'" cannot be read.', current($result->messageList()));
	}
}
