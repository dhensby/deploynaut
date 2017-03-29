<?php

/**
 * Runs a deployment via the most appropriate backend
 */
class DeployJob extends DeploynautJob {

	/**
	 * @var array
	 */
	public $args;

	public function setUp() {
		$this->updateStatus(DNDeployment::TR_DEPLOY);
		chdir(BASE_PATH);
	}

	public function perform() {
		echo "[-] DeployJob starting" . PHP_EOL;
		$log = new DeploynautLogFile($this->args['logfile']);

		$deployment = DNDeployment::get()->byID($this->args['deploymentID']);
		$environment = $deployment->Environment();
		$currentBuild = $environment->CurrentBuild();
		$project = $environment->Project();
		$backupDataTransfer = null;
		$backupMode = !empty($this->args['backup_mode']) ? $this->args['backup_mode'] : 'db';

		// Perform pre-deploy backup here if required. Note that the backup is done here within
		// the deploy job, so that the order of backup is done before deployment, and so it
		// doesn't tie up another worker. It also puts the backup output into
		// the same log as the deployment so there is visibility on what is going on.
		// Note that the code has to be present for a backup to be performed, so the first
		// deploy onto a clean environment will not be performing any backup regardless of
		// whether the predeploy_backup option was passed or not.
		// Sometimes predeploy_backup comes through as string false from the frontend.
		if(
			!empty($this->args['predeploy_backup'])
			&& $this->args['predeploy_backup'] !== 'false'
			&& !empty($currentBuild)
		) {
			$backupDataTransfer = DNDataTransfer::create();
			$backupDataTransfer->EnvironmentID = $environment->ID;
			$backupDataTransfer->Direction = 'get';
			$backupDataTransfer->Mode = $backupMode;
			$backupDataTransfer->ResqueToken = $deployment->ResqueToken;
			$backupDataTransfer->AuthorID = $deployment->DeployerID;
			$backupDataTransfer->write();

			$deployment->BackupDataTransferID = $backupDataTransfer->ID;
			$deployment->write();
		}

		try {
			// Disallow concurrent deployments (don't rely on queuing implementation to restrict this)
			// Only consider deployments started in the last 30 minutes (older jobs probably got stuck)
			$runningDeployments = $environment->runningDeployments()->exclude('ID', $this->args['deploymentID']);
			if($runningDeployments->count()) {
				$runningDeployment = $runningDeployments->first();
				$message = sprintf(
					'Error: another deployment is in progress (started at %s by %s)',
					$runningDeployment->dbObject('Created')->Nice(),
					$runningDeployment->Deployer()->Title
				);
				$log->write($message);
				throw new \RuntimeException($message);
			}

			$this->performBackup($backupDataTransfer, $log);
			$environment->Backend()->deploy(
				$environment,
				$log,
				$project,
				// Pass all args to give the backend full visibility. These args also contain
				// all options from the DeploymentStrategy merged in, including sha.
				$this->args
			);
		} catch(Exception $e) {
			// DeploynautJob will automatically trigger onFailure.
			echo "[-] DeployJob failed" . PHP_EOL;
			throw $e;
		}

		$this->updateStatus(DNDeployment::TR_COMPLETE);
		echo "[-] DeployJob finished" . PHP_EOL;
	}

	public function onFailure(Exception $exception) {
		$this->updateStatus(DNDeployment::TR_FAIL);
	}

	protected function performBackup($backupDataTransfer, \DeploynautLogFile $log) {
		if (!$backupDataTransfer) {
			return false;
		}

		$log->write('Backing up existing data');
		try {
			$backupDataTransfer->Environment()->Backend()->dataTransfer($backupDataTransfer, $log);
			global $databaseConfig;
			DB::connect($databaseConfig);
			$backupDataTransfer->Status = 'Finished';
			$backupDataTransfer->write();
		} catch(Exception $e) {
			global $databaseConfig;
			DB::connect($databaseConfig);
			$backupDataTransfer->Status = 'Failed';
			$backupDataTransfer->write();
			throw $e;
		}
	}

	/**
	 * @param string $status Transition
	 * @global array $databaseConfig
	 */
	protected function updateStatus($status) {
		global $databaseConfig;
		DB::connect($databaseConfig);
		$deployment = DNDeployment::get()->byID($this->args['deploymentID']);
		$deployment->getMachine()->apply($status);
	}

	/**
	 * @return DNData
	 */
	protected function DNData() {
		return DNData::inst();
	}
}
