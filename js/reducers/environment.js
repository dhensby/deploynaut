const _ = require('underscore');

const actions = require('../_actions.js');

module.exports = function environment(state, action) {
	if (typeof state === 'undefined') {
		return {
			id: null,
			is_ready: null,
			not_ready_message: null,
			name: null,
			project_name: null,
			usage: null,
			supported_options: {},
			approvers: {}
		};
	}

	switch (action.type) {
		case actions.SET_ENVIRONMENT:
			return _.assign({}, state, {
				id: action.data.id,
				is_ready: action.data.is_ready,
				not_ready_message: action.data.not_ready_message,
				name: action.data.name,
				project_name: action.data.project_name,
				usage: action.data.usage,
				supported_options: action.data.supported_options,
				approvers: action.data.approvers
			});
		case actions.SUCCEED_DEPLOYMENT_GET: {
			const newApprovers = _.assign({}, state.approvers);
			if (action.data.deployment.approver_id && action.data.deployment.approver) {
				newApprovers[action.data.deployment.approver_id] = action.data.deployment.approver;
			}
			return _.assign({}, state, {
				approvers: newApprovers
			});
		}
		default:
			return state;
	}
};
