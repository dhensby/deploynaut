var React = require("react");

var wrapperClasses = function(checked) {
	var classes = [
		'bootstrap-switch',
		'bootstrap-switch-wrapper',
		'bootstrap-switch-small',
		'bootstrap-switch-inverse',
		'bootstrap-switch-animate'
	];
	if(checked) {
		classes.push("bootstrap-switch-on");
	} else {
		classes.push("bootstrap-switch-on");
	}
	return classes.join(' ');
};

var containerStyle = function(wrapperWidth, checked) {
	if(checked) {
		return {width: (wrapperWidth * 2) + "px", marginLeft: "-" + (wrapperWidth / 2) + "px"};
	}
	return {width: (wrapperWidth * 2) + "px", marginLeft: "0px"};
};

function Switch(props) {
	// in pixels
	var wrapperWidth = 80;

	return (
		<div
			className={wrapperClasses(props.checked)}
			style={{width: wrapperWidth + "px"}}
			onClick={props.changeHandler}
		>
			<div
				className="bootstrap-switch-container"
				style={containerStyle(wrapperWidth, props.checked)}
			>
					<span
						className="bootstrap-switch-handle-off bootstrap-switch-default"
						style={{width: (wrapperWidth / 2) + "px"}}
					>
						OFF
					</span>
					<span
						className="bootstrap-switch-label"
						style={{width: (wrapperWidth / 2) + "px"}}
					>
						&nbsp;
					</span>
					<span
						className="bootstrap-switch-handle-on bootstrap-switch-primary"
						style={{width: (wrapperWidth / 2) + "px"}}
					>
						ON
					</span>
				<input
					type="checkbox"
					name="my-checkbox"
					defaultChecked={props.checked}
				/>
			</div>
		</div>
	);
}

Switch.propTypes = {
	checked: React.PropTypes.bool.isRequired,
	changeHandler: React.PropTypes.func.isRequired
};

module.exports = Switch;
