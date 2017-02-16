<?php

class LetmeinJob extends \DeploynautJob {

	/**
	 * Takes:
	 * - logfile
	 * - environmentID
	 * - username
	 * - password
	 * @var array
	 */
	public $args;

	public function perform() {
		// ensure database is initialised, otherwise we get "MySQL server has gone away" errors
		global $databaseConfig;
		DB::connect($databaseConfig);

		echo '[-] LetmeinJob starting' . PHP_EOL;
		$log = new \DeploynautLogFile($this->args['logfile']);
		$environment = \DNEnvironment::get()->byID($this->args['environmentID']);

		try {
			$environment->Backend()->letmein($environment, $log, $this->args['username'], $this->args['password']);
		} catch (\Exception $e) {
			echo '[-] LetmeinJob failed' . PHP_EOL;
			throw $e;
		}

		echo '[-] LetmeinJob finished' . PHP_EOL;
	}

	protected function updateStatus($status) {
		// no-op.
	}

}
