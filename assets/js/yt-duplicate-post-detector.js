/**
 * YT Duplicate Post Detector - Admin JavaScript
 *
 * @format
 * @package YT_Duplicate_Post_Detector
 * @version 1.0.0
 */

(function ($) {
	"use strict";

	var checkTimeout = null;
	var lastCheckedTitle = "";

	/**
	 * Get similarity color based on percentage.
	 *
	 * @param {number} similarity Similarity percentage.
	 * @return {string} Color class.
	 */
	function getSimilarityColor(similarity) {
		if (similarity >= 95) {
			return "#e74c3c"; // Red
		} else if (similarity >= 90) {
			return "#e67e22"; // Orange
		} else if (similarity >= 85) {
			return "#f39c12"; // Yellow-orange
		} else {
			return "#95a5a6"; // Gray
		}
	}

	/**
	 * Create checker UI element.
	 *
	 * @return {jQuery} Checker element.
	 */
	function createCheckerUI() {
		var html =
			'<div class="yt-dpd-checker" id="yt-dpd-live-checker">' +
			'<div class="yt-dpd-checker-header">' +
			'<h4 class="yt-dpd-checker-title">Duplicate Title Checker</h4>' +
			'<span class="yt-dpd-checker-status"></span>' +
			"</div>" +
			'<div class="yt-dpd-results"></div>' +
			"</div>";

		return $(html);
	}

	/**
	 * Update checker status message.
	 *
	 * @param {string} message Status message.
	 * @param {string} type Status type (checking, found, clear).
	 */
	function updateStatus(message, type) {
		$(".yt-dpd-checker-status").removeClass("checking found clear").addClass(type).text(message);
	}

	/**
	 * Display duplicate results.
	 *
	 * @param {Array} duplicates Array of duplicate posts.
	 */
	function displayResults(duplicates) {
		var $results = $(".yt-dpd-results");

		if (!duplicates || duplicates.length === 0) {
			$results.html('<p class="yt-dpd-results-empty">' + ytDpdData.strings.noDuplicates + "</p>");
			updateStatus(ytDpdData.strings.noDuplicates, "clear");
			return;
		}

		var html = "";
		duplicates.forEach(function (duplicate) {
			var color = getSimilarityColor(duplicate.similarity);
			html +=
				'<div class="yt-dpd-result-item">' +
				'<div class="yt-dpd-result-similarity">' +
				'<span class="yt-dpd-similarity-badge" style="background-color: ' +
				color +
				'">' +
				duplicate.similarity +
				"%" +
				"</span>" +
				"</div>" +
				'<div class="yt-dpd-result-title">' +
				duplicate.title +
				"</div>" +
				'<div class="yt-dpd-result-actions">' +
				'<a href="' +
				duplicate.edit_link +
				'" class="button button-small">Edit</a>' +
				'<a href="' +
				duplicate.view_link +
				'" class="button button-small" target="_blank">View</a>' +
				"</div>" +
				"</div>";
		});

		$results.html(html);
		updateStatus(ytDpdData.strings.foundDuplicates + " (" + duplicates.length + ")", "found");
	}

	/**
	 * Check title for duplicates via AJAX.
	 *
	 * @param {string} title Post title to check.
	 * @param {number} postId Current post ID.
	 * @param {string} postType Post type.
	 */
	function checkForDuplicates(title, postId, postType) {
		// Don't check if title is empty or hasn't changed
		if (!title || title === lastCheckedTitle) {
			return;
		}

		lastCheckedTitle = title;

		// Show checking status
		updateStatus(ytDpdData.strings.checking, "checking");
		$(".yt-dpd-results").html(
			'<p class="yt-dpd-results-empty">' + ytDpdData.strings.checking + '<span class="yt-dpd-spinner"></span>' + "</p>"
		);

		// AJAX request
		$.ajax({
			url: ytDpdData.ajaxUrl,
			type: "POST",
			data: {
				action: "yt_dpd_check_title",
				nonce: ytDpdData.nonce,
				title: title,
				post_id: postId,
				post_type: postType
			},
			success: function (response) {
				if (response.success) {
					displayResults(response.data.duplicates);
				}
			},
			error: function () {
				updateStatus("Error checking for duplicates", "clear");
				$(".yt-dpd-results").html('<p class="yt-dpd-results-empty">Error checking for duplicates</p>');
			}
		});
	}

	/**
	 * Debounced title check.
	 *
	 * @param {string} title Post title.
	 * @param {number} postId Post ID.
	 * @param {string} postType Post type.
	 */
	function debouncedCheck(title, postId, postType) {
		// Clear existing timeout
		if (checkTimeout) {
			clearTimeout(checkTimeout);
		}

		// Set new timeout (wait 1 second after user stops typing)
		checkTimeout = setTimeout(function () {
			checkForDuplicates(title, postId, postType);
		}, 1000);
	}

	/**
	 * Initialize for Classic Editor.
	 */
	function initClassicEditor() {
		var $titleInput = $("#title");

		if ($titleInput.length === 0) {
			return;
		}

		// Create and insert checker UI
		var $checker = createCheckerUI();
		$titleInput.after($checker);

		var postId = $("#post_ID").val() || 0;
		var postType = $("#post_type").val() || ytDpdData.postType || "post";

		// Check on page load if title exists
		var initialTitle = $titleInput.val();
		if (initialTitle) {
			checkForDuplicates(initialTitle, postId, postType);
		}

		// Check on title input
		$titleInput.on("input keyup", function () {
			var title = $(this).val();
			debouncedCheck(title, postId, postType);
		});

		// Check on title blur (when user leaves the field)
		$titleInput.on("blur", function () {
			var title = $(this).val();
			if (title && title !== lastCheckedTitle) {
				checkForDuplicates(title, postId, postType);
			}
		});
	}

	/**
	 * Initialize for Block Editor (Gutenberg).
	 */
	function initBlockEditor() {
		// Wait for editor to be ready
		var checkEditorReady = setInterval(function () {
			if (typeof wp !== "undefined" && wp.data && wp.data.select("core/editor")) {
				clearInterval(checkEditorReady);
				setupBlockEditor();
			}
		}, 500);

		// Stop checking after 10 seconds
		setTimeout(function () {
			clearInterval(checkEditorReady);
		}, 10000);
	}

	/**
	 * Setup Block Editor integration.
	 */
	function setupBlockEditor() {
		var editor = wp.data;
		var postId = editor.select("core/editor").getCurrentPostId();
		var postType = editor.select("core/editor").getCurrentPostType();
		var lastTitle = "";

		// Create checker UI in sidebar
		var $checker = createCheckerUI();

		// Try to insert into editor sidebar
		var insertChecker = setInterval(function () {
			var $sidebar = $(".edit-post-sidebar");
			if ($sidebar.length > 0) {
				clearInterval(insertChecker);
				$sidebar.prepend($checker);

				// Check initial title
				var initialTitle = editor.select("core/editor").getEditedPostAttribute("title");
				if (initialTitle) {
					checkForDuplicates(initialTitle, postId, postType);
				}
			}
		}, 500);

		// Stop trying after 5 seconds
		setTimeout(function () {
			clearInterval(insertChecker);
		}, 5000);

		// Subscribe to title changes
		var unsubscribe = editor.subscribe(function () {
			var currentTitle = editor.select("core/editor").getEditedPostAttribute("title");

			if (currentTitle !== lastTitle) {
				lastTitle = currentTitle;
				debouncedCheck(currentTitle, postId, postType);
			}
		});

		// Cleanup on page unload
		$(window).on("beforeunload", function () {
			if (unsubscribe) {
				unsubscribe();
			}
		});
	}

	/**
	 * Add warning before publishing if duplicates found.
	 */
	function addPublishWarning() {
		// Classic Editor
		$("#publish").on("click", function (e) {
			var $results = $(".yt-dpd-results .yt-dpd-result-item");

			if ($results.length > 0) {
				var message = "Warning: Similar post titles were found.\n\n";
				message += "Are you sure you want to publish?\n\n";

				$results.each(function (index) {
					var title = $(this).find(".yt-dpd-result-title").text();
					var similarity = $(this).find(".yt-dpd-similarity-badge").text();
					message += index + 1 + ". " + title + " (" + similarity + ")\n";
				});

				if (!confirm(message)) {
					e.preventDefault();
					return false;
				}
			}
		});

		// Block Editor
		if (typeof wp !== "undefined" && wp.data) {
			wp.data.subscribe(function () {
				var isSavingPost = wp.data.select("core/editor").isSavingPost();
				var isAutosaving = wp.data.select("core/editor").isAutosavingPost();

				if (isSavingPost && !isAutosaving) {
					// Post is being published/updated
					// Note: Block editor doesn't easily support preventing save
					// Users will see the warning in the sidebar instead
				}
			});
		}
	}

	/**
	 * Initialize plugin.
	 */
	function init() {
		// Only run if plugin is enabled
		if (!ytDpdData.enabled) {
			return;
		}

		// Check if we're on a post edit screen
		var isClassicEditor = $("#title").length > 0;
		var isBlockEditor = typeof wp !== "undefined" && wp.blocks;

		if (isClassicEditor) {
			initClassicEditor();
		} else if (isBlockEditor) {
			initBlockEditor();
		}

		// Add publish warning
		addPublishWarning();
	}

	// Initialize when DOM is ready
	$(document).ready(function () {
		init();
	});

	// Re-initialize on Gutenberg mount (if needed)
	if (typeof wp !== "undefined" && wp.domReady) {
		wp.domReady(function () {
			// Additional Gutenberg-specific initialization if needed
		});
	}
})(jQuery);
