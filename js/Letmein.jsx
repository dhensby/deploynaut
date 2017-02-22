const React = require('react');
const Redux = require('redux');
const ReactRedux = require('react-redux');
const thunk = require('redux-thunk').default;
const createLogger = require('redux-logger');

const LetmeinOverview = require('./containers/LetmeinOverview.jsx');

const reducers = require('./reducers/index.js');
const webAPI = require('./_api.js');

const middleware = [thunk];
if (process.env.NODE_ENV !== "production") {
	middleware.push(createLogger());
}

const store = Redux.createStore(
	reducers,
	Redux.applyMiddleware(...middleware)
);

function Letmein(props) {
	store.dispatch(webAPI.setupAPI(
		props.model.dispatchers,
		props.model.api_auth
	));

	return (
		<ReactRedux.Provider store={store}>
			<LetmeinOverview />
		</ReactRedux.Provider>
	);
}

module.exports = Letmein;

