<div class="content page-header">
	<% include Breadcrumb %>

	<% if $CurrentProject %>
	<ul class="nav nav-tabs">
		<li><a href="$CurrentProject.Link('snapshots')">Overview</a></li>

		<% if $CurrentProject.canBackup %>
		<li class="active"><a href="$CurrentProject.Link('snapshots/createsnapshot')">Create snapshot</a></li>
		<% end_if %>
		<% if $CurrentProject.canUploadArchive %>
		<li><a href="$CurrentProject.Link('snapshots/upload')">Upload snapshot</a></li>
		<% end_if %>
		<li><a href="$CurrentProject.Link('snapshots/history')">History</a></li>
	</ul>
	<% end_if %>
</div>

<div class="content">
	<div class="row">
		<div class="col-md-8 col-md-offset-2">
			<h3>$Title</h3>

			<p>Back up the database and/or assets into a file and transfer it to $PlatformTitle. From there it can be downloaded or used for later restores</p>
			$DataTransferForm
		</div>
	</div>
</div>
