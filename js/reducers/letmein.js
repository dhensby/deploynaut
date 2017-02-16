const _ = require('underscore');

const actions = require('../_actions.js');

const initialState = {
	error: '',
	is_requesting: false,
	username: '',
	password: ''
};

module.exports = function letmein(state, action) {
	if (typeof state === 'undefined') {
		return initialState;
	}
	switch (action.type) {
		case actions.START_LETMEIN_REQUEST:
			return _.assign({}, state, {
				is_requesting: true,
				error: '',
				username: '',
				password: ''
			});
		case actions.SUCCEED_LETMEIN_REQUEST:
			return _.assign({}, state, {
				is_requesting: false,
				username: action.data.username,
				password: action.data.password
			});
		case actions.FAIL_LETMEIN_REQUEST:
			return _.assign({}, state, {
				is_requesting: false,
				error: action.error.toString()
			});
		default:
			return state;
	}
};
