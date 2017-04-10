/*jslint white: true, browser: true, nomen: true, devel: true */
/*global jQuery: false */
(function($) {
	"use strict";

	var login = $('input[name="Login"]');
	if (login && login.length > 0) {
		login.focus();
	}

	// Popover on enviroment repository link
	$(function () {
		$('[data-toggle="popover"]').popover()
	});

	// Openclose nav
	$('button.sidebar-open').on('click', function(e) {
		$('.page-container').toggleClass("open"); // you can list several class names
		e.preventDefault();
	});

	// Scrolling tabs
	if($('.list').length > 0) {
		var hidWidth;
		var scrollBarWidths = 40;

		var widthOfList = function(){
			var itemsWidth = 0;
			$('.list li').each(function(){
				var itemWidth = $(this).outerWidth();
				itemsWidth+=itemWidth;
			});
			return itemsWidth;
		};

		var widthOfHidden = function(){
			return (($('.wrapper').outerWidth())-widthOfList()-getLeftPosi())-scrollBarWidths;
		};

		var getLeftPosi = function(){
			return $('.list').position().left;
		};

		var reAdjust = function(){
			if (($('.wrapper').outerWidth()) < widthOfList()) {
				$('.scroller-right').show();
			} else {
				$('.scroller-right').hide();
			}

			if (getLeftPosi()<0) {
				$('.scroller-left').show();
			} else {
				$('.item').animate({left:"-="+getLeftPosi()+"px"},'slow');
				$('.scroller-left').hide();
			}
		}

		reAdjust();

		$(window).on('resize',function(e){
			reAdjust();
		});

		$('.scroller-right').click(function() {
			$('.scroller-left').fadeIn('slow');
			$('.scroller-right').fadeOut('slow');
			$('.list').animate({left:"+="+widthOfHidden()+"px"},'slow',function(){
			});
		});

		$('.scroller-left').click(function() {
			$('.scroller-right').fadeIn('slow');
			$('.scroller-left').fadeOut('slow');
			$('.list').animate({left:"-="+getLeftPosi()+"px"},'slow',function(){
			});
		});
	}

	var queue = {
		autoScroll: true,
		showlog: function(status, content, logLink) {
			var self = this;
			//add scroll listener
			content.on('scroll', function(ev) {
				// if we are scrolled to the bottom then autoScroll should be on otherwise we've scrolled somewhere else
				// and we shouldn't move the scroll any more
				if (content.scrollTop() >= (content[0].scrollHeight - content.innerHeight())) {
					self.autoScroll = true;
				}
				else {
					self.autoScroll = false;
				}
			});
			$.getJSON(logLink, {randval: Math.random()},
			function(data) {
				status.text(data.status.toLowerCase());
				content.text(data.content);
				//scroll the content to the bottom
				if (self.autoScroll) {
					content.scrollTop(content[0].scrollHeight);
					//we have to re-enable autoscroll because we'll have triggered a scroll event
					self.autoScroll = true;
				}
				$(status).parent().addClass(data.status);
				$('title').text(data.status + " | Deploynaut");
				if (data.status == 'Complete' || data.status == 'Completed' || data.status == 'Failed' || data.status == 'Invalid') {
					$(status).parent().removeClass('Running Deploying Aborting Queued progress-bar-striped active');
					//detach scroll listener
					content.off('scroll');
					self._clearInterval();
				} else if (data.status == 'Running' || data.status == 'Deploying' || data.status == 'Aborting') {
					$(status).parent().addClass('progress-bar-striped active')
					$(status).parent().removeClass('Queued');
				}
			}
			);
		},


		start: function() {
			this._setupPinging();
		},


		/**
		 * Will fetch latest deploy log and reload the content with it
		 */
		_setupPinging: function() {
			var self = this;
			window._queue_refresh = window.setInterval(function() {
				self.showlog($("#queue_action .jobstatus"), $("#queue_log"), $('#queue_log').data('loglink'));
			}, 3000);
		},


		/**
		 * Will remove the pinging and refresh of the application list
		 */
		_clearInterval: function() {
			window.clearInterval(window._queue_refresh);
		}
	};

	$(document).ready(function() {

		// enable table filtering
		if(document.getElementsByClassName('table-filter').length > 0) {
			TableFilter.init('table-filter');
			document.getElementsByClassName('table-filter')[0].focus();
		}

		// Enable select2
		$('select:not(.disable-select2)').select2();

		// Menu 1 expand collapse
		$('a.nav-submenu.level1').click(function(e) {
			$(this).parent().siblings().removeClass('open');
			$(this).parent().toggleClass('open');
			e.preventDefault();
		});

		// Menu 2 expand collapse
		$('a.nav-submenu.level2').click(function(e) {
			$(this).parent().toggleClass('open');
			e.preventDefault();
		});

		// Deployment screen
		if ($('#queue_log').length) {
			queue.start();
		}

		$('.tooltip-hint:not(.git-sha), .btn-tooltip').tooltip({
			placement: 'top',
			trigger: 'hover'
		});

		$('.tooltip-hint.git-sha').tooltip({
			placement: 'left',
			trigger: 'hover',
			template: '<div class="tooltip" role="tooltip"><div class="tooltip-arrow"></div><div class="tooltip-inner git-sha"></div></div>'
		});

		$('.project-name .icon-star').click(function(e) {
			var self = $(this);
			var navEl = $('.side-content .nav');

			$.get($(this).parent().prop('href'), function() {
				$.get(navEl.data('nav'), function(data) {
					navEl.html(data);
					self.toggleClass('hollow');
				});
			});

			e.preventDefault();
		});

		/**
		 * Extend a specific target
		 */
		$('.extended-trigger').click(function(e) {
			var $el = $($(this).data('extendedTarget'));
			var $container = $($(this).data('extendedContainer'));

			if($el.is(':empty')) {
				$container.data('href', $(this).attr('href'));
				$container.addClass('loading');

				$el.load($(this).attr('href'), function() {
					$container.removeClass('loading');
					$el.removeClass('hide');
					$el.find('select').select2();
				});
			} else {
				$el.empty();
				$el.addClass('hide');

				// Re-enter the click handler if another button has been pressed, so the form re-opens.
				if ($(this).attr('href')!==$container.data('href')) {
					$container.data('href', null);
					$(this).trigger('click');
				} else {
					$container.data('href', null);
				}

			}

			e.preventDefault();
		});

		$('.table-data-archives').on('click', ':input[name=action_doDataTransfer]', function(e) {
			var form = $(this).closest('form'),
				envVal = form.find("select[name=EnvironmentID]").val(),
				envLabel = form.find("select[name=EnvironmentID] option[value=\"" + envVal + '"]').text(),
				msg = 'Are you sure you want to restore data onto environment ' + envLabel + '?';
			if(!envVal){
				alert("You must select an evironment to restore to.");
				return false;
			}
			if(!confirm(msg)) e.preventDefault();
		});

		$('.upload-exceed-link').on('click', function() {
			$(this).tab('show');

			var id = $(this).attr('data-target');
			$(id).find('select').select2();
			return false;
		});

		var bulkCheckboxes = $('.bulk-delete-select');
		var bulkSubmit = $('.bulk-delete-submit')
		var bulkSelectAll = $('.bulk-delete-select-all')
		var bulkDeselectAll = $('.bulk-delete-deselect-all');

		// Hide "Delete" and "Select All" if there's no boxes to tick
		if (bulkCheckboxes.length < 1) {
			bulkSelectAll.addClass('hide');
			bulkSubmit.addClass('hide');
		}

		bulkCheckboxes.click(function() {
			var numCheckBoxes = bulkCheckboxes.length;
			// If the submit button is disabled and there's one or more snapshots selected, enable the submit button
			if (bulkSubmit.attr('disabled', true) && bulkCheckboxes.filter(':checked').length > 0) {
				bulkSubmit.prop('disabled', false);
			}

			// Also check that we aren't unticking a box
			if (bulkCheckboxes.filter(':checked').length < 1) {
				// If there's less than one ticked we simply disable the button again
				bulkSubmit.prop('disabled', true);
				// Show Select all button
				bulkSelectAll.removeClass('hide');
				// Hide Unselect all button
				bulkDeselectAll.addClass('hide');
			}

			// numCheckBoxes is an integer equal to the number of .bulk-delete-select boxes
			// If all .bulk-delete-select are checked then we
			if (bulkCheckboxes.filter(':checked').length === numCheckBoxes) {
				// Hide Select all button
				bulkSelectAll.addClass('hide');
				// Show the Unselect all button
				bulkDeselectAll.toggleClass('hide');
			//Otherwise we are clicking and not all checkboxes selected
			} else {
				// Ensure unselect is hidden
				bulkDeselectAll.addClass('hide');
				// Ensure select all is shown
				bulkSelectAll.removeClass('hide');
			}
		});

		bulkSubmit.click(function() {
			var numSnapsToDelete = bulkCheckboxes.filter(':checked').length;
			var plural = "snapshots";
			if(numSnapsToDelete < 2) {
				plural = "snapshot";
			}
			// Display the "are you sure" pop-up
			var submitForm = confirm("Are you sure you want to delete "+numSnapsToDelete+" "+plural+"? This can not be undone.");
			// If they don't choose to continue return false
			if (!submitForm) {
				return false;
			}

			var self = $(this);

			$.post(
				self.data('url'),
				{ ID: bulkCheckboxes.filter(':checked').map(function() { return $(this).val(); }).get() },
				function(data) {
					location.href = self.data('snapshots-url');
				}
			);
			return false;
		});

		// On click of the Select All
		bulkSelectAll.click(function() {
			// Check all boxes
			bulkCheckboxes.prop("checked", true);
			// Enable deletion button
			bulkSubmit.prop('disabled', false);
			// Hide self
			bulkSelectAll.addClass('hide');
			// Show the Unselect all button
			bulkDeselectAll.toggleClass('hide');
		});

		// On click of unselect all
		bulkDeselectAll.click(function() {
			// Uncheck all boxes
			bulkCheckboxes.prop("checked", false);
			// Disable the deletion button
			bulkSubmit.prop('disabled', true);
			// Hide self
			bulkDeselectAll.addClass('hide');
			// Show Select all button
			bulkSelectAll.toggleClass('hide');
		});

	});
}(jQuery));
