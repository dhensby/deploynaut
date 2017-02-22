const React = require('react');
const ReactRedux = require('react-redux');
const LetmeinButton = require('./LetmeinButton.jsx');

function LetmeinOverview(props) {
	let error = null;
	if (props.error) {
		error = (
			<div className="alert alert-danger">
				<div className="">
					{props.error}
				</div>
			</div>
		);
	}

	let details = null;
	if (props.is_requesting === false && props.username && props.password) {
		details = (
			<div className="alert alert-good">
				<div className="">
					Here are your credentials: {props.username} / {props.password}
				</div>
			</div>
		);
	} else {
		details = (
			<LetmeinButton />
		);
	}

	return (
		<div>
			<h3>CMS access</h3>
			<p>Use this feature to gain temporary admin access to the CMS for this environment.</p>
			<p><strong>Please note that these credentials will expire after 2 hours.</strong></p>
			{error}
			{details}
		</div>
	);
}

const mapStateToProps = function(state) {
	return {
		error: state.letmein.error,
		is_requesting: state.letmein.is_requesting,
		username: state.letmein.username,
		password: state.letmein.password
	};
};

module.exports = ReactRedux.connect(mapStateToProps)(LetmeinOverview);
