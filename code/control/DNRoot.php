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
		'environment',
		'createenvlog',
		'createenv',
		'getCreateEnvironmentForm'
	];

	/**
	 * URL handlers pretending that we have a deep URL structure.
	 */
	private static $url_handlers = [
		'project/$Project/environment/$Environment' => 'environment',
		'project/$Project/createenv/$Identifier/log' => 'createenvlog',
		'project/$Project/createenv/$Identifier' => 'createenv',
		'project/$Project/CreateEnvironmentForm' => 'getCreateEnvironmentForm',
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
	 * @return array
	 */
	public static function get_template_global_variables() {
		return [
			'PlatformTitle' => 'platform_title',
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
			// we cannot return from here (from init), we need to throw an exception and use
			// the permission response so that the request parsing stops here and users
			// gets redirected to the login page
			$permResponse = Security::permissionFailure();
			$this->httpError($permResponse->getStatusCode(), $permResponse);
		}

		// Block framework jquery
		Requirements::block(FRAMEWORK_DIR . '/thirdparty/jquery/jquery.js');

		self::include_requirements();
	}

	/**
	 * Override core handleAction method so that we can handle restful requests more elegantly
	 *
	 * @param SS_HTTPRequest $request
	 * @param string $action
	 *
	 * @return SS_HTTPResponse
	 */
	protected function handleAction($request, $action)
	{
		$this->extend('beforeCallActionHandler', $request, $action);

		foreach($request->latestParams() as $k => $v) {
			if($v || !isset($this->urlParams[$k])) $this->urlParams[$k] = $v;
		}

		$this->action = $action;
		$this->requestParams = $request->requestVars();
		$verb = strtolower($request->httpMethod());
		$actionMethod = strtolower(($verb === 'get' ? 'do' : $verb) . $action);
		if (!$this->hasMethod($actionMethod) && $this->hasMethod($action)) {
			$actionMethod = $action;
		}

		if ($this->hasAction($action) && !$this->hasMethod($actionMethod)) {
			$allowedVerbs = [];
			$actionLength = strlen($action);
			foreach (get_class_methods($this) as $method) {
				if (strtolower(substr($method, -$actionLength, $actionLength)) === $action) {
					$allowedVerb = strtolower(substr($method, 0, -$actionLength));
					if ($allowedVerb === 'do') {
						$allowedVerb = 'get';
					}
					if (in_array($allowedVerb, ['get', 'post', 'put', 'patch', 'delete', 'head'])) {
						$allowedVerbs[] = $allowedVerb;
					}
				}
			}
			$this->httpError(405, sprintf('%s requests are not allowed to this end point. Please try: %s',
				strtoupper($verb), strtoupper(implode(', ', $allowedVerbs))));
		}

		if($this->hasMethod($actionMethod)) {

			$res = $this->extend('beforeCallActionHandler', $request, $action);
			if ($res) {
				$result = reset($res);
			} else {

				$result = $this->$actionMethod($request);

				$res = $this->extend('afterCallActionHandler', $request, $action);
				if ($res) {
					$result = reset($res);
				}
			}

			// If the action returns an array, customise with it before rendering the template.
			if(is_array($result)) {
				return $this->getViewer($action)->process($this->customise($result));
			} else {
				return $result;
			}
		} else {
			return $this->getViewer($action)->process($this);
		}
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
	 * @return \SS_HTTPResponse
	 */
	public function environment() {
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

		return $this->redirect($env->DeploymentsLink());
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
			throw new LogicException("Project in URL doesn't match this environment creation");
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
			self::ALLOW_CREATE_ENVIRONMENT => [
				'name' => 'Ability to create environments',
				'category' => 'Deploynaut'
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

	/**
	 * @return SS_HTTPResponse
	 */
	protected function project404Response() {
		$this->httpError(404, "Project '" . Convert::raw2xml($this->getRequest()->param('Project')) . "' not found.");
	}

	/**
	 * @return SS_HTTPResponse
	 */
	protected function environment404Response() {
		$envName = Convert::raw2xml($this->getRequest()->param('Environment'));
		$this->httpError(404, "Environment '" . $envName . "' not found.");
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

