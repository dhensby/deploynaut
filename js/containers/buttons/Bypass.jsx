const ReactRedux = require('react-redux');

const actions = require('../../_actions.js');
const Button = require('../../components/Button.jsx');
const constants = require('../../constants/deployment.js');

function canBypass(state) {
	if (!state.user.can_bypass_approval) {
		return false;
	}
	const current = state.deployment.list[state.deployment.current_id] || {};
	if (constants.isApproved(current.state)) {
		return false;
	}
	if (constants.isRejected(current.state)) {
		return false;
	}

	// Submitted state does not allow bypass, except for the case where the requester
	// has permissions to bypass their own submission if they assigned it to someone else
	// and wish to bypass anyway. This is because they will not be shown approve or reject
	// actions on their own submissions.
	if (constants.isSubmitted(current.state) && state.deployer && (state.deployer.id !== state.user.id)) {
		return false;
	}
	return true;
}

const mapStateToProps = function(state) {
	return {
		display: canBypass(state),
		disabled: state.approval.is_loading,
		style: "btn-wide btn-warning",
		value: "Bypass approval",
		icon: "fa fa-level-down"
	};
};

const mapDispatchToProps = function(dispatch) {
	return {
		onClick: function() {
			dispatch(actions.approveDeployment());
		}
	};
};

module.exports = ReactRedux.connect(mapStateToProps, mapDispatchToProps)(Button);
