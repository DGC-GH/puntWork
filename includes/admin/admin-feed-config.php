<?php

/**
 * Drag-and-drop feed configuration UI
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      2.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render drag-and-drop feed configuration UI
 *
 * @return void
 */
function render_feed_config_ui(): void {
	$feeds      = get_feeds();
	$feed_posts = get_posts(
		array(
			'post_type'      => 'job-feed',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		)
	);

	?>
	<div class="puntwork-admin">
		<div class="puntwork-container">
			<header class="puntwork-header">
				<h1 class="puntwork-header__title">Feed Configuration</h1>
				<p class="puntwork-header__subtitle">Manage and configure your job feeds with drag-and-drop reordering</p>
			</header>

			<!-- Feed Configuration Section -->
			<div class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
				<div class="puntwork-card__header">
					<h2 class="puntwork-card__title">Active Feeds</h2>
					<p class="puntwork-card__subtitle">Drag and drop to reorder feeds. Feeds are processed in the order shown below.</p>
				</div>

				<div class="puntwork-card__body">
					<div id="feed-list" class="feed-list">
						<?php if ( empty( $feed_posts ) ) : ?>
							<div class="puntwork-empty">
								<i class="fas fa-rss puntwork-empty__icon"></i>
								<div class="puntwork-empty__title">No feeds configured</div>
								<div class="puntwork-empty__message">Add your first job feed to get started with importing jobs.</div>
								<button id="add-first-feed" class="puntwork-btn puntwork-btn--primary">
									<i class="fas fa-plus puntwork-btn__icon"></i>Add First Feed
								</button>
							</div>
						<?php else : ?>
							<?php
							foreach ( $feed_posts as $post ) :
								$feed_url     = get_post_meta( $post->ID, 'feed_url', true );
								$is_enabled   = get_post_meta( $post->ID, 'feed_enabled', true ) !== '0'; // Default to enabled
								$last_import  = get_post_meta( $post->ID, 'last_import', true );
								$import_count = get_post_meta( $post->ID, 'import_count', true ) ?: 0;
								?>
								<div class="feed-item" data-feed-id="<?php echo esc_attr( $post->ID ); ?>">
									<div class="feed-item__handle">
										<i class="fas fa-grip-vertical"></i>
									</div>

									<div class="feed-item__content">
										<div class="feed-item__header">
											<div class="feed-item__title">
												<strong><?php echo esc_html( $post->post_title ); ?></strong>
												<span class="feed-item__url"><?php echo esc_url( $feed_url ); ?></span>
											</div>
											<div class="feed-item__actions">
												<label class="feed-toggle">
													<input type="checkbox" class="feed-enabled" <?php checked( $is_enabled ); ?>>
													<span class="feed-toggle-slider"></span>
													<span class="feed-toggle-label">Enabled</span>
												</label>
												<button class="feed-action feed-action--edit" title="Edit Feed">
													<i class="fas fa-edit"></i>
												</button>
												<button class="feed-action feed-action--delete" title="Delete Feed">
													<i class="fas fa-trash"></i>
												</button>
											</div>
										</div>

										<div class="feed-item__meta">
											<span class="feed-meta-item">
												<i class="fas fa-chart-line"></i>
												<?php echo number_format( $import_count ); ?> imports
											</span>
											<?php if ( $last_import ) : ?>
												<span class="feed-meta-item">
													<i class="fas fa-clock"></i>
													Last import: <?php echo esc_html( date( 'M j, Y H:i', strtotime( $last_import ) ) ); ?>
												</span>
											<?php endif; ?>
											<span class="feed-meta-item">
												<i class="fas fa-tag"></i>
												<?php echo esc_html( $post->post_name ); ?>
											</span>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $feed_posts ) ) : ?>
						<div class="feed-actions" style="margin-top: var(--spacing-lg); padding-top: var(--spacing-lg); border-top: 1px solid var(--color-gray-200);">
							<button id="add-new-feed" class="puntwork-btn puntwork-btn--primary">
								<i class="fas fa-plus puntwork-btn__icon"></i>Add New Feed
							</button>
							<button id="save-feed-order" class="puntwork-btn puntwork-btn--success" style="display: none;">
								<i class="fas fa-save puntwork-btn__icon"></i>Save Order
							</button>
							<span id="feed-order-status" style="margin-left: var(--spacing-md); font-size: var(--font-size-sm); color: var(--color-gray-600);"></span>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Feed Statistics Section -->
			<div class="puntwork-card">
				<div class="puntwork-card__header">
					<h2 class="puntwork-card__title">Feed Statistics</h2>
					<p class="puntwork-card__subtitle">Overview of your feed configuration and performance.</p>
				</div>

				<div class="puntwork-card__body">
					<div class="puntwork-stats">
						<div class="puntwork-stat">
							<div class="puntwork-stat__icon">
								<i class="fas fa-rss"></i>
							</div>
							<div class="puntwork-stat__value"><?php echo count( $feed_posts ); ?></div>
							<div class="puntwork-stat__label">Total Feeds</div>
						</div>

						<div class="puntwork-stat">
							<div class="puntwork-stat__icon">
								<i class="fas fa-check-circle"></i>
							</div>
							<div class="puntwork-stat__value">
								<?php
								$enabled_count = 0;
								foreach ( $feed_posts as $post ) {
									if ( get_post_meta( $post->ID, 'feed_enabled', true ) !== '0' ) {
										++$enabled_count;
									}
								}
								echo $enabled_count;
								?>
							</div>
							<div class="puntwork-stat__label">Enabled Feeds</div>
						</div>

						<div class="puntwork-stat">
							<div class="puntwork-stat__icon">
								<i class="fas fa-chart-line"></i>
							</div>
							<div class="puntwork-stat__value">
								<?php
								$total_imports = 0;
								foreach ( $feed_posts as $post ) {
									$total_imports += (int) get_post_meta( $post->ID, 'import_count', true );
								}
								echo number_format( $total_imports );
								?>
							</div>
							<div class="puntwork-stat__label">Total Imports</div>
						</div>

						<div class="puntwork-stat">
							<div class="puntwork-stat__icon">
								<i class="fas fa-clock"></i>
							</div>
							<div class="puntwork-stat__value">
								<?php
								$last_import_times = array();
								foreach ( $feed_posts as $post ) {
									$last_import = get_post_meta( $post->ID, 'last_import', true );
									if ( $last_import ) {
										$last_import_times[] = strtotime( $last_import );
									}
								}
								if ( ! empty( $last_import_times ) ) {
									$most_recent = max( $last_import_times );
									echo esc_html( date( 'M j', $most_recent ) );
								} else {
									echo 'Never';
								}
								?>
							</div>
							<div class="puntwork-stat__label">Last Import</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Feed Edit Modal -->
	<div id="feed-modal" class="puntwork-modal" style="display: none;">
		<div class="puntwork-modal__overlay"></div>
		<div class="puntwork-modal__content">
			<div class="puntwork-modal__header">
				<h3 id="feed-modal-title">Add New Feed</h3>
				<button class="puntwork-modal__close">
					<i class="fas fa-times"></i>
				</button>
			</div>

			<form id="feed-form" class="puntwork-modal__body">
				<input type="hidden" id="feed-id" name="feed_id" value="">

				<div class="puntwork-form-group">
					<label for="feed-title" class="puntwork-form-label">Feed Name *</label>
					<input type="text" id="feed-title" name="feed_title" class="puntwork-form-control" placeholder="e.g., Indeed Jobs" required>
					<small class="puntwork-form-help">A descriptive name for this feed</small>
				</div>

				<div class="puntwork-form-group">
					<label for="feed-url" class="puntwork-form-label">Feed URL *</label>
					<input type="url" id="feed-url" name="feed_url" class="puntwork-form-control" placeholder="https://example.com/jobs.xml" required>
					<small class="puntwork-form-help">The URL of the job feed (XML, JSON, or CSV)</small>
				</div>

				<div class="puntwork-form-group">
					<label for="feed-slug" class="puntwork-form-label">Feed Slug</label>
					<input type="text" id="feed-slug" name="feed_slug" class="puntwork-form-control" placeholder="auto-generated">
					<small class="puntwork-form-help">URL-friendly identifier (auto-generated from name if empty)</small>
				</div>

				<div class="puntwork-form-group">
					<label class="puntwork-form-label">Feed Settings</label>
					<div class="feed-settings">
						<label class="feed-setting">
							<input type="checkbox" id="feed-enabled-modal" name="feed_enabled" checked>
							<span>Enable this feed for imports</span>
						</label>
					</div>
				</div>
			</form>

			<div class="puntwork-modal__footer">
				<button type="button" class="puntwork-btn puntwork-btn--secondary" id="cancel-feed">Cancel</button>
				<button type="button" class="puntwork-btn puntwork-btn--primary" id="save-feed">
					<span id="save-feed-text">Save Feed</span>
					<span id="save-feed-loading" style="display: none;">Saving...</span>
				</button>
			</div>
		</div>
	</div>

	<style>
		/* Feed List Styles */
		.feed-list {
			min-height: 200px;
		}

		.feed-item {
			display: flex;
			align-items: center;
			padding: var(--spacing-lg);
			background: var(--color-white);
			border: 1px solid var(--color-gray-200);
			border-radius: var(--radius-md);
			margin-bottom: var(--spacing-md);
			transition: var(--transition-fast);
			cursor: move;
			min-width: 0;
		}

		.feed-item:hover {
			border-color: var(--color-primary);
			box-shadow: var(--shadow-sm);
		}

		.feed-item__handle {
			color: var(--color-gray-400);
			cursor: grab;
			margin-right: var(--spacing-md);
			font-size: var(--font-size-lg);
		}

		.feed-item__handle:active {
			cursor: grabbing;
		}

		.feed-item__content {
			flex: 1;
			min-width: 0;
			overflow: hidden;
		}

		.feed-item__header {
			display: flex;
			justify-content: space-between;
			align-items: flex-start;
			margin-bottom: var(--spacing-sm);
			min-width: 0;
		}

		.feed-item__title strong {
			display: block;
			font-size: var(--font-size-base);
			font-weight: var(--font-weight-semibold);
			color: var(--color-black);
			margin-bottom: var(--spacing-xs);
		}

		.feed-item__url {
			font-size: var(--font-size-sm);
			color: var(--color-gray-600);
			word-break: break-all;
			overflow-wrap: break-word;
		}

		.feed-item__actions {
			display: flex;
			align-items: center;
			gap: var(--spacing-sm);
		}

		.feed-toggle {
			position: relative;
			display: inline-block;
			width: 44px;
			height: 24px;
			cursor: pointer;
		}

		.feed-toggle input {
			opacity: 0;
			width: 0;
			height: 0;
		}

		.feed-toggle-slider {
			position: absolute;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background: var(--color-gray-300);
			border-radius: 12px;
			transition: var(--transition-fast);
		}

		.feed-toggle-slider:before {
			position: absolute;
			content: "";
			height: 18px;
			width: 18px;
			left: 3px;
			bottom: 3px;
			background: var(--color-white);
			border-radius: 50%;
			transition: var(--transition-fast);
			box-shadow: 0 1px 3px rgba(0,0,0,0.2);
		}

		.feed-toggle input:checked + .feed-toggle-slider {
			background: var(--color-success);
		}

		.feed-toggle input:checked + .feed-toggle-slider:before {
			transform: translateX(20px);
		}

		.feed-toggle-label {
			margin-left: 52px;
			font-size: var(--font-size-sm);
			color: var(--color-gray-600);
		}

		.feed-action {
			background: none;
			border: none;
			color: var(--color-gray-500);
			cursor: pointer;
			padding: var(--spacing-xs);
			border-radius: var(--radius-sm);
			transition: var(--transition-fast);
			font-size: var(--font-size-sm);
		}

		.feed-action:hover {
			background: var(--color-gray-100);
			color: var(--color-primary);
		}

		.feed-action--delete:hover {
			color: var(--color-danger);
		}

		.feed-item__meta {
			display: flex;
			gap: var(--spacing-lg);
			font-size: var(--font-size-sm);
			color: var(--color-gray-600);
		}

		.feed-meta-item {
			display: flex;
			align-items: center;
			gap: var(--spacing-xs);
		}

		.feed-meta-item i {
			font-size: var(--font-size-xs);
		}

		/* Modal Styles */
		.puntwork-modal {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			z-index: 10000;
		}

		.puntwork-modal__overlay {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0,0,0,0.5);
		}

		.puntwork-modal__content {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			background: var(--color-white);
			border-radius: var(--radius-lg);
			box-shadow: var(--shadow-xl);
			max-width: 500px;
			width: 90%;
			max-height: 90vh;
			overflow-y: auto;
		}

		.puntwork-modal__header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: var(--spacing-lg);
			border-bottom: 1px solid var(--color-gray-200);
		}

		.puntwork-modal__header h3 {
			margin: 0;
			font-size: var(--font-size-lg);
			font-weight: var(--font-weight-semibold);
		}

		.puntwork-modal__close {
			background: none;
			border: none;
			font-size: var(--font-size-lg);
			color: var(--color-gray-500);
			cursor: pointer;
			padding: var(--spacing-xs);
			border-radius: var(--radius-sm);
		}

		.puntwork-modal__close:hover {
			background: var(--color-gray-100);
			color: var(--color-black);
		}

		.puntwork-modal__body {
			padding: var(--spacing-lg);
		}

		.puntwork-modal__footer {
			display: flex;
			justify-content: flex-end;
			gap: var(--spacing-md);
			padding: var(--spacing-lg);
			border-top: 1px solid var(--color-gray-200);
		}

		/* Form Styles */
		.puntwork-form-group {
			margin-bottom: var(--spacing-lg);
		}

		.puntwork-form-label {
			display: block;
			font-size: var(--font-size-sm);
			font-weight: var(--font-weight-medium);
			color: var(--color-black);
			margin-bottom: var(--spacing-xs);
		}

		.puntwork-form-control {
			width: 100%;
			padding: var(--spacing-sm) var(--spacing-md);
			border: 1px solid var(--color-gray-300);
			border-radius: var(--radius-md);
			font-size: var(--font-size-base);
			transition: var(--transition-fast);
		}

		.puntwork-form-control:focus {
			outline: none;
			border-color: var(--color-primary);
			box-shadow: 0 0 0 3px rgba(0,122,255,0.1);
		}

		.puntwork-form-help {
			display: block;
			font-size: var(--font-size-xs);
			color: var(--color-gray-600);
			margin-top: var(--spacing-xs);
		}

		.feed-settings {
			display: flex;
			flex-direction: column;
			gap: var(--spacing-sm);
		}

		.feed-setting {
			display: flex;
			align-items: center;
			gap: var(--spacing-sm);
			font-size: var(--font-size-sm);
		}

		/* Drag and Drop States */
		.feed-item.ui-sortable-helper {
			opacity: 0.8;
			transform: rotate(5deg);
		}

		.ui-sortable-placeholder {
			visibility: visible !important;
			background: var(--color-gray-100);
			border: 2px dashed var(--color-primary);
			border-radius: var(--radius-md);
			margin-bottom: var(--spacing-md);
			height: 80px;
		}

		/* Responsive Design */
		@media (max-width: 768px) {
			.feed-item__header {
				flex-direction: column;
				align-items: flex-start;
				gap: var(--spacing-sm);
			}

			.feed-item__actions {
				align-self: flex-end;
			}

			.feed-item__meta {
				flex-direction: column;
				gap: var(--spacing-xs);
			}

			.puntwork-modal__content {
				width: 95%;
				margin: var(--spacing-lg);
			}
		}
	</style>

	<script>
		jQuery(document).ready(function($) {
			let isOrderChanged = false;

			// Initialize jQuery UI Sortable
			$('#feed-list').sortable({
				handle: '.feed-item__handle',
				placeholder: 'sortable-placeholder',
				tolerance: 'pointer',
				update: function(event, ui) {
					isOrderChanged = true;
					$('#save-feed-order').show();
					updateOrderStatus('Order changed - click Save Order to apply changes');
				}
			});

			// Feed toggle handlers
			$(document).on('change', '.feed-enabled', function() {
				const feedId = $(this).closest('.feed-item').data('feed-id');
				const isEnabled = $(this).is(':checked');

				updateFeedStatus(feedId, isEnabled);
			});

			// Add new feed button
			$('#add-new-feed, #add-first-feed').on('click', function() {
				openFeedModal();
			});

			// Edit feed button
			$(document).on('click', '.feed-action--edit', function() {
				const feedItem = $(this).closest('.feed-item');
				const feedId = feedItem.data('feed-id');

				// Get feed data
				const title = feedItem.find('.feed-item__title strong').text();
				const url = feedItem.find('.feed-item__url').text();
				const isEnabled = feedItem.find('.feed-enabled').is(':checked');

				openFeedModal(feedId, title, url, isEnabled);
			});

			// Delete feed button
			$(document).on('click', '.feed-action--delete', function() {
				const feedItem = $(this).closest('.feed-item');
				const feedId = feedItem.data('feed-id');
				const title = feedItem.find('.feed-item__title strong').text();

				if (confirm(`Are you sure you want to delete the feed "${title}"? This action cannot be undone.`)) {
					deleteFeed(feedId);
				}
			});

			// Save feed order
			$('#save-feed-order').on('click', function() {
				saveFeedOrder();
			});

			// Modal handlers
			$('#feed-modal .puntwork-modal__close, #cancel-feed').on('click', function() {
				closeFeedModal();
			});

			$('#save-feed').on('click', function() {
				saveFeed();
			});

			// Close modal on overlay click
			$('#feed-modal .puntwork-modal__overlay').on('click', function() {
				closeFeedModal();
			});

			// Auto-generate slug from title
			$('#feed-title').on('input', function() {
				const title = $(this).val();
				const slug = title.toLowerCase()
					.replace(/[^a-z0-9\s-]/g, '')
					.replace(/\s+/g, '-')
					.replace(/-+/g, '-')
					.trim('-');

				$('#feed-slug').val(slug);
			});

			function openFeedModal(feedId = '', title = '', url = '', enabled = true) {
				$('#feed-id').val(feedId);
				$('#feed-title').val(title);
				$('#feed-url').val(url);
				$('#feed-slug').val('');
				$('#feed-enabled-modal').prop('checked', enabled);

				if (feedId) {
					$('#feed-modal-title').text('Edit Feed');
					$('#save-feed-text').text('Update Feed');
				} else {
					$('#feed-modal-title').text('Add New Feed');
					$('#save-feed-text').text('Add Feed');
				}

				$('#feed-modal').show();
				$('#feed-title').focus();
			}

			function closeFeedModal() {
				$('#feed-modal').hide();
				$('#feed-form')[0].reset();
			}

			function saveFeed() {
				const feedId = $('#feed-id').val();
				const title = $('#feed-title').val().trim();
				const url = $('#feed-url').val().trim();
				const slug = $('#feed-slug').val().trim();
				const enabled = $('#feed-enabled-modal').is(':checked');

				if (!title || !url) {
					alert('Please fill in all required fields.');
					return;
				}

				// Show loading
				$('#save-feed-text').hide();
				$('#save-feed-loading').show();

				// Prepare data
				const data = {
					action: 'puntwork_save_feed',
					feed_id: feedId,
					feed_title: title,
					feed_url: url,
					feed_slug: slug,
					feed_enabled: enabled,
					nonce: '<?php echo wp_create_nonce( 'puntwork_feed_config' ); ?>'
				};

				// Make AJAX request
				$.post(ajaxurl, data)
					.done(function(response) {
						if (response.success) {
							closeFeedModal();
							location.reload(); // Refresh to show changes
						} else {
							alert('Error saving feed: ' + (response.data || 'Unknown error'));
						}
					})
					.fail(function(xhr, status, error) {
						alert('Error saving feed: ' + error);
					})
					.always(function() {
						$('#save-feed-text').show();
						$('#save-feed-loading').hide();
					});
			}

			function updateFeedStatus(feedId, enabled) {
				const data = {
					action: 'puntwork_toggle_feed',
					feed_id: feedId,
					enabled: enabled,
					nonce: '<?php echo wp_create_nonce( 'puntwork_feed_config' ); ?>'
				};

				$.post(ajaxurl, data)
					.done(function(response) {
						if (!response.success) {
							alert('Error updating feed status: ' + (response.data || 'Unknown error'));
							// Revert checkbox
							$('.feed-item[data-feed-id="' + feedId + '"] .feed-enabled').prop('checked', !enabled);
						}
					})
					.fail(function(xhr, status, error) {
						alert('Error updating feed status: ' + error);
						// Revert checkbox
						$('.feed-item[data-feed-id="' + feedId + '"] .feed-enabled').prop('checked', !enabled);
					});
			}

			function deleteFeed(feedId) {
				const data = {
					action: 'puntwork_delete_feed',
					feed_id: feedId,
					nonce: '<?php echo wp_create_nonce( 'puntwork_feed_config' ); ?>'
				};

				$.post(ajaxurl, data)
					.done(function(response) {
						if (response.success) {
							$('.feed-item[data-feed-id="' + feedId + '"]').fadeOut(function() {
								$(this).remove();
								if ($('.feed-item').length === 0) {
									location.reload(); // Show empty state
								}
							});
						} else {
							alert('Error deleting feed: ' + (response.data || 'Unknown error'));
						}
					})
					.fail(function(xhr, status, error) {
						alert('Error deleting feed: ' + error);
					});
			}

			function saveFeedOrder() {
				const feedOrder = [];
				$('.feed-item').each(function() {
					feedOrder.push($(this).data('feed-id'));
				});

				const data = {
					action: 'puntwork_save_feed_order',
					feed_order: feedOrder,
					nonce: '<?php echo wp_create_nonce( 'puntwork_feed_config' ); ?>'
				};

				$('#save-feed-order').prop('disabled', true).text('Saving...');

				$.post(ajaxurl, data)
					.done(function(response) {
						if (response.success) {
							isOrderChanged = false;
							$('#save-feed-order').hide();
							updateOrderStatus('Order saved successfully', 'success');
							setTimeout(function() {
								$('#feed-order-status').text('');
							}, 3000);
						} else {
							alert('Error saving feed order: ' + (response.data || 'Unknown error'));
							$('#save-feed-order').prop('disabled', false).text('Save Order');
						}
					})
					.fail(function(xhr, status, error) {
						alert('Error saving feed order: ' + error);
						$('#save-feed-order').prop('disabled', false).text('Save Order');
					});
			}

			function updateOrderStatus(message, type = 'info') {
				const statusEl = $('#feed-order-status');
				statusEl.text(message);
				statusEl.removeClass('success error info').addClass(type);
			}
		});
	</script>
	<?php
}