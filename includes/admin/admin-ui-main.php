<?php

/**
 * Main import UI components for job import plugin
 * Contains the primary import interface and progress display
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Render jobs dashboard UI section
 *
 * @return void
 */
function render_jobs_dashboard_ui(): void
{
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
                    <p class="puntwork-card__subtitle">Permanently delete all job posts that are in Draft or Trash status. This action cannot be undone.</p>
                </div>

                <!-- Cleanup Progress Section -->
                <div id="cleanup-progress" class="puntwork-card__body" style="background-color: var(--color-gray-50); border-radius: var(--radius-md); margin-bottom: var(--spacing-lg); display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-sm);">
                        <span id="cleanup-progress-percent" style="font-size: var(--font-size-xl); font-weight: var(--font-weight-bold); color: var(--color-primary);">0%</span>
                        <span id="cleanup-time-elapsed" style="font-size: var(--font-size-sm); color: var(--color-gray-600);">0s</span>
                    </div>
                    <div class="puntwork-progress">
                        <div id="cleanup-progress-bar" class="puntwork-progress__bar" style="width: 0%;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: var(--spacing-sm);">
                        <span id="cleanup-status-message" style="font-size: var(--font-size-sm); color: var(--color-gray-600);">Ready to start.</span>
                        <span id="cleanup-items-left" style="font-size: var(--font-size-sm); color: var(--color-gray-600);">0 left</span>
                    </div>
                </div>

                <div class="puntwork-card__footer">
                    <div style="display: flex; gap: var(--spacing-md); align-items: center;">
                        <button id="cleanup-duplicates" class="puntwork-btn puntwork-btn--danger">
                            <i class="fas fa-trash-alt puntwork-btn__icon"></i>
                            <span id="cleanup-text">Delete Drafts & Trash</span>
                            <span id="cleanup-loading" style="display: none;">Deleting...</span>
                        </button>
                        <span id="cleanup-status" style="font-size: var(--font-size-sm); color: var(--color-gray-600);"></span>
                    </div>
                </div>
            </div>

            <!-- Database Optimization Section -->
            <div class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
                <div class="puntwork-card__header">
                    <h2 class="puntwork-card__title">Database Optimization</h2>
                    <p class="puntwork-card__subtitle">Optimize database performance with proper indexes for faster imports.</p>
                </div>

                <div class="puntwork-card__body">
                    <div id="db-optimization-status" style="background-color: var(--color-gray-50); border-radius: var(--radius-md); padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
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
            </div>
        </div>
    </div>
                        <i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>
                        Loading async processing status...
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 12px; align-items: center;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px;">
                    <input type="checkbox" id="enable-async-processing" checked>
                    Enable async processing for large imports (>500 items)
                </label>
                <button id="save-async-settings" class="button button-primary" style="border-radius: 8px; padding: 8px 16px; font-size: 14px; font-weight: 500;">
                    <span id="save-async-text">Save Settings</span>
                    <span id="save-async-loading" style="display: none;">Saving...</span>
                </button>
                <span id="async-settings-status" style="font-size: 14px; color: #8e8e93;"></span>
            </div>
        </div>

        <!-- Job Listings Section with Lazy Loading -->
        <div class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
            <div class="puntwork-card__header">
                <h2 class="puntwork-card__title">Job Listings</h2>
                <p class="puntwork-card__subtitle">Browse and manage imported job posts with lazy loading for better performance.</p>
            </div>

            <div class="puntwork-card__body">
                <!-- Filters -->
                <div style="display: flex; gap: var(--spacing-md); align-items: center; margin-bottom: var(--spacing-lg); flex-wrap: wrap;">
                    <div class="puntwork-form-group" style="margin: 0;">
                        <label for="job-status-filter" class="puntwork-form-label" style="margin-bottom: var(--spacing-xs);">Status</label>
                        <select id="job-status-filter" class="puntwork-form-control" style="width: auto; min-width: 120px;">
                            <option value="any">All Status</option>
                            <option value="publish">Published</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending</option>
                            <option value="trash">Trash</option>
                        </select>
                    </div>
                    <div class="puntwork-form-group" style="margin: 0; flex: 1; min-width: 200px;">
                        <label for="job-search" class="puntwork-form-label" style="margin-bottom: var(--spacing-xs);">Search</label>
                        <input type="text" id="job-search" class="puntwork-form-control" placeholder="Search by title, company, or location...">
                    </div>
                    <div style="display: flex; gap: var(--spacing-sm); align-items: end;">
                        <button id="apply-job-filters" class="puntwork-btn puntwork-btn--primary">
                            <i class="fas fa-search puntwork-btn__icon"></i>Search
                        </button>
                        <button id="clear-job-filters" class="puntwork-btn puntwork-btn--secondary">
                            <i class="fas fa-times puntwork-btn__icon"></i>Clear
                        </button>
                    </div>
                </div>

                <!-- Loading State -->
                <div id="job-listings-loading" class="puntwork-loading" style="display: none;">
                    <i class="fas fa-spinner fa-spin puntwork-loading__spinner"></i>
                    <div>Loading job listings...</div>
                </div>

                <!-- Job Listings Table -->
                <div id="job-listings-table" style="display: none;">
                    <div style="overflow-x: auto; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md);">
                        <div id="job-listings-body" style="min-height: 200px;">
                            <!-- Job rows will be inserted here -->
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div id="job-listings-empty" class="puntwork-empty" style="display: none;">
                    <i class="fas fa-inbox puntwork-empty__icon"></i>
                    <div class="puntwork-empty__title">No jobs found</div>
                    <div class="puntwork-empty__message">Try adjusting your search criteria or import some jobs first.</div>
                </div>

                <!-- Pagination -->
                <div id="job-pagination" style="display: flex; justify-content: center; align-items: center; gap: var(--spacing-md); margin-top: var(--spacing-lg); display: none;">
                    <button id="job-prev-page" class="puntwork-btn puntwork-btn--outline" disabled>
                        <i class="fas fa-chevron-left puntwork-btn__icon"></i>Previous
                    </button>
                    <span id="job-page-info" style="font-size: var(--font-size-sm); color: var(--color-gray-600);">Page 1 of 1</span>
                    <button id="job-next-page" class="puntwork-btn puntwork-btn--outline" disabled>
                        Next<i class="fas fa-chevron-right puntwork-btn__icon puntwork-btn__icon--right"></i>
                    </button>
                </div>
            </div>
        </div>

            <!-- Job Filters -->
            <div id="job-filters" style="background-color: #f9f9f9; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label for="job-status-filter" style="font-size: 14px; font-weight: 500;">Status:</label>
                        <select id="job-status-filter" style="border-radius: 6px; padding: 6px 12px; border: 1px solid #d1d1d6; font-size: 14px;">
                            <option value="any">All Statuses</option>
                            <option value="publish">Published</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending</option>
                            <option value="trash">Trash</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label for="job-search" style="font-size: 14px; font-weight: 500;">Search:</label>
                        <input type="text" id="job-search" placeholder="Search job titles..." style="border-radius: 6px; padding: 6px 12px; border: 1px solid #d1d1d6; font-size: 14px; min-width: 200px;">
                    </div>
                    <button id="apply-job-filters" class="button button-secondary" style="border-radius: 6px; padding: 6px 16px; font-size: 14px;">Apply Filters</button>
                    <button id="clear-job-filters" class="button button-outline" style="border-radius: 6px; padding: 6px 16px; font-size: 14px; background: transparent; border: 1px solid #d1d1d6;">Clear</button>
                </div>
            </div>

            <!-- Job Listings Container -->
            <div id="job-listings-container" style="border: 1px solid #e1e1e1; border-radius: 8px; overflow: hidden;">
                <!-- Loading State -->
                <div id="job-listings-loading" style="padding: 40px; text-align: center; background: #f9f9f9;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #007aff; margin-bottom: 12px;"></i>
                    <div style="font-size: 16px; color: #8e8e93;">Loading job listings...</div>
                </div>

                <!-- Job Listings Table -->
                <div id="job-listings-table" style="display: none;">
                    <div style="background: #f8f9fa; padding: 12px 16px; border-bottom: 1px solid #e1e1e1; font-size: 14px; font-weight: 600; color: #495057;">
                        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 100px; gap: 16px; align-items: center;">
                            <span>Job Title</span>
                            <span>Status</span>
                            <span>Published</span>
                            <span>Modified</span>
                            <span>Actions</span>
                        </div>
                    </div>
                    <div id="job-listings-body">
                        <!-- Job rows will be loaded here -->
                    </div>
                </div>

                <!-- Empty State -->
                <div id="job-listings-empty" style="padding: 40px; text-align: center; display: none;">
                    <i class="fas fa-briefcase" style="font-size: 48px; color: #dee2e6; margin-bottom: 16px;"></i>
                    <div style="font-size: 18px; font-weight: 500; color: #6c757d; margin-bottom: 8px;">No jobs found</div>
                    <div style="font-size: 14px; color: #8e8e93;">Try adjusting your filters or run an import to populate jobs.</div>
                </div>
            </div>

            <!-- Pagination -->
            <div id="job-pagination" style="display: none; margin-top: 16px; text-align: center;">
                <div style="display: inline-flex; gap: 8px; align-items: center;">
                    <button id="job-prev-page" class="button button-secondary" style="border-radius: 6px; padding: 8px 16px;" disabled>
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    <span id="job-page-info" style="font-size: 14px; color: #6c757d; padding: 8px 16px;">Page 1 of 1</span>
                    <button id="job-next-page" class="button button-secondary" style="border-radius: 6px; padding: 8px 16px;" disabled>
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>

/**
 * Render main import UI section
 *
 * @return void
 */
function render_main_import_ui(): void {
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
                        <span id="import-status" style="font-size: var(--font-size-sm); color: var(--color-gray-600);"></span>
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
                    <div id="progress-bar" class="puntwork-progress__bar" style="width: 0%;"></div>
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

    <script>
        // Job Listings Lazy Loading
        let currentJobPage = 1;
        let totalJobPages = 1;
        let currentJobFilters = {
            status: 'any',
            search: ''
        };

        function loadJobListings(page = 1, filters = {}) {
            const container = document.getElementById('job-listings-container');
            const loading = document.getElementById('job-listings-loading');
            const table = document.getElementById('job-listings-table');
            const body = document.getElementById('job-listings-body');
            const empty = document.getElementById('job-listings-empty');
            const pagination = document.getElementById('job-pagination');

            // Show loading state
            loading.style.display = 'block';
            table.style.display = 'none';
            empty.style.display = 'none';
            pagination.style.display = 'none';

            // Prepare AJAX data
            const ajaxData = {
                action: 'puntwork_load_jobs',
                page: page,
                per_page: 20,
                status: filters.status || 'any',
                search: filters.search || '',
                nonce: '<?php echo wp_create_nonce("puntwork_load_jobs"); ?>'
            };

            // Make AJAX request
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(ajaxData)
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';

                if (data.success && data.data && data.data.data && data.data.data.length > 0) {
                    // Render job listings
                    body.innerHTML = '';
                    data.data.data.forEach(job => {
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
                    totalJobPages = data.data.pagination.total_pages;
                    updateJobPagination(data.data.pagination);

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
        }

        function updateJobPagination(pagination) {
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
                if (e.key === 'Enter') {
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
                    window.open(`<?php echo admin_url('post.php?action=edit&post='); ?>${jobId}`, '_blank');
                } else if (target.classList.contains('view-job')) {
                    window.open(`<?php echo get_permalink($jobId); ?>`, '_blank');
                }
            });
        });
    </script>
    <?php
}