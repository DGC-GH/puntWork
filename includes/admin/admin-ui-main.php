<?php

/**
 * Main import UI components for job import plugin
 * Contains the primary import interface and progress display.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render jobs dashboard UI section.
 *
 * @return void
 */
function render_jobs_dashboard_ui(): void {
	?>
	<div class="puntwork-admin">
		<div class="puntwork-container">
			<header class="puntwork-header">
				<h1 class="puntwork-header__title">Jobs Dashboard</h1>
				<p class="puntwork-header__subtitle">Manage and monitor your job import operations</p>
			</header>

			<!-- Delete Drafts and Trash Section -->
			<div class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
				<div class="puntwork-card__header">
					<h2 class="puntwork-card__title">Cleanup Operations</h2>
										<p class="puntwork-card__subtitle">Permanently delete all job posts that are in Draft or Trash status.
						This action cannot be undone.</p>
				</div>

				<!-- Cleanup Progress Section -->
								<div id="jobs-cleanup-progress" class="puntwork-card__body"
					style="background-color: var(--color-gray-50); border-radius: var(--radius-md);
					margin-bottom: var(--spacing-lg); display: none;">
										<div style="display: flex; justify-content: space-between; align-items: center;
						margin-bottom: var(--spacing-sm);">
												<span id="jobs-cleanup-progress-percent"
							style="font-size: var(--font-size-xl); font-weight: var(--font-weight-bold);
							color: var(--color-primary);">0%</span>
												<span id="jobs-cleanup-time-elapsed"
							style="font-size: var(--font-size-sm); color: var(--color-gray-600);">0s</span>
					</div>
					<div class="puntwork-progress">
						<div id="jobs-cleanup-progress-bar" class="puntwork-progress__bar" style="width: 0%;"></div>
					</div>
					<div style="display: flex; justify-content: space-between; align-items: center;
						margin-top: var(--spacing-sm);">
												<span id="jobs-cleanup-status-message"
							style="font-size: var(--font-size-sm); color: var(--color-gray-600);">Ready to start.</span>
												<span id="jobs-cleanup-items-left"
							style="font-size: var(--font-size-sm); color: var(--color-gray-600);">0 left</span>
					</div>
				</div>

				<div class="puntwork-card__footer">
					<div style="display: flex; gap: var(--spacing-md); align-items: center;">
						<button id="jobs-cleanup-duplicates" class="puntwork-btn puntwork-btn--danger">
							<i class="fas fa-trash-alt puntwork-btn__icon"></i>
							<span id="jobs-cleanup-text">Delete Drafts & Trash</span>
							<span id="jobs-cleanup-loading" style="display: none;">Deleting...</span>
						</button>
						<span id="jobs-cleanup-status"
							style="font-size: var(--font-size-sm); color: var(--color-gray-600);"></span>
					</div>
				</div>
			</div>

			<!-- Database Optimization Section -->
			<div class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
				<div class="puntwork-card__header">
					<h2 class="puntwork-card__title">Database Optimization</h2>
					<p class="puntwork-card__subtitle">
						Optimize database performance with proper indexes for faster imports.
					</p>
				</div>

				<div class="puntwork-card__body">
										<div id="db-optimization-status"
						style="background-color: var(--color-gray-50); border-radius: var(--radius-md);
						padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-md);">
							<span style="font-size: var(--font-size-base); font-weight: var(--font-weight-medium);">Index Status</span>
							<span id="db-status-badge" class="puntwork-status puntwork-status--neutral">
								<i class="fas fa-spinner fa-spin" style="margin-right: var(--spacing-xs);"></i>Loading...
							</span>
						</div>
						<div id="db-indexes-list" style="font-size: var(--font-size-sm); color: var(--color-gray-600);">
							<div style="display: flex; align-items: center;">
								<i class="fas fa-spinner fa-spin" style="margin-right: var(--spacing-xs);"></i>
								Loading database optimization status...
							</div>
						</div>
					</div>
				</div>

				<div class="puntwork-card__footer">
					<div style="display: flex; gap: var(--spacing-md); align-items: center;">
						<button id="optimize-database" class="puntwork-btn puntwork-btn--primary">
							<i class="fas fa-database puntwork-btn__icon"></i>
							<span id="optimize-text">Create Missing Indexes</span>
							<span id="optimize-loading" style="display: none;">Creating Indexes...</span>
						</button>
						<button id="check-db-status" class="puntwork-btn puntwork-btn--secondary">
							<i class="fas fa-sync puntwork-btn__icon"></i>
							<span id="check-text">Check Status</span>
							<span id="check-loading" style="display: none;">Checking...</span>
						</button>
						<span id="db-optimization-status-msg" style="font-size: var(--font-size-sm); color: var(--color-gray-600);"></span>
					</div>
				</div>
			</div>

			<!-- Async Processing Configuration Section -->
			<div class="puntwork-card">
				<div class="puntwork-card__header">
					<h2 class="puntwork-card__title">Async Processing</h2>
					<p class="puntwork-card__subtitle">Configure background processing for large imports to prevent timeouts and improve performance.</p>
				</div>

				<div class="puntwork-card__body">
					<div id="async-processing-status" style="background-color: var(--color-gray-50); border-radius: var(--radius-md); padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-md);">
							<span style="font-size: var(--font-size-base); font-weight: var(--font-weight-medium);">Async Status</span>
							<span id="async-status-badge" class="puntwork-status puntwork-status--neutral">
								<i class="fas fa-spinner fa-spin" style="margin-right: var(--spacing-xs);"></i>Loading...
							</span>
						</div>
						<div id="async-status-details" style="font-size: var(--font-size-sm); color: var(--color-gray-600);">
							<div style="display: flex; align-items: center;">
								<i class="fas fa-spinner fa-spin" style="margin-right: var(--spacing-xs);"></i>
								Loading async processing status...
							</div>
						</div>
					</div>
				</div>

				<div class="puntwork-card__footer">
					<div style="display: flex; gap: 12px; align-items: center;">
						<label style="display: flex; align-items: center; gap: 8px; font-size: 14px;">
							<input type="checkbox" id="enable-async-processing" checked>
							Enable async processing for large imports (>500 items)
						</label>
						<button id="save-async-settings" class="puntwork-btn puntwork-btn--primary" style="border-radius: 8px; padding: 8px 16px; font-size: 14px; font-weight: 500;">
							<span id="save-async-text">Save Settings</span>
							<span id="save-async-loading" style="display: none;">Saving...</span>
						</button>
						<span id="async-settings-status" style="font-size: 14px; color: #8e8e93;"></span>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render main import UI section.
 *
 * @return void
 */
function render_main_import_ui(): void {
	error_log( '[PUNTWORK] [UI-RENDER] render_main_import_ui() called at ' . date( 'Y-m-d H:i:s T' ) );
	error_log( '[PUNTWORK] [UI-RENDER] Rendering main import UI elements' );

	// Check if combined JSONL file exists and if there are feeds configured
	$jsonl_path   = ABSPATH . 'feeds/combined-jobs.jsonl';
	$jsonl_exists = file_exists( $jsonl_path );
	$jsonl_size   = $jsonl_exists ? filesize( $jsonl_path ) : 0;
	$feeds        = get_feeds();
	$has_feeds    = ! empty( $feeds );

	error_log( '[PUNTWORK] [UI-VALIDATION] Combined JSONL file check - Path: ' . $jsonl_path . ', Exists: ' . ( $jsonl_exists ? 'YES' : 'NO' ) . ', Size: ' . $jsonl_size . ' bytes' );
	error_log( '[PUNTWORK] [UI-VALIDATION] Feeds check - Count: ' . count( $feeds ) . ', Has feeds: ' . ( $has_feeds ? 'YES' : 'NO' ) );

	if ( ! $jsonl_exists || $jsonl_size === 0 ) {
		if ( $has_feeds ) {
			// If feeds are configured but no JSONL, Start Import will create it
			error_log( '[PUNTWORK] [UI-INFO] No combined JSONL file found, but feeds are configured - Start Import will process feeds first' );
		} else {
			// No feeds configured and no JSONL - show warning
			error_log( '[PUNTWORK] [UI-WARNING] No import data available - Combined JSONL file missing or empty, and no feeds configured' );
			PuntWorkLogger::logAdminAction( 'UI Import Data Check', 'No import data available - combined JSONL file missing or empty, and no feeds configured', PuntWorkLogger::CONTEXT_ADMIN );
		}
	} else {
		error_log( '[PUNTWORK] [UI-SUCCESS] Import data available - Combined JSONL file found and valid' );
	}

	?>
	<div class="puntwork-admin">
		<div class="puntwork-container">
			<header class="puntwork-header">
				<h1 class="puntwork-header__title">Feeds Dashboard</h1>
				<p class="puntwork-header__subtitle">Manage and monitor your job feed imports</p>
			</header>

			<!-- Import Controls Section -->
			<div class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
				<div class="puntwork-card__header">
					<h2 class="puntwork-card__title">Import Controls</h2>
					<p class="puntwork-card__subtitle">Start, pause, or resume job imports from configured feeds.</p>
				</div>

				<?php if ( ( ! $jsonl_exists || $jsonl_size === 0 ) && ! $has_feeds ) : ?>
				<!-- No Data Available Notice - Only show if no feeds are configured -->
				<div class="puntwork-card__body" style="background-color: #fef3c7; border: 1px solid #f59e0b; border-radius: var(--radius-md); padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
					<div style="display: flex; align-items: flex-start; gap: var(--spacing-md);">
						<i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: var(--font-size-xl); margin-top: var(--spacing-xs);"></i>
						<div>
							<h3 style="font-size: var(--font-size-base); font-weight: var(--font-weight-semibold); color: #92400e; margin: 0 0 var(--spacing-sm) 0;">No Import Data Available</h3>
							<p style="font-size: var(--font-size-sm); color: #92400e; margin: 0 0 var(--spacing-md) 0;">
								Job feeds need to be processed before you can run imports. The combined data file is missing or empty.
							</p>
							<p style="font-size: var(--font-size-sm); color: #92400e; margin: 0;">
								<strong>Solution:</strong> Go to the <a href="<?php echo admin_url( 'admin.php?page=puntwork-scheduling' ); ?>" style="color: #dc2626; text-decoration: underline;">Scheduling section</a> and click "Run Now" to process feeds and start a complete import.
							</p>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<div class="puntwork-card__footer">
					<div style="display: flex; gap: var(--spacing-md); align-items: center; flex-wrap: wrap;">
						<button id="start-import" class="puntwork-btn puntwork-btn--primary">
							<i class="fas fa-play puntwork-btn__icon"></i>
							<span id="start-text">Start Import</span>
							<span id="start-loading" style="display: none;">Starting...</span>
						</button>
						<button id="cancel-import" class="puntwork-btn puntwork-btn--danger" style="display: none;">
							<i class="fas fa-stop puntwork-btn__icon"></i>Cancel Import
						</button>
						<button id="resume-import" class="puntwork-btn puntwork-btn--success" style="display: none;">
							<i class="fas fa-play-circle puntwork-btn__icon"></i>Resume Import
						</button>
						<button id="reset-import" class="puntwork-btn puntwork-btn--outline">
							<i class="fas fa-undo puntwork-btn__icon"></i>Reset Import
						</button>
						<button id="clear-rate-limits" class="puntwork-btn puntwork-btn--outline" style="background: linear-gradient(135deg, #ff9500, #ff9f0a); color: white; border: 1px solid #ff9500;">
							<i class="fas fa-shield-alt puntwork-btn__icon"></i>
							<span id="clear-rate-text">Clear Rate Limits</span>
							<span id="clear-rate-loading" style="display: none;">Clearing...</span>
						</button>
						<button id="test-single-job" class="puntwork-btn puntwork-btn--outline" style="background: linear-gradient(135deg, #ff9500, #ff9f0a); color: white; border: 1px solid #ff9500;">
							<i class="fas fa-flask puntwork-btn__icon"></i>Test Single Job
						</button>
						<span id="import-status" style="font-size: var(--font-size-sm); color: var(--color-gray-600);"></span>
					</div>
				</div>
			</div>

			<!-- Cleanup Operations Section -->
			<div class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
				<div class="puntwork-card__header">
					<h2 class="puntwork-card__title">Cleanup Operations</h2>
					<p class="puntwork-card__subtitle">Permanently delete all job posts that are in Draft or Trash status. This action cannot be undone.</p>
				</div>

				<!-- Cleanup Progress Section -->
				<div id="cleanup-progress" class="puntwork-card__body"
					style="background-color: var(--color-gray-50); border-radius: var(--radius-md);
					margin-bottom: var(--spacing-lg); display: none;">
					<div style="display: flex; justify-content: space-between; align-items: center;
						margin-bottom: var(--spacing-sm);">
						<span id="cleanup-progress-percent"
							style="font-size: var(--font-size-xl); font-weight: var(--font-weight-bold);
							color: var(--color-primary);">0%</span>
						<span id="cleanup-time-elapsed"
							style="font-size: var(--font-size-sm); color: var(--color-gray-600);">0s</span>
					</div>
					<div class="puntwork-progress">
						<div id="cleanup-progress-bar" class="puntwork-progress__bar" style="width: 0%;"></div>
					</div>
					<div style="display: flex; justify-content: space-between; align-items: center;
						margin-top: var(--spacing-sm);">
						<span id="cleanup-status-message"
							style="font-size: var(--font-size-sm); color: var(--color-gray-600);">Ready to start.</span>
						<span id="cleanup-items-left"
							style="font-size: var(--font-size-sm); color: var(--color-gray-600);">0 left</span>
					</div>
				</div>

				<div class="puntwork-card__footer">
					<div style="display: flex; gap: var(--spacing-md); align-items: center;">
						<button id="cleanup-duplicates" class="puntwork-btn puntwork-btn--danger">
							<i class="fas fa-trash-alt puntwork-btn__icon"></i>
							<span id="cleanup-text">Delete Drafts & Trash</span>
							<span id="cleanup-loading" style="display: none;">Deleting...</span>
						</button>
						<span id="cleanup-status"
							style="font-size: var(--font-size-sm); color: var(--color-gray-600);"></span>
					</div>
				</div>
			</div>

		<!-- Import Progress Section -->
		<div id="import-progress" class="puntwork-card" style="margin-bottom: var(--spacing-xl); display: none;">
			<div class="puntwork-card__header">
				<div style="display: flex; justify-content: space-between; align-items: center;">
					<div>
						<h2 class="puntwork-card__title">Import Progress</h2>
						<div style="display: flex; align-items: baseline; gap: var(--spacing-md); margin-top: var(--spacing-xs);">
							<span id="progress-percent" style="font-size: var(--font-size-3xl); font-weight: var(--font-weight-bold); color: var(--color-primary);">0%</span>
							<span style="font-size: var(--font-size-sm); color: var(--color-gray-600);">complete</span>
						</div>
					</div>
				</div>
			</div>

			<div class="puntwork-card__body">
				<!-- Progress Bar -->
				<div class="puntwork-progress" style="margin-bottom: var(--spacing-lg);">
					<div id="progress-bar" class="puntwork-progress__bar" style="width: 100%;"></div>
				</div>

				<!-- Time Counters -->
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-xl); font-size: var(--font-size-sm); color: var(--color-gray-600);">
					<span>Elapsed: <span id="time-elapsed" style="font-weight: var(--font-weight-medium);">0s</span></span>
					<span>Remaining: <span id="time-left" style="font-weight: var(--font-weight-medium);">Calculating...</span></span>
				</div>

				<!-- Statistics Grid -->
				<div class="puntwork-stats" style="margin-bottom: var(--spacing-lg);">
					<!-- Progress Overview -->
					<div class="puntwork-stat">
						<div class="puntwork-stat__icon">
							<i class="fas fa-chart-line"></i>
						</div>
						<div class="puntwork-stat__value" id="processed-items">0</div>
						<div class="puntwork-stat__label">of <span id="total-items">0</span> processed</div>
					</div>

					<!-- Success Metrics -->
					<div class="puntwork-stat puntwork-stat--success">
						<div class="puntwork-stat__icon">
							<i class="fas fa-check-circle"></i>
						</div>
						<div class="puntwork-stat__value" id="published-items">0</div>
						<div class="puntwork-stat__label">published</div>
					</div>

					<!-- Updated Items -->
					<div class="puntwork-stat puntwork-stat--success">
						<div class="puntwork-stat__icon">
							<i class="fas fa-sync-alt"></i>
						</div>
						<div class="puntwork-stat__value" id="updated-items">0</div>
						<div class="puntwork-stat__label">updated</div>
					</div>

					<!-- Skipped Items -->
					<div class="puntwork-stat puntwork-stat--warning">
						<div class="puntwork-stat__icon">
							<i class="fas fa-forward"></i>
						</div>
						<div class="puntwork-stat__value" id="skipped-items">0</div>
						<div class="puntwork-stat__label">already current</div>
					</div>

					<!-- Issues & Actions -->
					<div class="puntwork-stat puntwork-stat--danger">
						<div class="puntwork-stat__icon">
							<i class="fas fa-exclamation-triangle"></i>
						</div>
						<div class="puntwork-stat__value" id="duplicates-drafted">0</div>
						<div class="puntwork-stat__label">drafted</div>
					</div>

					<!-- Items Left -->
					<div class="puntwork-stat puntwork-stat--info">
						<div class="puntwork-stat__icon">
							<i class="fas fa-clock"></i>
						</div>
						<div class="puntwork-stat__value" id="items-left">0</div>
						<div class="puntwork-stat__label">remaining</div>
					</div>
				</div>

				<!-- Status Message -->
				<div style="background-color: var(--color-gray-50); border-radius: var(--radius-md); padding: var(--spacing-md); text-align: center; margin-bottom: var(--spacing-lg);">
					<span id="status-message" style="font-size: var(--font-size-sm); color: var(--color-gray-600);">Ready to start.</span>
					<div id="ui-update-indicator" style="font-size: var(--font-size-xs); color: var(--color-gray-500); margin-top: var(--spacing-xs); display: none;">
						<i class="fas fa-clock" style="margin-right: var(--spacing-xs);"></i>
						Last UI update: <span id="last-ui-update">Never</span>
					</div>
				</div>

			<!-- Integrated Log Section -->
			<div id="integrated-log" style="margin-top: var(--spacing-lg);">
				<div style="display: flex; align-items: center; margin-bottom: var(--spacing-md);">
					<div style="width: 6px; height: 6px; border-radius: 50%; background-color: var(--color-primary); margin-right: 10px;"></div>
					<h3 style="font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); margin: 0; color: var(--color-black);">Import Details</h3>
					<div style="margin-left: auto; font-size: var(--font-size-xs); color: var(--color-gray-600);">
						<i class="fas fa-terminal" style="margin-right: var(--spacing-xs);"></i>
						Live Log
					</div>
				</div>
				<textarea id="log-textarea" readonly style="width: 100%; height: 180px; padding: var(--spacing-md); border: 1px solid var(--color-gray-300); border-radius: var(--radius-md); font-family: var(--font-family-mono); font-size: var(--font-size-xs); line-height: var(--line-height-normal); resize: vertical; background-color: var(--color-gray-50); transition: var(--transition-fast);"></textarea>
			</div>
		</div>
	</div>

	<!-- Job Listings Section -->
	<div id="job-listings-container" class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
		<div class="puntwork-card__header">
			<h2 class="puntwork-card__title">Job Listings</h2>
			<p class="puntwork-card__subtitle">Browse and manage imported job posts.</p>
		</div>

		<!-- Job Filters -->
		<div class="puntwork-card__body">
			<div style="display: flex; gap: var(--spacing-md); align-items: center; margin-bottom: var(--spacing-lg); flex-wrap: wrap;">
				<div style="display: flex; align-items: center; gap: var(--spacing-sm);">
					<label for="job-status-filter" style="font-size: var(--font-size-sm); font-weight: var(--font-weight-medium);">Status:</label>
					<select id="job-status-filter" class="puntwork-select">
						<option value="any">All Status</option>
						<option value="publish">Published</option>
						<option value="draft">Draft</option>
						<option value="trash">Trash</option>
					</select>
				</div>
				<div style="display: flex; align-items: center; gap: var(--spacing-sm); flex: 1; min-width: 200px;">
					<label for="job-search" style="font-size: var(--font-size-sm); font-weight: var(--font-weight-medium);">Search:</label>
					<input type="text" id="job-search" class="puntwork-input" placeholder="Search job titles..." style="flex: 1;">
				</div>
				<button id="apply-job-filters" class="puntwork-btn puntwork-btn--primary">
					<i class="fas fa-search puntwork-btn__icon"></i>Apply Filters
				</button>
				<button id="clear-job-filters" class="puntwork-btn puntwork-btn--outline">
					<i class="fas fa-times puntwork-btn__icon"></i>Clear
				</button>
			</div>
		</div>

		<!-- Loading State -->
		<div id="job-listings-loading" class="puntwork-card__body" style="text-align: center; padding: var(--spacing-xl); display: none;">
			<i class="fas fa-spinner fa-spin" style="font-size: 24px; color: var(--color-primary); margin-bottom: var(--spacing-md);"></i>
			<div style="font-size: var(--font-size-base); color: var(--color-gray-600);">Loading job listings...</div>
		</div>

		<!-- Job Listings Table -->
		<div id="job-listings-table" style="display: none;">
			<div class="puntwork-table">
				<div class="puntwork-table__header">
					<div class="puntwork-table__row">
						<div class="puntwork-table__cell puntwork-table__cell--header">Job Title</div>
						<div class="puntwork-table__cell puntwork-table__cell--header">Status</div>
						<div class="puntwork-table__cell puntwork-table__cell--header">Created</div>
						<div class="puntwork-table__cell puntwork-table__cell--header">Modified</div>
						<div class="puntwork-table__cell puntwork-table__cell--header">Actions</div>
					</div>
				</div>
				<div id="job-listings-body" class="puntwork-table__body">
					<!-- Job rows will be inserted here -->
				</div>
			</div>
		</div>

		<!-- Empty State -->
		<div id="job-listings-empty" class="puntwork-card__body" style="text-align: center; padding: var(--spacing-xl); display: none;">
			<i class="fas fa-briefcase" style="font-size: 48px; color: var(--color-gray-400); margin-bottom: var(--spacing-md);"></i>
			<div style="font-size: var(--font-size-lg); font-weight: var(--font-weight-medium); color: var(--color-gray-600); margin-bottom: var(--spacing-sm);">No jobs found</div>
			<div style="font-size: var(--font-size-sm); color: var(--color-gray-500);">Try adjusting your filters or import some jobs first.</div>
		</div>

		<!-- Pagination -->
		<div id="job-pagination" class="puntwork-card__footer" style="display: none;">
			<div style="display: flex; justify-content: space-between; align-items: center;">
				<button id="job-prev-page" class="puntwork-btn puntwork-btn--outline" disabled>
					<i class="fas fa-chevron-left puntwork-btn__icon"></i>Previous
				</button>
				<span id="job-page-info" style="font-size: var(--font-size-sm); color: var(--color-gray-600);">Page 1 of 1</span>
				<button id="job-next-page" class="puntwork-btn puntwork-btn--outline" disabled>
					Next<i class="fas fa-chevron-right puntwork-btn__icon"></i>
				</button>
			</div>
		</div>
	</div>

	<script>
		// Job Listings Lazy Loading
		let currentJobPage = 1;
		let totalJobPages = 1;
		let currentJobFilters = {
			status: 'any',
			search: ''
		};

		function loadJobListings(page, filters) {
			const loading = document.getElementById('job-listings-loading');
			const empty = document.getElementById('job-listings-empty');
			const table = document.getElementById('job-listings-table');
			const body = document.getElementById('job-listings-body');

			loading.style.display = 'block';
			empty.style.display = 'none';
			table.style.display = 'none';

			const apiKey = '<?php echo esc_js( get_option( 'puntwork_api_key' ) ); ?>';
			const apiUrl = `${window.location.origin}/wp-json/puntwork/v1/jobs?api_key=${encodeURIComponent(apiKey)}&page=${page}&per_page=20&status=${encodeURIComponent(filters.status || 'any')}&search=${encodeURIComponent(filters.search || '')}`;

			// Make REST API request
			fetch(apiUrl, {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
				}
			})
			.then(response => response.json())
			.then(data => {
				loading.style.display = 'none';

				if (data.success && data.data && data.data.length > 0) {
					// Render job listings
					body.innerHTML = '';
					data.data.forEach(job => {
						const row = document.createElement('div');
						row.className = 'job-listings-row';
						row.innerHTML = `
							<div class="job-listings-title">
								<a href="${job.permalink}" target="_blank">${job.title}</a>
							</div>
							<div class="job-listings-meta">
								<span class="job-status status-${job.status}">${job.status}</span>
							</div>
							<div class="job-listings-meta">${new Date(job.date_created).toLocaleDateString()}</div>
							<div class="job-listings-meta">${new Date(job.date_modified).toLocaleDateString()}</div>
							<div class="job-listings-actions">
								<button class="job-action job-action--edit" data-id="${job.id}" title="Edit Job">
									<i class="fas fa-edit"></i>
								</button>
								<button class="job-action job-action--view" data-id="${job.id}" title="View Job">
									<i class="fas fa-eye"></i>
								</button>
							</div>
						`;
						body.appendChild(row);
					});

					table.style.display = 'block';

					// Update pagination
					currentJobPage = page;
					totalJobPages = data.pagination.total_pages;
					updateJobPagination(data.pagination);

				} else {
					empty.style.display = 'block';
				}
			})
			.catch(error => {
				console.error('Error loading job listings:', error);
				loading.style.display = 'none';
				empty.innerHTML = `
					<i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #ffc107; margin-bottom: 16px;"></i>
					<div style="font-size: 18px; font-weight: 500; color: #6c757d; margin-bottom: 8px;">Error loading jobs</div>
					<div style="font-size: 14px; color: #8e8e93;">Please try again or check the console for details.</div>
				`;
				empty.style.display = 'block';
			});
		}		function updateJobPagination(pagination) {
			const paginationEl = document.getElementById('job-pagination');
			const pageInfo = document.getElementById('job-page-info');
			const prevBtn = document.getElementById('job-prev-page');
			const nextBtn = document.getElementById('job-next-page');

			if (pagination.total_pages > 1) {
				pageInfo.textContent = `Page ${pagination.page} of ${pagination.total_pages}`;
				prevBtn.disabled = pagination.page <= 1;
				nextBtn.disabled = pagination.page >= pagination.total_pages;
				paginationEl.style.display = 'block';
			} else {
				paginationEl.style.display = 'none';
			}
		}

		// Initialize job listings on page load
		document.addEventListener('DOMContentLoaded', function() {
			loadJobListings(1, currentJobFilters);

			// Job filter event listeners
			document.getElementById('apply-job-filters').addEventListener('click', function() {
				currentJobFilters.status = document.getElementById('job-status-filter').value;
				currentJobFilters.search = document.getElementById('job-search').value.trim();
				loadJobListings(1, currentJobFilters);
			});

			document.getElementById('clear-job-filters').addEventListener('click', function() {
				document.getElementById('job-status-filter').selectedIndex = 0;
				document.getElementById('job-search').value = '';
				currentJobFilters = { status: 'any', search: '' };
				loadJobListings(1, currentJobFilters);
			});

			document.getElementById('job-search').addEventListener('keypress', function(e) {
				if (e.key == 'Enter') {
					document.getElementById('apply-job-filters').click();
				}
			});

			// Pagination event listeners
			document.getElementById('job-prev-page').addEventListener('click', function() {
				if (currentJobPage > 1) {
					loadJobListings(currentJobPage - 1, currentJobFilters);
				}
			});

			document.getElementById('job-next-page').addEventListener('click', function() {
				if (currentJobPage < totalJobPages) {
					loadJobListings(currentJobPage + 1, currentJobFilters);
				}
			});

			// Job action event listeners (delegated)
			document.getElementById('job-listings-body').addEventListener('click', function(e) {
				const target = e.target.closest('.job-action');
				if (!target) return;

				const jobId = target.dataset.id;
				if (target.classList.contains('edit-job')) {
					window.open(`<?php echo admin_url( 'post.php?action=edit&post=' ); ?>\${jobId}`, '_blank');
				} else if (target.classList.contains('view-job')) {
					window.open(`<?php echo get_permalink(); ?>?p=\${jobId}`, '_blank');
				}
			});
		});
	</script>
	<?php
}
