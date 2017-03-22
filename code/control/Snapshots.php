<?php

/**
 * Controller for the snapshot actions
 *
 * @package deploynaut
 * @subpackage control
 */
class Snapshots extends DNRoot {

	/**
	 * @const string - action type for actions that manipulate snapshots
	 */
	const ACTION_SNAPSHOT = 'snapshot';

	const ALLOW_PROD_SNAPSHOT = 'ALLOW_PROD_SNAPSHOT';

	const ALLOW_NON_PROD_SNAPSHOT = 'ALLOW_NON_PROD_SNAPSHOT';

	/**
	 * @var array
	 */
	private static $allowed_actions = [
		'transfer',
		'log',
		'createsnapshot',
		'deletesnapshot',
		'bulkdeletesnapshot',
		'restoresnapshot',
		'movesnapshot',
		'history',
		'upload',
		'PostForm',
		'UploadForm',
		'DataTransferForm',
		'DataTransferRestoreForm',
		'DeleteForm',
		'MoveForm',
		'postsuccess'
	];

	/**
	 * URL handlers pretending that we have a deep URL structure.
	 */
	private static $url_handlers = [
		'transfer/$ID/log' => 'log'
	];

	/**
	 * @var string
	 */
	private $actionType = self::ACTION_SNAPSHOT;

	/**
	 * @var \DNProject
	 */
	protected $project = null;

	public function init() {
		parent::init();

		$this->project = $this->getCurrentProject();
		if (!$this->project) {
			return $this->project404Response();
		}

		$this->setCurrentActionType(self::ACTION_SNAPSHOT);
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function index(\SS_HTTPRequest $request) {
		return $this->renderWith(['Snapshots', 'DNRoot']);
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function createsnapshot(\SS_HTTPRequest $request) {
		if (!$this->project->canBackup()) {
			return new \SS_HTTPResponse_Exception('Not allowed to create snapshots on any environments', 401);
		}

		return $this->customise([
			'Title' => 'Create snapshot',
		])->renderWith(['Snapshots_create', 'DNRoot']);
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function upload(\SS_HTTPRequest $request) {
		if (!$this->project->canUploadArchive()) {
			return new \SS_HTTPResponse_Exception('Not allowed to upload', 401);
		}

		return $this->customise([
			'Title' => 'Upload snapshot'
		])->renderWith(['Snapshots_upload', 'DNRoot']);
	}

	/**
	 * Return the upload limit for snapshot uploads
	 *
	 * @param bool $formatted Return formatted for viewing, e.g. 2MB (true) or return as bytes (false)
	 * @return string
	 */
	public function UploadLimit($formatted = true) {
		$min = min(\File::ini2bytes(ini_get('upload_max_filesize')), \File::ini2bytes(ini_get('post_max_size')));
		if ($formatted) {
			return \File::format_size($min);
		}
		return $min;
	}

	/**
	 * @return \Form
	 */
	public function UploadForm() {
		if (!$this->project->canUploadArchive()) {
			return new \SS_HTTPResponse_Exception('Not allowed to upload', 401);
		}

		// Framing an environment as a "group of people with download access"
		// makes more sense to the user here, while still allowing us to enforce
		// environment specific restrictions on downloading the file later on.
		$envs = $this->project->DNEnvironmentList()->filterByCallback(function($item) {
			return $item->canUploadArchive();
		});
		$envsMap = [];
		foreach ($envs as $env) {
			$envsMap[$env->ID] = $env->Name;
		}

		$fileField = \DataArchiveFileField::create('ArchiveFile', 'File');
		$fileField->getValidator()->setAllowedExtensions(['sspak']);
		$fileField->getValidator()->setAllowedMaxFileSize(['*' => $this->UploadLimit(false)]);

		$form = \Form::create(
			$this,
			'UploadForm',
			\FieldList::create(
				$fileField,
				\DropdownField::create('Mode', 'What does this file contain?', \DNDataArchive::get_mode_map()),
				\DropdownField::create('EnvironmentID', 'Initial ownership of the file', $envsMap)
					->setEmptyString('Select an environment')
			),
			\FieldList::create(
				\FormAction::create('doUpload', 'Upload File')
					->addExtraClass('btn')
			),
			\RequiredFields::create('ArchiveFile', 'EnvironmentID')
		);

		$form->addExtraClass('fields-wide');
		$form->setFormAction(\Controller::join_links($this->project->Link(), 'snapshots', 'UploadForm'));

		return $form;
	}

	/**
	 * @param array $data
	 * @param \Form $form
	 * @return \SS_HTTPResponse
	 */
	public function doUpload($data, \Form $form) {
		$this->validateSnapshotMode($data['Mode']);

		$dataArchive = \DNDataArchive::create([
			'AuthorID' => \Member::currentUserID(),
			'EnvironmentID' => $data['EnvironmentID'],
			'IsManualUpload' => true,
		]);
		$environment = $this->validateEnvironmentUploadTo($data);

		// needs an ID and transfer to determine upload path
		$dataArchive->write();
		$dataTransfer = \DNDataTransfer::create([
			'AuthorID' => \Member::currentUserID(),
			'Mode' => $data['Mode'],
			'Origin' => 'ManualUpload',
			'EnvironmentID' => $environment->ID
		]);
		$dataTransfer->write();
		$dataArchive->DataTransfers()->add($dataTransfer);
		$form->saveInto($dataArchive);
		$dataArchive->write();
		$workingDir = TEMP_FOLDER . DIRECTORY_SEPARATOR . 'deploynaut-transfer-' . $dataTransfer->ID;

		$cleanupFn = function() use($workingDir, $dataTransfer, $dataArchive) {
			$process = new \AbortableProcess(sprintf('rm -rf %s', escapeshellarg($workingDir)));
			$process->setTimeout(120);
			$process->run();
			$dataTransfer->delete();
			$dataArchive->delete();
		};

		// extract the sspak contents so we can inspect them
		try {
			$dataArchive->extractArchive($workingDir);
		} catch (\Exception $e) {
			$cleanupFn();
			$form->sessionMessage(
				'There was a problem trying to open your snapshot for processing. Please try uploading again',
				'bad'
			);
			return $this->redirectBack();
		}

		// validate that the sspak contents match the declared contents
		$result = $dataArchive->validateArchiveContents();
		if (!$result->valid()) {
			$cleanupFn();
			$form->sessionMessage($result->message(), 'bad');
			return $this->redirectBack();
		}

		// fix file permissions of extracted sspak files then re-build the sspak
		try {
			$dataArchive->fixArchivePermissions($workingDir);
			$dataArchive->setArchiveFromFiles($workingDir);
		} catch (Exception $e) {
			$cleanupFn();
			$form->sessionMessage(
				'There was a problem processing your snapshot. Please try uploading again',
				'bad'
			);
			return $this->redirectBack();
		}

		// cleanup any extracted sspak contents lying around
		$process = new \AbortableProcess(sprintf('rm -rf %s', escapeshellarg($workingDir)));
		$process->setTimeout(120);
		$process->run();

		return $this->customise([
			'DataArchive' => $dataArchive,
			'DataTransferRestoreForm' => $this->DataTransferRestoreForm($this->request, $dataArchive),
			'BackURL' => $this->project->Link('snapshots')
		])->renderWith(['Snapshots_upload', 'DNRoot']);
	}

	/**
	 * @return \Form
	 */
	public function PostForm() {
		if (!$this->project->canUploadArchive()) {
			return new \SS_HTTPResponse_Exception('Not allowed to upload', 401);
		}

		// Framing an environment as a "group of people with download access"
		// makes more sense to the user here, while still allowing us to enforce
		// environment specific restrictions on downloading the file later on.
		$envs = $this->project->DNEnvironmentList()->filterByCallback(function($item) {
			return $item->canUploadArchive();
		});
		$envsMap = [];
		foreach ($envs as $env) {
			$envsMap[$env->ID] = $env->Name;
		}

		$form = \Form::create(
			$this,
			'PostForm',
			\FieldList::create(
				\DropdownField::create('Mode', 'What does this file contain?', \DNDataArchive::get_mode_map()),
				\DropdownField::create('EnvironmentID', 'Initial ownership of the file', $envsMap)
					->setEmptyString('Select an environment')
			),
			\FieldList::create(
				\FormAction::create('doPostForm', 'Submit request')
					->addExtraClass('btn')
			),
			\RequiredFields::create('File')
		);

		$form->addExtraClass('fields-wide');
		$form->setFormAction(\Controller::join_links($this->project->Link(), 'snapshots', 'PostForm'));

		return $form;
	}

	/**
	 * @param array $data
	 * @param \Form $form
	 * @return \SS_HTTPResponse
	 */
	public function doPostForm($data, \Form $form) {
		$this->validateEnvironmentUploadTo($data);
		$dataArchive = \DNDataArchive::create([
			'UploadToken' => \DNDataArchive::generate_upload_token(),
		]);
		$form->saveInto($dataArchive);
		$dataArchive->write();

		return $this->redirect(\Controller::join_links(
			$this->project->Link(),
			'snapshots/postsuccess',
			$dataArchive->ID
		));
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function history(\SS_HTTPRequest $request) {
		return $this->customise([
			'Title' => 'Snapshots history',
		])->renderWith(['Snapshots_history', 'DNRoot']);
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 * @throws \SS_HTTPResponse_Exception
	 */
	public function postsuccess(\SS_HTTPRequest $request) {
		if (!$this->project->canUploadArchive()) {
			throw new \SS_HTTPResponse_Exception('Not allowed to upload', 401);
		}

		$dataArchive = DNDataArchive::get()->byId($request->param('ID'));
		if (!$dataArchive) {
			throw new \SS_HTTPResponse_Exception('Archive not found', 404);
		}

		if (!$dataArchive->canRestore()) {
			throw new \SS_HTTPResponse_Exception('Not allowed to restore archive', 403);
		}

		return $this->customise([
			'Title' => 'How to send us your snapshot by post',
			'DataArchive' => $dataArchive,
			'Address' => Config::inst()->get('Deploynaut', 'snapshot_post_address'),
			'BackURL' => $this->project->Link(),
		])->renderWith(['Snapshots_postsuccess', 'DNRoot']);
	}

	/**
	 * @return \Form
	 */
	public function DataTransferForm() {
		$envs = $this->project->DNEnvironmentList()->filterByCallback(function ($item) {
			return $item->canBackup();
		});

		if (!$envs) {
			return $this->environment404Response();
		}

		$items = [];
		$disabledEnvironments = [];
		foreach($envs as $env) {
			$items[$env->ID] = $env->Title;
			if ($env->CurrentBuild() === false) {
				$items[$env->ID] = sprintf('%s - requires initial deployment', $env->Title);
				$disabledEnvironments[] = $env->ID;
			}
		}

		$envsField = \DropdownField::create('EnvironmentID', 'Environment', $items)
			->setEmptyString('Select an environment');
		$envsField->setDisabledItems($disabledEnvironments);

		$formAction = \FormAction::create('doDataTransfer', 'Create')
			->addExtraClass('btn');

		if (count($disabledEnvironments) === $envs->count()) {
			$formAction->setDisabled(true);
		}

		// Allow the _resampled dir to be included if we are a Rainforest Env
		if ($this->project->DNEnvironmentList()->first() instanceof \RainforestEnvironment) {
			$fields = \FieldList::create(
				\HiddenField::create('Direction', null, 'get'),
				$envsField,
				\DropdownField::create('Mode', 'Transfer', \DNDataArchive::get_mode_map()),
				\CheckboxField::create('IncludeResampled', 'Include Resampled Images Directory? (e.g. for total content migration)')
			);
		} else {
			$fields = \FieldList::create(
				\HiddenField::create('Direction', null, 'get'),
				$envsField,
				\DropdownField::create('Mode', 'Transfer', \DNDataArchive::get_mode_map())
			);
		}

		$form = \Form::create(
			$this,
			'DataTransferForm',
			$fields,
			\FieldList::create($formAction)
		);
		$form->setFormAction(\Controller::join_links($this->project->Link(), 'snapshots', 'DataTransferForm'));

		return $form;
	}

	/**
	 * @param array $data
	 * @return \SS_HTTPResponse
	 * @throws \SS_HTTPResponse_Exception
	 */
	public function doDataTransfer($data) {
		$dataArchive = null;

		// Validate direction.
		if ($data['Direction'] == 'get') {
			$validEnvs = $this->project->DNEnvironmentList()
				->filterByCallback(function($item) {
					return $item->canBackup();
				});
		} else if ($data['Direction'] == 'push') {
			$validEnvs = $this->project->DNEnvironmentList()
				->filterByCallback(function($item) {
					return $item->canRestore();
				});
		} else {
			throw new \SS_HTTPResponse_Exception('Invalid direction', 400);
		}

		$environment = $validEnvs->find('ID', $data['EnvironmentID']);
		if (!$environment) {
			throw new \SS_HTTPResponse_Exception('Invalid environment');
		}
		$this->validateSnapshotMode($data['Mode']);

		// Only 'push' direction is allowed an association with an existing archive.
		if (
			$data['Direction'] == 'push'
			&& isset($data['ID'])
			&& is_numeric($data['ID'])
		) {
			$dataArchive = \DNDataArchive::get()->byId($data['ID']);
			if (!$dataArchive) {
				throw new \SS_HTTPResponse_Exception('Invalid data archive', 400);
			}

			if (!$dataArchive->canDownload()) {
				throw new \SS_HTTPResponse_Exception('Not allowed to access archive', 403);
			}
		}

		$transfer = \DNDataTransfer::create([
			'Direction' => $data['Direction'],
			'Mode' => $data['Mode'],
			'DataArchiveID' => $dataArchive ? $dataArchive->ID : null,
			'EnvironmentID' => $environment->ID
		]);

		if (isset($data['IncludeResampled'])) {
			$transfer->IncludeResampled = $data['IncludeResampled'];
		}
		if ($data['Direction'] == 'push') {
			$transfer->setBackupBeforePush(!empty($data['BackupBeforePush']));
		}
		$transfer->write();
		$transfer->start();

		return $this->redirect($transfer->Link());
	}

	/**
	 * View into the log for a {@link DNDataTransfer}.
	 *
	 * @param \SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 * @throws \SS_HTTPResponse_Exception
	 */
	public function transfer(\SS_HTTPRequest $request) {
		$params = $request->params();
		$transfer = \DNDataTransfer::get()->byId($params['ID']);

		if (!$transfer || !$transfer->ID) {
			throw new \SS_HTTPResponse_Exception('Transfer not found', 404);
		}
		if (!$transfer->canView()) {
			return \Security::permissionFailure();
		}

		$environment = $transfer->Environment();
		$project = $environment->Project();

		if ($project->Name != $params['Project']) {
			throw new \SS_HTTPResponse_Exception('Project in URL doesn\'t match this snapshot', 400);
		}

		return $this->customise([
			'CurrentTransfer' => $transfer,
		])->renderWith(['Snapshots_transfer', 'DNRoot']);
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @return string
	 * @throws \SS_HTTPResponse_Exception
	 */
	public function log(\SS_HTTPRequest $request) {
		$params = $request->params();
		$transfer = \DNDataTransfer::get()->byId($params['ID']);

		if (!$transfer || !$transfer->ID) {
			throw new \SS_HTTPResponse_Exception('Transfer not found', 404);
		}
		if (!$transfer->canView()) {
			return \Security::permissionFailure();
		}

		$environment = $transfer->Environment();
		$project = $environment->Project();

		if ($project->Name != $params['Project']) {
			throw new \SS_HTTPResponse_Exception('Project in URL doesn\'t match this snapshot', 400);
		}

		$log = $transfer->log();
		if ($log->exists()) {
			$content = $log->content();
		} else {
			$content = 'Waiting for action to start';
		}

		return $this->sendResponse($transfer->ResqueStatus(), $content);
	}

	/**
	 * Note: Submits to the same action as {@link DataTransferForm()},
	 * but with a Direction=push and an archive reference.
	 *
	 * @param \SS_HTTPRequest $request
	 * @param \DNDataArchive|null $dataArchive Only set when method is called manually in {@link restore()},
	 *                            otherwise the state is inferred from the request data.
	 * @return \Form
	 */
	public function DataTransferRestoreForm(\SS_HTTPRequest $request, \DNDataArchive $dataArchive = null) {
		$dataArchive = $dataArchive ? $dataArchive : \DNDataArchive::get()->byId($request->requestVar('ID'));

		$envs = $this->project->DNEnvironmentList()->filterByCallback(function ($item) {
			return $item->canRestore();
		});

		if (!$envs) {
			return $this->environment404Response();
		}

		$modesMap = [];
		if (in_array($dataArchive->Mode, ['all'])) {
			$modesMap['all'] = 'Database and Assets';
		};
		if (in_array($dataArchive->Mode, ['all', 'db'])) {
			$modesMap['db'] = 'Database only';
		};
		if (in_array($dataArchive->Mode, ['all', 'assets'])) {
			$modesMap['assets'] = 'Assets only';
		};

		$alertMessage = '<div class="alert alert-warning"><strong>Warning:</strong> '
			. 'This restore will overwrite the data on the chosen environment below</div>';


		$items = [];
		$disabledEnvironments = [];
		foreach($envs as $env) {
			$items[$env->ID] = $env->Title;
			if ($env->CurrentBuild() === false) {
				$items[$env->ID] = sprintf("%s - requires initial deployment", $env->Title);
				$disabledEnvironments[] = $env->ID;
			}
		}

		$envsField = \DropdownField::create('EnvironmentID', 'Environment', $items)
			->setEmptyString('Select an environment');
		$envsField->setDisabledItems($disabledEnvironments);
		$formAction = \FormAction::create('doDataTransfer', 'Restore Data')->addExtraClass('btn');

		if (count($disabledEnvironments) == $envs->count()) {
			$formAction->setDisabled(true);
		}

		$form = \Form::create(
			$this,
			'DataTransferRestoreForm',
			\FieldList::create(
				\HiddenField::create('ID', null, $dataArchive->ID),
				\HiddenField::create('Direction', null, 'push'),
				\HiddenField::create('snapshotAction', null, 'restoresnapshot'),
				\LiteralField::create('Warning', $alertMessage),
				$envsField,
				\DropdownField::create('Mode', 'Transfer', $modesMap),
				\CheckboxField::create('BackupBeforePush', 'Backup existing data', '1')
			),
			\FieldList::create($formAction)
		);
		$form->setFormAction(\Controller::join_links($this->project->Link(), 'snapshots', 'DataTransferRestoreForm'));

		return $form;
	}

	/**
	 * View a form to restore a specific {@link DataArchive}.
	 * Permission checks are handled in {@link DataArchives()}.
	 * Submissions are handled through {@link doDataTransfer()}, same as backup operations.
	 *
	 * @param \SS_HTTPRequest $request
	 * @return \HTMLText
	 * @throws \SS_HTTPResponse_Exception
	 */
	public function restoresnapshot(\SS_HTTPRequest $request) {
		$dataArchive = \DNDataArchive::get()->byId($request->param('ID'));
		if (!$dataArchive) {
			throw new \SS_HTTPResponse_Exception('Archive not found', 404);
		}

		// We check for canDownload because that implies access to the data.
		// canRestore is later checked on the actual restore action per environment.
		if (!$dataArchive->canDownload()) {
			throw new \SS_HTTPResponse_Exception('Not allowed to access archive', 403);
		}

		$form = $this->DataTransferRestoreForm($this->request, $dataArchive);

		// View currently only available via ajax
		return $form->forTemplate();
	}

	/**
	 * View a form to delete a specific {@link DataArchive}.
	 * Or delete multiple {@link DataArchives()}.
	 * Permission checks are handled in {@link DataArchives()}.
	 * Submissions are handled through {@link DataArchive()->delete()()}.
	 *
	 * @param \SS_HTTPRequest $request
	 * @return \HTMLText
	 * @throws \SS_HTTPResponse_Exception
	 */
	public function deletesnapshot(\SS_HTTPRequest $request) {
		$dataArchive = \DNDataArchive::get()->byId($request->param('ID'));
		$this->processArchive($dataArchive);
		$form = $this->DeleteForm($this->request, $dataArchive);
		return $form->forTemplate();
	}

	/**
	 * @param \SS_HTTPRequest $request
	 */
	public function bulkdeletesnapshot(\SS_HTTPRequest $request) {
		return $this->doDelete($request->postVars());
	}

	/**
	 * Perform sanity checks on requested IDs to be deleted.
	 *
	 * @param \DNDataArchive $dataArchive
	 * @throws \SS_HTTPResponse_Exception
	 */
	public function processArchive($dataArchive) {
		if (!$dataArchive) {
			throw new \SS_HTTPResponse_Exception('Archive not found', 404);
		}

		if (!$dataArchive->canDelete()) {
			throw new \SS_HTTPResponse_Exception('Not allowed to delete archive', 403);
		}
	}

	/**
	 * @param array $data
	 */
	public function doDelete($data) {
		$snapshotIDs = !empty($data['ID']) ? $data['ID'] : null;
		if (!$snapshotIDs) {
			throw new \SS_HTTPResponse_Exception('No snapshot IDs received', 400);
		}

		if (!is_array($snapshotIDs)) {
			$snapshotIDs = [$snapshotIDs];
		}

		foreach ($snapshotIDs as $id) {
			$dataArchive = \DNDataArchive::get()->byId($id);
			$this->processArchive($dataArchive);
			$dataArchive->delete();
		}
		return $this->redirectBack();
	}

	/**
	 * @param \SS_HTTPRequest $request
	 * @param \DNDataArchive|null $dataArchive Only set when method is called manually, otherwise the state is inferred
	 *        from the request data.
	 * @return \Form
	 */
	public function DeleteForm(\SS_HTTPRequest $request, \DNDataArchive $dataArchive = null) {
		$dataArchive = $dataArchive ? $dataArchive : \DNDataArchive::get()->byId($request->requestVar('ID'));

		$snapshotDeleteWarning = '<div class="alert alert-warning">'
			. 'Are you sure you want to permanently delete this snapshot from this archive area?'
			. '</div>';

		$form = \Form::create(
			$this,
			'DeleteForm',
			\FieldList::create(
				\HiddenField::create('ID', null, $dataArchive->ID),
				\HiddenField::create('snapshotAction', null, 'individualDelete'),
				\LiteralField::create('Warning', $snapshotDeleteWarning)
			),
			\FieldList::create(
				\FormAction::create('doDelete', 'Delete')
					->addExtraClass('btn')
			)
		);
		$form->setFormAction(\Controller::join_links($this->project->Link(), 'snapshots', 'DeleteForm'));

		return $form;
	}

	/**
	 * View a form to move a specific {@link DataArchive}.
	 *
	 * @param \SS_HTTPRequest $request
	 * @return \HTMLText
	 * @throws \SS_HTTPResponse_Exception
	 */
	public function movesnapshot(\SS_HTTPRequest $request) {
		$dataArchive = \DNDataArchive::get()->byId($request->param('ID'));
		if (!$dataArchive) {
			throw new \SS_HTTPResponse_Exception('Archive not found', 404);
		}

		// We check for canDownload because that implies access to the data.
		if (!$dataArchive->canDownload()) {
			throw new \SS_HTTPResponse_Exception('Not allowed to access archive', 403);
		}

		$form = $this->MoveForm($this->request, $dataArchive);

		// View currently only available via ajax
		return $form->forTemplate();
	}

	/**
	 * Build snapshot move form.
	 *
	 * @param \SS_HTTPRequest $request
	 * @param \DNDataArchive|null $dataArchive
	 * @return \Form
	 */
	public function MoveForm(\SS_HTTPRequest $request, \DNDataArchive $dataArchive = null) {
		$dataArchive = $dataArchive ? $dataArchive : \DNDataArchive::get()->byId($request->requestVar('ID'));

		$envs = $dataArchive->validTargetEnvironments();
		if (!$envs) {
			return $this->environment404Response();
		}

		$warningMessage = '<div class="alert alert-warning"><strong>Warning:</strong> This will make the snapshot '
			. 'available to people with access to the target environment.<br>By pressing "Change ownership" you '
			. 'confirm that you have considered data confidentiality regulations.</div>';

		$form = \Form::create(
			$this,
			'MoveForm',
			\FieldList::create(
				\HiddenField::create('ID', null, $dataArchive->ID),
				\HiddenField::create('snapshotAction', null, 'move'),
				\LiteralField::create('Warning', $warningMessage),
				\DropdownField::create('EnvironmentID', 'Environment', $envs->map())
					->setEmptyString('Select an environment')
			),
			\FieldList::create(
				\FormAction::create('doMove', 'Change ownership')
					->addExtraClass('btn')
			)
		);
		$form->setFormAction(\Controller::join_links($this->project->Link(), 'snapshots', 'MoveForm'));

		return $form;
	}

	/**
	 * @param array $data
	 * @return \SS_HTTPResponse
	 * @throws \SS_HTTPResponse_Exception
	 * @throws \LogicException
	 */
	public function doMove($data) {
		$dataArchive = \DNDataArchive::get()->byId($data['ID']);
		if (!$dataArchive) {
			throw new LogicException('Invalid data archive');
		}

		// We check for canDownload because that implies access to the data.
		if (!$dataArchive->canDownload()) {
			throw new \SS_HTTPResponse_Exception('Not allowed to access archive', 403);
		}

		$validEnvs = $dataArchive->validTargetEnvironments();
		$environment = $validEnvs->find('ID', $data['EnvironmentID']);
		if (!$environment) {
			throw new \SS_HTTPResponse_Exception('Invalid environment');
		}
		$dataArchive->EnvironmentID = $environment->ID;
		$dataArchive->write();

		return $this->redirectBack();
	}

	/**
	 * @param array $data
	 * @return \DNEnvironment
	 * @throws \SS_HTTPResponse_Exception
	 */
	protected function validateEnvironmentUploadTo($data) {
		$validEnvs = $this->project->DNEnvironmentList()->filterByCallback(function($item) {
			return $item->canUploadArchive();
		});
		$environment = $validEnvs->find('ID', $data['EnvironmentID']);
		if (!$environment) {
			throw new \SS_HTTPResponse_Exception('Invalid environment', 400);
		}
		return $environment;
	}

	/**
	 * Helper method to allow templates to know whether they should show the 'Archive List' include or not.
	 * The actual permissions are set on a per-environment level, so we need to find out if this $member can upload to
	 * or download from *any* {@link DNEnvironment} that (s)he has access to.
	 *
	 * TODO To be replaced with a method that just returns the list of archives this {@link Member} has access to.
	 *
	 * @param \Member|null $member The {@link Member} to check (or null to check the currently logged in Member)
	 * @return bool|null true if $member has access to upload or download to at least one {@link DNEnvironment}.
	 */
	public function CanViewArchives(\Member $member = null) {
		if ($member === null) {
			$member = \Member::currentUser();
		}
		if (\Permission::checkMember($member, 'ADMIN')) {
			return true;
		}

		$allProjects = $this->DNProjectList();
		if (!$allProjects) {
			return false;
		}

		foreach ($allProjects as $project) {
			if ($project->Environments()) {
				foreach ($project->Environments() as $environment) {
					if (
						$environment->canRestore($member) ||
						$environment->canBackup($member) ||
						$environment->canUploadArchive($member) ||
						$environment->canDownloadArchive($member)
					) {
						// We can return early as we only need to know that we can access one environment
						return true;
					}
				}
			}
		}
	}

	/**
	 * Returns a list of all archive files that can be accessed by the currently logged-in {@link Member}
	 * @return \PaginatedList
	 */
	public function CompleteDataArchives() {
		$archives = new \ArrayList();

		$archiveList = $this->project->Environments()->relation('DataArchives');
		if ($archiveList->count() > 0) {
			foreach ($archiveList as $archive) {
				if (!$archive->isPending()) {
					$archives->push($archive);
				}
			}
		}
		return new \PaginatedList($archives->sort('Created', 'DESC'), $this->getRequest());
	}

	/**
	 * @return \PaginatedList The list of "pending" data archives which are waiting for a file
	 * to be delivered offline by post, and manually uploaded into the system.
	 */
	public function PendingDataArchives() {
		$archives = new \ArrayList();
		foreach ($this->project->DNEnvironmentList() as $env) {
			foreach ($env->DataArchives() as $archive) {
				if ($archive->isPending()) {
					$archives->push($archive);
				}
			}
		}
		return new \PaginatedList($archives->sort("Created", "DESC"), $this->request);
	}

	/**
	 * @return \PaginatedList
	 */
	public function DataTransferLogs() {
		$environments = $this->project->Environments()->column('ID');
		$transfers = \DNDataTransfer::get()
			->filter('EnvironmentID', $environments)
			->filterByCallback(
				function($record) {
					return
						$record->Environment()->canRestore() || // Ensure member can perform an action on the transfers env
						$record->Environment()->canBackup() ||
						$record->Environment()->canUploadArchive() ||
						$record->Environment()->canDownloadArchive();
				});

		return new \PaginatedList($transfers->sort("Created", "DESC"), $this->request);
	}

	/**
	 * Validate the snapshot mode
	 * @param string $mode
	 * @throws \SS_HTTPResponse_Exception
	 */
	protected function validateSnapshotMode($mode) {
		if (!in_array($mode, ['all', 'assets', 'db'])) {
			throw new \SS_HTTPResponse_Exception('Invalid mode', 400);
		}
	}

	/**
	 * @return array
	 */
	public function providePermissions() {
		return [
			self::ALLOW_PROD_SNAPSHOT => [
				'name' => 'Ability to make production snapshots',
				'category' => 'Deploynaut'
			],
			self::ALLOW_NON_PROD_SNAPSHOT => [
				'name' => 'Ability to make non-production snapshots',
				'category' => 'Deploynaut'
			]
		];
	}

}
