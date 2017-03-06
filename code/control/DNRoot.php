<?php

/**
 * God controller for the deploynaut interface
 *
 * @package deploynaut
 * @subpackage control
 */
class DNRoot extends Controller implements PermissionProvider, TemplateGlobalProvider {

	/**
	 * @const string - action type for actions that perform deployments
	 */
	const ACTION_DEPLOY = 'deploy';

	const ACTION_ENVIRONMENTS = 'createenv';

	const PROJECT_OVERVIEW = 'overview';

	/**
	 * Allow advanced options on deployments
	 */
	const DEPLOYNAUT_ADVANCED_DEPLOY_OPTIONS = 'DEPLOYNAUT_ADVANCED_DEPLOY_OPTIONS';

	const ALLOW_PROD_DEPLOYMENT = 'ALLOW_PROD_DEPLOYMENT';

	const ALLOW_NON_PROD_DEPLOYMENT = 'ALLOW_NON_PROD_DEPLOYMENT';

	const ALLOW_CREATE_ENVIRONMENT = 'ALLOW_CREATE_ENVIRONMENT';

	/**
	 * @var array
	 */
	protected static $_project_cache = [];

	/**
	 * @var DNData
	 */
	protected $data;

	/**
	 * @var string
	 */
	private $actionType = self::ACTION_DEPLOY;

	/**
	 * @var array
	 */
	private static $allowed_actions = [
		'projects',
		'nav',
		'update',
		'project',
		'toggleprojectstar',
		'branch',
		'environment',
		'createenvlog',
		'createenv',
		'getDeployForm',
		'doDeploy',
		'deploy',
		'deploylog',
		'abortDeploy',
		'gitRevisions',
		'deploySummary',
		'startDeploy'
	];

	/**
	 * URL handlers pretending that we have a deep URL structure.
	 */
	private static $url_handlers = [
		'project/$Project/environment/$Environment/deploy_summary' => 'deploySummary',
		'project/$Project/environment/$Environment/git_revisions' => 'gitRevisions',
		'project/$Project/environment/$Environment/start-deploy' => 'startDeploy',
		'project/$Project/environment/$Environment/deploy/$Identifier/log' => 'deploylog',
		'project/$Project/environment/$Environment/deploy/$Identifier/abort-deploy' => 'abortDeploy',
		'project/$Project/environment/$Environment/deploy/$Identifier' => 'deploy',
		'project/$Project/environment/$Environment' => 'environment',
		'project/$Project/createenv/$Identifier/log' => 'createenvlog',
		'project/$Project/createenv/$Identifier' => 'createenv',
		'project/$Project/CreateEnvironmentForm' => 'getCreateEnvironmentForm',
		'project/$Project/branch' => 'branch',
		'project/$Project/build/$Build' => 'build',
		'project/$Project/update' => 'update',
		'project/$Project/star' => 'toggleprojectstar',
		'project/$Project' => 'project',
		'nav/$Project' => 'nav',
		'projects' => 'projects',
	];

	/**
	 * @var array
	 */
	private static $support_links = [];

	/**
	 * @var array
	 */
	private static $logged_in_links = [];

	/**
	 * @var array
	 */
	private static $platform_specific_strings = [];

	/**
	 * Include requirements that deploynaut needs, such as javascript.
	 */
	public static function include_requirements() {

		// JS should always go to the bottom, otherwise there's the risk that Requirements
		// puts them halfway through the page to the nearest <script> tag. We don't want that.
		Requirements::set_force_js_to_bottom(true);

		// todo these should be bundled into the same JS as the others in "static" below.
		// We've deliberately not used combined_files as it can mess with some of the JS used
		// here and cause sporadic errors.
		Requirements::javascript('deploynaut/javascript/jquery.js');
		Requirements::javascript('deploynaut/javascript/bootstrap.js');
		Requirements::javascript('deploynaut/javascript/q.js');
		Requirements::javascript('deploynaut/javascript/tablefilter.js');
		Requirements::javascript('deploynaut/javascript/deploynaut.js');

		Requirements::javascript('deploynaut/javascript/bootstrap.file-input.js');
		Requirements::javascript('deploynaut/thirdparty/select2/dist/js/select2.min.js');
		Requirements::javascript('deploynaut/javascript/selectize.js');
		Requirements::javascript('deploynaut/thirdparty/bootstrap-switch/dist/js/bootstrap-switch.min.js');
		Requirements::javascript('deploynaut/javascript/material.js');

		// Load the buildable dependencies only if not loaded centrally.
		if (!is_dir(BASE_PATH . DIRECTORY_SEPARATOR . 'static')) {
			if (\Director::isDev()) {
				\Requirements::javascript('deploynaut/static/bundle-debug.js');
			} else {
				\Requirements::javascript('deploynaut/static/bundle.js');
			}
		}

		// We need to include javascript here so that prerequisite js object(s) from
		// the deploynaut module have been loaded
		Requirements::javascript('static/platform.js');

		Requirements::css('deploynaut/static/style.css');
	}

	/**
	 * @return ArrayList
	 */
	public static function get_support_links() {
		$supportLinks = self::config()->support_links;
		if ($supportLinks) {
			return new ArrayList($supportLinks);
		}
	}

	/**
	 * @return ArrayList
	 */
	public static function get_logged_in_links() {
		$loggedInLinks = self::config()->logged_in_links;
		if ($loggedInLinks) {
			return new ArrayList($loggedInLinks);
		}
	}

	/**
	 * Return the platform title if configured, defaulting to "Deploynaut".
	 * @return string
	 */
	public static function platform_title() {
		if (defined('DEPLOYNAUT_PLATFORM_TITLE')) {
			return DEPLOYNAUT_PLATFORM_TITLE;
		}
		return 'Deploynaut';
	}

	/**
	 * Return the version number of this deploynaut install
	 */
	public static function app_version_number() {
		$basePath = BASE_PATH;
		if(is_dir("$basePath/.git")) {
			$CLI_git = escapeshellarg("$basePath/.git");
			return trim(`git --git-dir $CLI_git describe --tags HEAD`);

		} else if(file_exists("$basePath/.app-version-number")) {
			return trim(file_get_contents("$basePath/.app-version-number"));

		} else if(file_exists("$basePath/REVISION")) {
			return 'Version ' . substr(trim(file_get_contents("$basePath/REVISION")),0,7);

		} else {
			return "";
		}
	}

	/**
	 * @return array
	 */
	public static function get_template_global_variables() {
		return [
			'PlatformTitle' => 'platform_title',
			'AppVersionNumber' => 'app_version_number',
			'RedisUnavailable' => 'RedisUnavailable',
			'RedisWorkersCount' => 'RedisWorkersCount',
			'SidebarLinks' => 'SidebarLinks',
			'SupportLinks' => 'get_support_links',
			'LoggedInLinks' => 'get_logged_in_links',
		];
	}

	/**
	 */
	public function init() {
		parent::init();

		if (!Member::currentUser() && !Session::get('AutoLoginHash')) {
			return Security::permissionFailure();
		}

		// Block framework jquery
		Requirements::block(FRAMEWORK_DIR . '/thirdparty/jquery/jquery.js');

		self::include_requirements();
	}

	/**
	 * @return string
	 */
	public function Link() {
		return "naut/";
	}

	/**
	 * Actions
	 *
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function index(\SS_HTTPRequest $request) {
		return $this->redirect($this->Link() . 'projects/');
	}

	/**
	 * Action
	 *
	 * @param \SS_HTTPRequest $request
	 * @return string - HTML
	 */
	public function projects(\SS_HTTPRequest $request) {
		// Performs canView permission check by limiting visible projects in DNProjectsList() call.
		return $this->customise([
			'Title' => 'Projects',
		])->render();
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return HTMLText
	 */
	public function nav(\SS_HTTPRequest $request) {
		return $this->renderWith('Nav');
	}

	/**
	 * Return a link to the navigation template used for AJAX requests.
	 * @return string
	 */
	public function NavLink() {
		$currentProject = $this->getCurrentProject();
		$projectName = $currentProject ? $currentProject->Name : null;
		return Controller::join_links(Director::absoluteBaseURL(), 'naut', 'nav', $projectName);
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function project(\SS_HTTPRequest $request) {
		$this->setCurrentActionType(self::PROJECT_OVERVIEW);
		return $this->getCustomisedViewSection('ProjectOverview', '', ['IsAdmin' => Permission::check('ADMIN')]);
	}

	/**
	 * This action will star / unstar a project for the current member
	 *
	 * @param \SS_HTTPRequest $request
	 *
	 * @return SS_HTTPResponse
	 */
	public function toggleprojectstar(\SS_HTTPRequest $request) {
		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		$member = Member::currentUser();
		if ($member === null) {
			return $this->project404Response();
		}
		$favProject = $member->StarredProjects()
			->filter('DNProjectID', $project->ID)
			->first();

		if ($favProject) {
			$member->StarredProjects()->remove($favProject);
		} else {
			$member->StarredProjects()->add($project);
		}

		if (!$request->isAjax()) {
			return $this->redirectBack();
		}
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function branch(\SS_HTTPRequest $request) {
		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		$branchName = $request->getVar('name');
		$branch = $project->DNBranchList()->byName($branchName);
		if (!$branch) {
			return new SS_HTTPResponse("Branch '" . Convert::raw2xml($branchName) . "' not found.", 404);
		}

		return $this->render([
			'CurrentBranch' => $branch,
		]);
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function environment(\SS_HTTPRequest $request) {
		// Performs canView permission check by limiting visible projects
		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		// Performs canView permission check by limiting visible projects
		$env = $this->getCurrentEnvironment($project);
		if (!$env) {
			return $this->environment404Response();
		}

		return $this->render([
			'DNEnvironmentList' => $this->getCurrentProject()->DNEnvironmentList(),
			'Redeploy' => (bool) $request->getVar('redeploy')
		]);
	}

	/**
	 * Shows the creation log.
	 *
	 * @param \SS_HTTPRequest $request
	 * @return string
	 */
	public function createenv(\SS_HTTPRequest $request) {
		$params = $request->params();
		if ($params['Identifier']) {
			$record = DNCreateEnvironment::get()->byId($params['Identifier']);

			if (!$record || !$record->ID) {
				throw new SS_HTTPResponse_Exception('Create environment not found', 404);
			}
			if (!$record->canView()) {
				return Security::permissionFailure();
			}

			$project = $this->getCurrentProject();
			if (!$project) {
				return $this->project404Response();
			}

			if ($project->Name != $params['Project']) {
				throw new LogicException("Project in URL doesn't match this creation");
			}

			return $this->render([
				'CreateEnvironment' => $record,
			]);
		}
		return $this->render(['CurrentTitle' => 'Create an environment']);
	}

	public function createenvlog(\SS_HTTPRequest $request) {
		$params = $request->params();
		$env = DNCreateEnvironment::get()->byId($params['Identifier']);

		if (!$env || !$env->ID) {
			throw new SS_HTTPResponse_Exception('Log not found', 404);
		}
		if (!$env->canView()) {
			return Security::permissionFailure();
		}

		$project = $env->Project();

		if ($project->Name != $params['Project']) {
			throw new LogicException("Project in URL doesn't match this deploy");
		}

		$log = $env->log();
		if ($log->exists()) {
			$content = $log->content();
		} else {
			$content = 'Waiting for action to start';
		}

		return $this->sendResponse($env->ResqueStatus(), $content);
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return Form
	 */
	public function getCreateEnvironmentForm(\SS_HTTPRequest $request = null) {
		$this->setCurrentActionType(self::ACTION_ENVIRONMENTS);

		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		$envType = $project->AllowedEnvironmentType;
		if (!$envType || !class_exists($envType)) {
			return null;
		}

		$backend = Injector::inst()->get($envType);
		if (!($backend instanceof EnvironmentCreateBackend)) {
			// Only allow this for supported backends.
			return null;
		}

		$fields = $backend->getCreateEnvironmentFields($project);
		if (!$fields) {
			return null;
		}

		if (!$project->canCreateEnvironments()) {
			return new SS_HTTPResponse('Not allowed to create environments for this project', 401);
		}

		$form = Form::create(
			$this,
			'CreateEnvironmentForm',
			$fields,
			FieldList::create(
				FormAction::create('doCreateEnvironment', 'Create')
					->addExtraClass('btn')
			),
			$backend->getCreateEnvironmentValidator()
		);

		// Tweak the action so it plays well with our fake URL structure.
		$form->setFormAction($project->Link() . '/CreateEnvironmentForm');

		return $form;
	}

	/**
	 * @param array $data
	 * @param Form $form
	 *
	 * @return bool|HTMLText|SS_HTTPResponse
	 */
	public function doCreateEnvironment($data, Form $form) {
		$this->setCurrentActionType(self::ACTION_ENVIRONMENTS);

		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		if (!$project->canCreateEnvironments()) {
			return new SS_HTTPResponse('Not allowed to create environments for this project', 401);
		}

		// Set the environment type so we know what we're creating.
		$data['EnvironmentType'] = $project->AllowedEnvironmentType;

		$job = DNCreateEnvironment::create();

		$job->Data = serialize($data);
		$job->ProjectID = $project->ID;
		$job->write();
		$job->start();

		return $this->redirect($project->Link('createenv') . '/' . $job->ID);
	}

	/**
	 * Get the DNData object.
	 *
	 * @return DNData
	 */
	public function DNData() {
		return DNData::inst();
	}

	/**
	 * Provide a list of all projects.
	 *
	 * @return SS_List
	 */
	public function DNProjectList() {
		$memberId = Member::currentUserID();
		if (!$memberId) {
			return new ArrayList();
		}

		if (Permission::check('ADMIN')) {
			return DNProject::get();
		}

		$groups = Member::get()->filter('ID', $memberId)->relation('Groups');
		$projects = new ArrayList();
		if ($groups && $groups->exists()) {
			$projects = $groups->relation('Projects');
		}

		$this->extend('updateDNProjectList', $projects);
		return $projects;
	}

	/**
	 * @return ArrayList
	 */
	public function getPlatformSpecificStrings() {
		$strings = $this->config()->platform_specific_strings;
		if ($strings) {
			return new ArrayList($strings);
		}
	}

	/**
	 * Provide a list of all starred projects for the currently logged in member
	 *
	 * @return SS_List
	 */
	public function getStarredProjects() {
		$member = Member::currentUser();
		if ($member === null) {
			return new ArrayList();
		}

		$favProjects = $member->StarredProjects();

		$list = new ArrayList();
		foreach ($favProjects as $project) {
			if ($project->canView($member)) {
				$list->add($project);
			}
		}
		return $list;
	}

	/**
	 * Returns top level navigation of projects.
	 *
	 * @param int $limit
	 *
	 * @return ArrayList
	 */
	public function Navigation($limit = 5) {
		$navigation = new ArrayList();

		$currentProject = $this->getCurrentProject();
		$currentEnvironment = $this->getCurrentEnvironment();
		$actionType = $this->getCurrentActionType();

		$projects = $this->getStarredProjects();
		if ($projects->count() < 1) {
			$projects = $this->DNProjectList();
		} else {
			$limit = -1;
		}

		if ($projects->count() > 0) {
			$activeProject = false;

			if ($limit > 0) {
				$limitedProjects = $projects->limit($limit);
			} else {
				$limitedProjects = $projects;
			}

			foreach ($limitedProjects as $project) {
				$isActive = $currentProject && $currentProject->ID == $project->ID;
				if ($isActive) {
					$activeProject = true;
				}

				$isCurrentEnvironment = false;
				if ($project && $currentEnvironment) {
					$isCurrentEnvironment = (bool) $project->DNEnvironmentList()->find('ID', $currentEnvironment->ID);
				}

				$navigation->push([
					'Project' => $project,
					'IsCurrentEnvironment' => $isCurrentEnvironment,
					'IsActive' => $currentProject && $currentProject->ID == $project->ID,
					'IsOverview' => $actionType == self::PROJECT_OVERVIEW && !$isCurrentEnvironment && $currentProject->ID == $project->ID
				]);
			}

			// Ensure the current project is in the list
			if (!$activeProject && $currentProject) {
				$navigation->unshift([
					'Project' => $currentProject,
					'IsActive' => true,
					'IsCurrentEnvironment' => $currentEnvironment,
					'IsOverview' => $actionType == self::PROJECT_OVERVIEW && !$currentEnvironment
				]);
				if ($limit > 0 && $navigation->count() > $limit) {
					$navigation->pop();
				}
			}
		}

		return $navigation;
	}

	/**
	 * Construct the deployment form
	 *
	 * @deprecated 2.0.0 - moved to DeployDispatcher
	 *
	 * @return Form
	 */
	public function getDeployForm($request = null) {

		// Performs canView permission check by limiting visible projects
		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		// Performs canView permission check by limiting visible projects
		$environment = $this->getCurrentEnvironment($project);
		if (!$environment) {
			return $this->environment404Response();
		}

		if (!$environment->canDeploy()) {
			return new SS_HTTPResponse("Not allowed to deploy", 401);
		}

		// Generate the form
		$form = new DeployForm($this, 'DeployForm', $environment, $project);

		// If this is an ajax request we don't want to submit the form - we just want to retrieve the markup.
		if (
			$request &&
			!$request->requestVar('action_showDeploySummary') &&
			$this->getRequest()->isAjax() &&
			$this->getRequest()->isGET()
		) {
			// We can just use the URL we're accessing
			$form->setFormAction($this->getRequest()->getURL());

			$body = json_encode(['Content' => $form->forAjaxTemplate()->forTemplate()]);
			$this->getResponse()->addHeader('Content-Type', 'application/json');
			$this->getResponse()->setBody($body);
			return $body;
		}

		$form->setFormAction($this->getRequest()->getURL() . '/DeployForm');
		return $form;
	}

	/**
	 * @deprecated 2.0.0 - moved to GitDispatcher
	 *
	 * @param \SS_HTTPRequest $request
	 *
	 * @return SS_HTTPResponse|string
	 */
	public function gitRevisions(\SS_HTTPRequest $request) {

		// Performs canView permission check by limiting visible projects
		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		// Performs canView permission check by limiting visible projects
		$env = $this->getCurrentEnvironment($project);
		if (!$env) {
			return $this->environment404Response();
		}

		$options = [];
		foreach ($env->getSupportedOptions() as $option) {
			$options[] = [
				'name' => $option->getName(),
				'title' => $option->getTitle(),
				'defaultValue' => $option->getDefaultValue()
			];
		}

		$tabs = [];
		$id = 0;
		$data = [
			'id' => ++$id,
			'name' => 'Deploy the latest version of a branch',
			'field_type' => 'dropdown',
			'field_label' => 'Choose a branch',
			'field_id' => 'branch',
			'field_data' => [],
			'options' => $options
		];
		foreach ($project->DNBranchList() as $branch) {
			$sha = $branch->SHA();
			$name = $branch->Name();
			$branchValue = sprintf("%s (%s, %s old)",
				$name,
				substr($sha, 0, 8),
				$branch->LastUpdated()->TimeDiff()
			);
			$data['field_data'][] = [
				'id' => $sha,
				'text' => $branchValue,
				'branch_name' => $name // the raw branch name, not including the time etc
			];
		}
		$tabs[] = $data;

		$data = [
			'id' => ++$id,
			'name' => 'Deploy a tagged release',
			'field_type' => 'dropdown',
			'field_label' => 'Choose a tag',
			'field_id' => 'tag',
			'field_data' => [],
			'options' => $options
		];

		foreach ($project->DNTagList()->setLimit(null) as $tag) {
			$name = $tag->Name();
			$data['field_data'][] = [
				'id' => $tag->SHA(),
				'text' => sprintf("%s", $name)
			];
		}

		// show newest tags first.
		$data['field_data'] = array_reverse($data['field_data']);

		$tabs[] = $data;

		// Past deployments
		$data = [
			'id' => ++$id,
			'name' => 'Redeploy a release that was previously deployed (to any environment)',
			'field_type' => 'dropdown',
			'field_label' => 'Choose a previously deployed release',
			'field_id' => 'release',
			'field_data' => [],
			'options' => $options
		];
		// We are aiming at the format:
		// [{text: 'optgroup text', children: [{id: '<sha>', text: '<inner text>'}]}]
		$redeploy = [];
		foreach ($project->DNEnvironmentList() as $dnEnvironment) {
			$envName = $dnEnvironment->Name;
			$perEnvDeploys = [];

			foreach ($dnEnvironment->DeployHistory()->filter('State', \DNDeployment::STATE_COMPLETED) as $deploy) {
				$sha = $deploy->SHA;

				// Check if exists to make sure the newest deployment date is used.
				if (!isset($perEnvDeploys[$sha])) {
					$pastValue = sprintf("%s (deployed %s)",
						substr($sha, 0, 8),
						$deploy->obj('LastEdited')->Ago()
					);
					$perEnvDeploys[$sha] = [
						'id' => $sha,
						'text' => $pastValue
					];
				}
			}

			if (!empty($perEnvDeploys)) {
				$redeploy[$envName] = array_values($perEnvDeploys);
			}
		}
		// Convert the array to the frontend format (i.e. keyed to regular array)
		foreach ($redeploy as $name => $descr) {
			$data['field_data'][] = ['text' => $name, 'children' => $descr];
		}
		$tabs[] = $data;

		$data = [
			'id' => ++$id,
			'name' => 'Deploy a specific SHA',
			'field_type' => 'textfield',
			'field_label' => 'Choose a SHA',
			'field_id' => 'SHA',
			'field_data' => [],
			'options' => $options
		];
		$tabs[] = $data;

		// get the last time git fetch was run
		$lastFetched = 'never';
		$fetch = DNGitFetch::get()
			->filter([
				'ProjectID' => $project->ID,
				'Status' => 'Finished'
			])
			->sort('LastEdited', 'DESC')
			->first();
		if ($fetch) {
			$lastFetched = $fetch->dbObject('LastEdited')->Ago();
		}

		$data = [
			'Tabs' => $tabs,
			'last_fetched' => $lastFetched
		];

		$this->applyRedeploy($request, $data);

		return json_encode($data, JSON_PRETTY_PRINT);
	}

	/**
	 * @deprecated 2.0.0 - moved to DeployDispatcher
	 *
	 * @param \SS_HTTPRequest $request
	 *
	 * @return string
	 */
	public function deploySummary(\SS_HTTPRequest $request) {

		// Performs canView permission check by limiting visible projects
		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		// Performs canView permission check by limiting visible projects
		$environment = $this->getCurrentEnvironment($project);
		if (!$environment) {
			return $this->environment404Response();
		}

		// Plan the deployment.
		$strategy = $environment->getDeployStrategy($request);
		$data = $strategy->toArray();

		// Add in a URL for comparing from->to code changes. Ensure that we have
		// two proper 40 character SHAs, otherwise we can't show the compare link.
		$interface = $project->getRepositoryInterface();
		if (
			!empty($interface) && !empty($interface->URL)
			&& !empty($data['changes']['Code version']['from'])
			&& strlen($data['changes']['Code version']['from']) == '40'
			&& !empty($data['changes']['Code version']['to'])
			&& strlen($data['changes']['Code version']['to']) == '40'
		) {
			$compareurl = sprintf(
				'%s/compare/%s...%s',
				$interface->URL,
				$data['changes']['Code version']['from'],
				$data['changes']['Code version']['to']
			);
			$data['changes']['Code version']['compareUrl'] = $compareurl;
		}

		// Append json to response
		$token = SecurityToken::inst();
		$data['SecurityID'] = $token->getValue();

		$this->extend('updateDeploySummary', $data);

		return json_encode($data);
	}

	/**
	 * Deployment form submission handler.
	 *
	 * @deprecated 2.0.0 - moved to DeployDispatcher
	 *
	 * Initiate a DNDeployment record and redirect to it for status polling
	 *
	 * @param \SS_HTTPRequest $request
	 *
	 * @return SS_HTTPResponse
	 * @throws ValidationException
	 * @throws null
	 */
	public function startDeploy(\SS_HTTPRequest $request) {

		$token = SecurityToken::inst();

		// Ensure the submitted token has a value
		$submittedToken = $request->postVar(\Dispatcher::SECURITY_TOKEN_NAME);
		if (!$submittedToken) {
			return false;
		}
		// Do the actual check.
		$check = $token->check($submittedToken);
		// Ensure the CSRF Token is correct
		if (!$check) {
			// CSRF token didn't match
			return $this->httpError(400, 'Bad Request');
		}

		// Performs canView permission check by limiting visible projects
		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		// Performs canView permission check by limiting visible projects
		$environment = $this->getCurrentEnvironment($project);
		if (!$environment) {
			return $this->environment404Response();
		}

		// Initiate the deployment
		// The extension point should pass in: Project, Environment, SelectRelease, buildName
		$this->extend('doDeploy', $project, $environment, $buildName, $data);

		// Start the deployment based on the approved strategy.
		$strategy = new DeploymentStrategy($environment);
		$strategy->fromArray($request->requestVar('strategy'));
		$deployment = $strategy->createDeployment();
		// Bypass approval by going straight to Queued.
		$deployment->getMachine()->apply(DNDeployment::TR_QUEUE);

		return json_encode([
			'url' => Director::absoluteBaseURL() . $deployment->Link()
		], JSON_PRETTY_PRINT);
	}

	/**
	 * @deprecated 2.0.0 - moved to DeployDispatcher
	 *
	 * Action - Do the actual deploy
	 *
	 * @param \SS_HTTPRequest $request
	 *
	 * @return SS_HTTPResponse|string
	 * @throws SS_HTTPResponse_Exception
	 */
	public function deploy(\SS_HTTPRequest $request) {
		$params = $request->params();
		$deployment = DNDeployment::get()->byId($params['Identifier']);

		if (!$deployment || !$deployment->ID) {
			throw new SS_HTTPResponse_Exception('Deployment not found', 404);
		}
		if (!$deployment->canView()) {
			return Security::permissionFailure();
		}

		$environment = $deployment->Environment();
		$project = $environment->Project();

		if ($environment->Name != $params['Environment']) {
			throw new LogicException("Environment in URL doesn't match this deploy");
		}
		if ($project->Name != $params['Project']) {
			throw new LogicException("Project in URL doesn't match this deploy");
		}

		return $this->render([
			'Deployment' => $deployment,
		]);
	}

	/**
	 * @deprecated 2.0.0 - moved to DeployDispatcher
	 *
	 * Action - Get the latest deploy log
	 *
	 * @param \SS_HTTPRequest $request
	 *
	 * @return string
	 * @throws SS_HTTPResponse_Exception
	 */
	public function deploylog(\SS_HTTPRequest $request) {
		$params = $request->params();
		$deployment = DNDeployment::get()->byId($params['Identifier']);

		if (!$deployment || !$deployment->ID) {
			throw new SS_HTTPResponse_Exception('Deployment not found', 404);
		}
		if (!$deployment->canView()) {
			return Security::permissionFailure();
		}

		$environment = $deployment->Environment();
		$project = $environment->Project();

		if ($environment->Name != $params['Environment']) {
			throw new LogicException("Environment in URL doesn't match this deploy");
		}
		if ($project->Name != $params['Project']) {
			throw new LogicException("Project in URL doesn't match this deploy");
		}

		$log = $deployment->log();
		if ($log->exists()) {
			$content = $log->content();
		} else {
			$content = 'Waiting for action to start';
		}

		return $this->sendResponse($deployment->ResqueStatus(), $content);
	}

	/**
	 * @deprecated 2.0.0 - moved to DeployDispatcher
	 *
	 * @param \SS_HTTPRequest $request
	 *
	 * @return string
	 * @throws SS_HTTPResponse_Exception
	 */
	public function abortDeploy(\SS_HTTPRequest $request) {
		$params = $request->params();
		$deployment = DNDeployment::get()->byId($params['Identifier']);

		if (!$deployment || !$deployment->ID) {
			throw new SS_HTTPResponse_Exception('Deployment not found', 404);
		}
		if (!$deployment->canView()) {
			return Security::permissionFailure();
		}

		// For now restrict to ADMINs only.
		if (!Permission::check('ADMIN')) {
			return Security::permissionFailure();
		}

		$environment = $deployment->Environment();
		$project = $environment->Project();

		if ($environment->Name != $params['Environment']) {
			throw new LogicException("Environment in URL doesn't match this deploy");
		}
		if ($project->Name != $params['Project']) {
			throw new LogicException("Project in URL doesn't match this deploy");
		}

		if (!in_array($deployment->Status, ['Queued', 'Deploying', 'Aborting'])) {
			throw new LogicException(sprintf("Cannot abort from %s state.", $deployment->Status));
		}

		$deployment->getMachine()->apply(DNDeployment::TR_ABORT);

		return $this->sendResponse($deployment->ResqueStatus(), []);
	}

	/**
	 * Returns an error message if redis is unavailable
	 *
	 * @return string
	 */
	public static function RedisUnavailable() {
		try {
			Resque::queues();
		} catch (Exception $e) {
			return $e->getMessage();
		}
		return '';
	}

	/**
	 * Returns the number of connected Redis workers
	 *
	 * @return int
	 */
	public static function RedisWorkersCount() {
		return count(Resque_Worker::all());
	}

	/**
	 * @return array
	 */
	public function providePermissions() {
		return [
			self::DEPLOYNAUT_ADVANCED_DEPLOY_OPTIONS => [
				'name' => "Access to advanced deploy options",
				'category' => "Deploynaut",
			],

			// Permissions that are intended to be added to the roles.
			self::ALLOW_PROD_DEPLOYMENT => [
				'name' => "Ability to deploy to production environments",
				'category' => "Deploynaut",
			],
			self::ALLOW_NON_PROD_DEPLOYMENT => [
				'name' => "Ability to deploy to non-production environments",
				'category' => "Deploynaut",
			],
			self::ALLOW_CREATE_ENVIRONMENT => [
				'name' => "Ability to create environments",
				'category' => "Deploynaut",
			],
		];
	}

	/**
	 * @return DNProject|null
	 */
	public function getCurrentProject() {
		$projectName = trim($this->getRequest()->param('Project'));
		if (!$projectName) {
			return null;
		}
		if (empty(self::$_project_cache[$projectName])) {
			self::$_project_cache[$projectName] = $this->DNProjectList()->filter('Name', $projectName)->First();
		}
		return self::$_project_cache[$projectName];
	}

	/**
	 * @param \DNProject|null $project
	 * @return \DNEnvironment|null
	 */
	public function getCurrentEnvironment(\DNProject $project = null) {
		if ($this->getRequest()->param('Environment') === null) {
			return null;
		}
		if ($project === null) {
			$project = $this->getCurrentProject();
		}
		// project can still be null
		if ($project === null) {
			return null;
		}
		return $project->DNEnvironmentList()->filter('Name', $this->getRequest()->param('Environment'))->First();
	}

	/**
	 * This will return a const that indicates the class of action currently being performed
	 *
	 * Until DNRoot is de-godded, it does a bunch of different actions all in the same class.
	 * So we just have each action handler calll setCurrentActionType to define what sort of
	 * action it is.
	 *
	 * @return string - one of the consts representing actions.
	 */
	public function getCurrentActionType() {
		return $this->actionType;
	}

	/**
	 * Sets the current action type
	 *
	 * @param string $actionType string - one of the action consts
	 */
	public function setCurrentActionType($actionType) {
		$this->actionType = $actionType;
	}

	/**
	 * Returns a list of attempted environment creations.
	 *
	 * @return PaginatedList
	 */
	public function CreateEnvironmentList() {
		$project = $this->getCurrentProject();
		if ($project) {
			$dataList = $project->CreateEnvironments();
		} else {
			$dataList = new ArrayList();
		}

		$this->extend('updateCreateEnvironmentList', $dataList);
		return new PaginatedList($dataList->sort('Created DESC'), $this->request);
	}

	/**
	 * @deprecated 2.0.0 - moved to DeployDispatcher
	 *
	 * @return null|PaginatedList
	 */
	public function DeployHistory() {
		if ($env = $this->getCurrentEnvironment()) {
			$history = $env->DeployHistory();
			if ($history->count() > 0) {
				$pagination = new PaginatedList($history, $this->getRequest());
				$pagination->setPageLength(4);
				return $pagination;
			}
		}
		return null;
	}

	/**
	 * @param string $status
	 * @param string $content
	 *
	 * @return string
	 */
	public function sendResponse($status, $content) {
		// strip excessive newlines
		$content = preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\n", $content);

		$sendJSON = (strpos($this->getRequest()->getHeader('Accept'), 'application/json') !== false)
			|| $this->getRequest()->getExtension() == 'json';

		if (!$sendJSON) {
			$this->response->addHeader("Content-type", "text/plain");
			return $content;
		}
		$this->response->addHeader("Content-type", "application/json");
		return json_encode([
			'status' => $status,
			'content' => $content,
		]);
	}

	/**
	 * Get items for the ambient menu that should be accessible from all pages.
	 *
	 * @return ArrayList
	 */
	public function AmbientMenu() {
		$list = new ArrayList();

		if (Member::currentUserID()) {
			$list->push(new ArrayData([
				'Classes' => 'logout',
				'FaIcon' => 'sign-out',
				'Link' => 'Security/logout',
				'Title' => 'Log out',
				'IsCurrent' => false,
				'IsSection' => false
			]));
		}

		$this->extend('updateAmbientMenu', $list);
		return $list;
	}

	/**
	 * Checks whether the user can create a project.
	 *
	 * @return bool
	 */
	public function canCreateProjects($member = null) {
		if (!$member) {
			$member = Member::currentUser();
		}
		if (!$member) {
			return false;
		}

		return singleton('DNProject')->canCreate($member);
	}

	protected function applyRedeploy(\SS_HTTPRequest $request, &$data) {
		if (!$request->getVar('redeploy')) {
			return;
		}

		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}

		// Performs canView permission check by limiting visible projects
		$env = $this->getCurrentEnvironment($project);
		if (!$env) {
			return $this->environment404Response();
		}

		$current = $env->CurrentBuild();
		if ($current && $current->exists()) {
			$data['preselect_tab'] = 3;
			$data['preselect_sha'] = $current->SHA;
		} else {
			$master = $project->DNBranchList()->byName('master');
			if ($master) {
				$data['preselect_tab'] = 1;
				$data['preselect_sha'] = $master->SHA();
			}
		}
	}

	/**
	 * @return SS_HTTPResponse
	 */
	protected function project404Response() {
		return new SS_HTTPResponse(
			"Project '" . Convert::raw2xml($this->getRequest()->param('Project')) . "' not found.",
			404
		);
	}

	/**
	 * @return SS_HTTPResponse
	 */
	protected function environment404Response() {
		$envName = Convert::raw2xml($this->getRequest()->param('Environment'));
		return new SS_HTTPResponse("Environment '" . $envName . "' not found.", 404);
	}

	/**
	 * @param string $sectionName
	 * @param string $title
	 *
	 * @return SS_HTTPResponse
	 */
	protected function getCustomisedViewSection($sectionName, $title = '', $data = []) {
		// Performs canView permission check by limiting visible projects
		$project = $this->getCurrentProject();
		if (!$project) {
			return $this->project404Response();
		}
		$data[$sectionName] = 1;

		if ($this !== '') {
			$data['Title'] = $title;
		}

		return $this->render($data);
	}

}

