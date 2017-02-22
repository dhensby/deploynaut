const ReactRedux = require('react-redux');

const actions = require('../_actions.js');
const Button = require('../components/Button.jsx');

const mapStateToProps = function(state) {
	return {
		disabled: state.letmein.is_requesting,
		icon: state.letmein.is_requesting ? 'fa fa-refresh fa-spin' : '',
		style: 'btn btn-primary btn-lg-wide',
		value: state.letmein.is_requesting ? 'Gaining CMS access...' : 'Gain CMS access'
	};
};

const mapDispatchToProps = function(dispatch) {
	return {
		onClick: function() {
			dispatch(actions.letmeinRequest());
		}
	};
};

module.exports = ReactRedux.connect(mapStateToProps, mapDispatchToProps)(Button);
