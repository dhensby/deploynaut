<?php

class LetmeinDispatcher extends \Dispatcher implements \PermissionProvider {

	const ACTION_LETMEIN = 'letmein';

	const ALLOW_LETMEIN = 'ALLOW_LETMEIN';

	/**
	 * @var array
	 */
	private static $allowed_actions = [
		'request'
	];

	/**
	 * @var \DNProject
	 */
	protected $project = null;

	/**
	 * @var \DNEnvironment
	 */
	protected $environment = null;

	public function init() {
		parent::init();

		$this->project = $this->getCurrentProject();
		if (!$this->project) {
			return $this->project404Response();
		}

		// Performs canView permission check by limiting visible projects
		$this->environment = $this->getCurrentEnvironment($this->project);
		if (!$this->environment) {
			return $this->environment404Response();
		}

		if (!$this->project->allowed(self::ALLOW_LETMEIN)) {
			return \Security::permissionFailure();
		}

		$this->setCurrentActionType(self::ACTION_LETMEIN);
	}

	/**
	 * @return string
	 */
	public function Link() {
		return \Controller::join_links($this->environment->Link(), 'letmein');
	}

	/**
	 * @param \SS_HTTPRequest $request
	 *
	 * @return \HTMLText
	 */
	public function index(\SS_HTTPRequest $request) {
		return $this->renderWith(['Letmein', 'DNRoot']);
	}

	/**
	 * @param string $name
	 *
	 * @return array
	 */
	public function getModel($name) {
		return [
			'dispatchers' => [
				'letmein' => \Director::absoluteBaseURL() . $this->Link()
			],
			'api_auth' => [
				'name' => $this->getSecurityToken()->getName(),
				'value' => $this->getSecurityToken()->getValue()
			],
		];
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return SS_HTTPResponse
	 */
	public function request(\SS_HTTPRequest $request) {
		$lib = new \PasswordLib\PasswordLib;
		$token = $lib->getRandomToken(16);
		// generate a token, replace characters that could cause us problems
		// when sending a message to the instance
		$token = str_replace(['/', '@', '"', '\'', ' ', '.', ','], 'a', $token);

		switch ($request->httpMethod()) {
			case 'POST':
				try {
					$member = \Member::currentUser();
					if (!$member->Username) {
						throw new \Exception('You must have a username to request CMS access');
					}

					$letmein = \DNLetmein::create();
					$letmein->EnvironmentID = $this->environment->ID;
					$letmein->write();
					$letmein->enqueue($member->Username, $token);

					return $this->getAPIResponse([
						'id' => $letmein->ID,
						'username' => $member->Username,
						'password' => $token,
						'message' => 'CMS access request sent'
					], 201);
				} catch (\Exception $e) {
					return $this->getAPIResponse([
						'message' => $e->getMessage()
					], 400);
				}
			case 'GET':
				return $this->getRequestStatus($request->param('ID'));
			default:
				return $this->getAPIResponse(['message' => 'Method not allowed, requires POST or GET/{id}'], 405);
		}
	}

	/**
	 * @param string $id
	 * @return SS_HTTPResponse
	 */
	protected function getRequestStatus($id) {
		$record = \DNLetmein::get()->byID($id);
		if (!$record || !$record->exists()) {
			return $this->getAPIResponse(['message' => sprintf('CMS access request (%s) not found', $id)], 404);
		}

		$resqueStatus = $record->ResqueStatus();
		if ($resqueStatus==='Failed') {
			return $this->getAPIResponse(['message' => 'job has failed'], 400);
		}

		$output = [
			'id' => $id,
			'status' => $resqueStatus
		];
		return $this->getAPIResponse($output, 200);
	}
}
