<?php

/**
 * @property string $ResqueToken
 * @property string $EnvironmentID
 * @property string $RequesterID
 * @method \DNEnvironment Environment()
 * @method \Member Requester()
 */
class DNLetmein extends \DataObject {

	/**
	 * @var array
	 */
	private static $db = [
		'ResqueToken' => 'Varchar(255)',
	];

	/**
	 * @var array
	 */
	private static $has_one = [
		'Environment' => 'DNEnvironment',
		'Requester' => 'Member',
	];

	/**
	 * Return a path to the log file.
	 * @return string
	 */
	protected function logfile() {
		return sprintf(
			'%s.letmein.%s.log',
			$this->Environment()->getFullName('.'),
			$this->ID
		);
	}

	/**
	 * @return \DeploynautLogFile
	 */
	public function log() {
		return new \DeploynautLogFile($this->logfile());
	}

	/**
	 * @return string
	 */
	public function LogContent() {
		return $this->log()->content();
	}

	/**
	 * @return string
	 */
	public function ResqueStatus() {
		$status = new \Resque_Job_Status($this->ResqueToken);

		$remap = [
			\Resque_Job_Status::STATUS_WAITING => 'Queued',
			\Resque_Job_Status::STATUS_RUNNING => 'Running',
			\Resque_Job_Status::STATUS_FAILED => 'Failed',
			\Resque_Job_Status::STATUS_COMPLETE => 'Complete',
			false => 'Invalid',
		];

		return $remap[$status->get()];
	}

	/**
	 * @param string $username
	 * @param string $password
	 */
	public function enqueue($username, $password) {
		$environment = $this->Environment();
		$log = $this->log();

		$args = [
			'environmentID' => $environment->ID,
			'logfile' => $this->logfile(),
			'username' => $username,
			'password' => $password,
		];

		if (!$this->RequesterID) {
			$this->RequesterID = \Member::currentUserID();
		}

		if ($this->RequesterID) {
			$deployer = $this->Requester();
			$message = sprintf(
				'Temporary access request to %s initiated by %s (%s)',
				$environment->getFullName(),
				$deployer->getName(),
				$deployer->Email
			);
			$log->write($message);
		}

		$token = \Resque::enqueue('letmein', 'LetmeinJob', $args, true);
		$this->ResqueToken = $token;
		$this->write();

		$message = sprintf('Temporary access request queued as job %s', $token);
		$log->write($message);
	}

}
