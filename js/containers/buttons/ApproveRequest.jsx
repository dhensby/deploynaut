var ReactRedux = require('react-redux');

var actions = require('../../_actions.js');
var Button = require('../../components/Button.jsx');
const constants = require('../../constants/deployment.js');

function canApprove(state) {
	if (!state.user.can_approve) {
		return false;
	}
	const current = state.deployment.list[state.deployment.current_id] || {};
	if (current.deployer && (current.deployer.id === state.user.id)) {
		return false;
	}
	if (!constants.isSubmitted(current.state)) {
		return false;
	}
	return true;
}

const mapStateToProps = function(state) {
	return {
		display: canApprove(state),
		disabled: state.approval.is_loading,
		style: "btn-success btn-wide",
		value: "Approve"
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
