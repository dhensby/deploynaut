/* global environmentConfigContext */

const React = require('react');
const ReactDOM = require('react-dom');
const DeploymentDialog = require('./DeploymentDialog.jsx');
const Tools = require('../../deploynaut/js/tools.jsx');
const Letmein = require('./Letmein.jsx');
const EnvironmentOverview = require('./EnvironmentOverview.jsx');

// Mount the component only on the page where the holder is actually present.
var holder = document.getElementById('deployment-dialog-holder');
if (holder) {
	ReactDOM.render(
		<DeploymentDialog context={environmentConfigContext} />,
		holder
	);
}

Tools.install(EnvironmentOverview, 'EnvironmentOverview');
Tools.install(Letmein, 'Letmein');
