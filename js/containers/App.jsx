const React = require('react');
const ReactRedux = require('react-redux');
const ReactRouter = require('react-router');

const CurrentBuildStatus = require('./CurrentBuildStatus.jsx');
const UpcomingDeployments = require('./UpcomingDeployments.jsx');
const DeployHistory = require('./DeployHistory.jsx');

const App = function App(props) {
	let output = (
		<div>
			<CurrentBuildStatus />
			<div className="row">
				<div className="col-md-9">
				</div>
				<div className="col-md-3 text-right">
					<ReactRouter.Link className="btn btn-primary btn-lg-wide" to="/deployment/new">
						New deployment
					</ReactRouter.Link>
				</div>
			</div>
			<UpcomingDeployments />
			<DeployHistory />
			{props.children}
		</div>
	);
	if (props.environment.is_ready === false) {
		output = (
			<div className="alert alert-warning" dangerouslySetInnerHTML={{__html: props.environment.not_ready_message}}></div>
		);
	}

	return output;
};

const mapStateToProps = function(state) {
	return {
		environment: state.environment
	};
};

module.exports = ReactRedux.connect(mapStateToProps)(App);
