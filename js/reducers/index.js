const Redux = require('redux');

const api = require('./api.js');
const approval = require('./approval.js');
const deployment = require('./deployment.js');
const environment = require('./environment.js');
const git = require('./git.js');
const plan = require('./plan.js');
const user = require('./user.js');
const letmein = require('./letmein.js');

const reducers = Redux.combineReducers({
	api,
	git,
	plan,
	approval,
	deployment,
	environment,
	user,
	letmein
});

module.exports = reducers;
