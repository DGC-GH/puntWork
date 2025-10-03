/**
 * Onboarding Wizard JavaScript
 * Interactive functionality for the puntWork onboarding experience
 */

console.log('[PUNTWORK] onboarding.js loaded - DEBUG MODE');
console.log('[PUNTWORK] Current timestamp:', new Date().toISOString());
console.log('[PUNTWORK] Browser info:', navigator.userAgent);
console.log('[PUNTWORK] Window location:', window.location.href);
console.log('[PUNTWORK] puntworkOnboarding available:', typeof puntworkOnboarding);
if (typeof puntworkOnboarding !== 'undefined') {
    console.log('[PUNTWORK] Onboarding steps available:', puntworkOnboarding.steps ? puntworkOnboarding.steps.length : 'none');
}

class PuntworkOnboarding {
    constructor() {
        console.log('[PUNTWORK] [ONBOARDING-CONSTRUCTOR] PuntworkOnboarding constructor called');
        this.currentStep = 0;
        this.steps = puntworkOnboarding.steps;
        this.modal = null;
        this.overlay = null;
        this.isAnimating = false;

        console.log('[PUNTWORK] [ONBOARDING-CONSTRUCTOR] Initial state - currentStep:', this.currentStep, 'steps count:', this.steps.length);
        this.init();
    }

    init() {
        console.log('[PUNTWORK] [ONBOARDING-INIT] init() called');
        console.log('[PUNTWORK] [ONBOARDING-INIT] Document ready state:', document.readyState);

        this.createModal();
        this.bindEvents();
        this.showModal();
        this.renderCurrentStep();

        console.log('[PUNTWORK] [ONBOARDING-INIT] Initialization completed');
    }

    createModal() {
        console.log('[PUNTWORK] [ONBOARDING-MODAL] createModal() called');

        // Modal is already created in PHP, just get references
        this.modal = document.getElementById('puntwork-onboarding-modal');
        this.overlay = document.querySelector('.onboarding-overlay');

        console.log('[PUNTWORK] [ONBOARDING-MODAL] Modal element found:', this.modal !== null);
        console.log('[PUNTWORK] [ONBOARDING-MODAL] Overlay element found:', this.overlay !== null);
    }

    bindEvents() {
        console.log('[PUNTWORK] [ONBOARDING-EVENTS] bindEvents() called');

        // Close button
        const closeBtn = document.getElementById('onboarding-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                console.log('[PUNTWORK] [ONBOARDING-EVENTS] Close button clicked');
                this.closeModal();
            });
            console.log('[PUNTWORK] [ONBOARDING-EVENTS] Close button event bound');
        } else {
            console.warn('[PUNTWORK] [ONBOARDING-EVENTS] Close button not found');
        }

        // Skip button
        const skipBtn = document.getElementById('onboarding-skip');
        if (skipBtn) {
            skipBtn.addEventListener('click', () => {
                console.log('[PUNTWORK] [ONBOARDING-EVENTS] Skip button clicked');
                this.skipOnboarding();
            });
            console.log('[PUNTWORK] [ONBOARDING-EVENTS] Skip button event bound');
        } else {
            console.warn('[PUNTWORK] [ONBOARDING-EVENTS] Skip button not found');
        }

        // Navigation buttons
        const prevBtn = document.getElementById('onboarding-prev');
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                console.log('[PUNTWORK] [ONBOARDING-EVENTS] Previous button clicked');
                this.previousStep();
            });
            console.log('[PUNTWORK] [ONBOARDING-EVENTS] Previous button event bound');
        }

        const nextBtn = document.getElementById('onboarding-next');
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                console.log('[PUNTWORK] [ONBOARDING-EVENTS] Next button clicked');
                this.nextStep();
            });
            console.log('[PUNTWORK] [ONBOARDING-EVENTS] Next button event bound');
        }

        // Step indicators
        const indicators = document.querySelectorAll('.step-indicator');
        console.log('[PUNTWORK] [ONBOARDING-EVENTS] Found', indicators.length, 'step indicators');
        indicators.forEach((indicator, index) => {
            indicator.addEventListener('click', () => {
                if (!this.isAnimating) {
                    console.log('[PUNTWORK] [ONBOARDING-EVENTS] Step indicator', index, 'clicked');
                    this.goToStep(index);
                }
            });
        });

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (!this.modal || this.modal.style.display === 'none') return;

            switch (e.key) {
                case 'Escape':
                    console.log('[PUNTWORK] [ONBOARDING-EVENTS] Escape key pressed');
                    this.closeModal();
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    console.log('[PUNTWORK] [ONBOARDING-EVENTS] ArrowLeft key pressed');
                    this.previousStep();
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    console.log('[PUNTWORK] [ONBOARDING-EVENTS] ArrowRight key pressed');
                    this.nextStep();
                    break;
                case 'Enter':
                    e.preventDefault();
                    console.log('[PUNTWORK] [ONBOARDING-EVENTS] Enter key pressed');
                    this.nextStep();
                    break;
            }
        });

        // Click outside to close
        this.overlay.addEventListener('click', () => {
            console.log('[PUNTWORK] [ONBOARDING-EVENTS] Overlay clicked');
            this.closeModal();
        });
    }

    showModal() {
        console.log('[PUNTWORK] [ONBOARDING-MODAL] showModal() called');
        this.overlay.style.display = 'block';
        this.modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Focus management
        this.modal.setAttribute('aria-hidden', 'false');
        document.getElementById('onboarding-close').focus();

        console.log('[PUNTWORK] [ONBOARDING-MODAL] Modal shown');
    }

    closeModal() {
        console.log('[PUNTWORK] [ONBOARDING-MODAL] closeModal() called');
        this.modal.style.animation = 'slideInUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) reverse';
        setTimeout(() => {
            this.overlay.style.display = 'none';
            this.modal.style.display = 'none';
            document.body.style.overflow = '';
            this.modal.setAttribute('aria-hidden', 'true');
            console.log('[PUNTWORK] [ONBOARDING-MODAL] Modal closed');
        }, 400);
    }

    renderCurrentStep() {
        console.log('[PUNTWORK] [ONBOARDING-RENDER] renderCurrentStep() called for step', this.currentStep);
        const step = this.steps[this.currentStep];
        const content = document.getElementById('onboarding-step-content');

        content.innerHTML = `
            <div class="step-icon">
                <i class="${step.icon}"></i>
            </div>
            <h2 class="step-title">${step.title}</h2>
            <p class="step-description">${step.content}</p>
        `;

        this.updateProgress();
        this.updateNavigation();
        this.updateStepIndicators();

        console.log('[PUNTWORK] [ONBOARDING-RENDER] Step content updated');
    }

    updateProgress() {
        const progress = ((this.currentStep + 1) / this.steps.length) * 100;
        document.getElementById('onboarding-progress-fill').style.width = `${progress}%`;
        console.log('[PUNTWORK] [ONBOARDING-PROGRESS] Progress updated to', progress, '%');
    }

    updateNavigation() {
        const prevBtn = document.getElementById('onboarding-prev');
        const nextBtn = document.getElementById('onboarding-next');
        const skipBtn = document.getElementById('onboarding-skip');

        // Previous button
        if (this.currentStep === 0) {
            prevBtn.style.display = 'none';
            console.log('[PUNTWORK] [ONBOARDING-NAVIGATION] Previous button hidden');
        } else {
            prevBtn.style.display = 'flex';
            console.log('[PUNTWORK] [ONBOARDING-NAVIGATION] Previous button shown');
        }

        // Next button text
        const step = this.steps[this.currentStep];
        nextBtn.innerHTML = `${step.action} <i class="fas fa-arrow-right"></i>`;

        // Skip button visibility
        if (this.currentStep === this.steps.length - 1) {
            skipBtn.style.display = 'none';
            nextBtn.innerHTML = `${step.action} <i class="fas fa-check"></i>`;
            console.log('[PUNTWORK] [ONBOARDING-NAVIGATION] Last step - next button shows check icon');
        } else {
            skipBtn.style.display = 'block';
            console.log('[PUNTWORK] [ONBOARDING-NAVIGATION] Skip button shown');
        }
    }

    updateStepIndicators() {
        console.log('[PUNTWORK] [ONBOARDING-INDICATORS] updateStepIndicators() called');
        document.querySelectorAll('.step-indicator').forEach((indicator, index) => {
            indicator.classList.remove('active', 'completed');

            if (index < this.currentStep) {
                indicator.classList.add('completed');
                console.log('[PUNTWORK] [ONBOARDING-INDICATORS] Step', index, 'marked as completed');
            } else if (index === this.currentStep) {
                indicator.classList.add('active');
                console.log('[PUNTWORK] [ONBOARDING-INDICATORS] Step', index, 'is the current active step');
            }
        });
    }

    nextStep() {
        if (this.isAnimating || this.currentStep >= this.steps.length - 1) {
            // Handle completion
            console.log('[PUNTWORK] [ONBOARDING-STEPS] Completing onboarding process');
            this.completeOnboarding();
            return;
        }

        console.log('[PUNTWORK] [ONBOARDING-STEPS] Moving to next step:', this.currentStep + 1);
        this.goToStep(this.currentStep + 1);
    }

    previousStep() {
        if (this.isAnimating || this.currentStep <= 0) return;
        console.log('[PUNTWORK] [ONBOARDING-STEPS] Moving to previous step:', this.currentStep - 1);
        this.goToStep(this.currentStep - 1);
    }

    goToStep(stepIndex) {
        if (this.isAnimating || stepIndex === this.currentStep) return;

        this.isAnimating = true;
        this.modal.classList.add('animating');
        const content = document.getElementById('onboarding-step-content');
        const direction = stepIndex > this.currentStep ? 'right' : 'left';

        console.log('[PUNTWORK] [ONBOARDING-STEPS] Animating step change from', this.currentStep, 'to', stepIndex, 'direction:', direction);

        // Animate out current step
        content.classList.add('leaving');
        setTimeout(() => {
            this.currentStep = stepIndex;
            this.renderCurrentStep();
            content.classList.remove('leaving');
            content.classList.add('entering');

            setTimeout(() => {
                content.classList.remove('entering');
                this.modal.classList.remove('animating');
                this.isAnimating = false;
                console.log('[PUNTWORK] [ONBOARDING-STEPS] Step change animation completed');
            }, 300);
        }, 150);
    }

    skipOnboarding() {
        if (confirm(puntworkOnboardingL10n.confirmSkip || 'Are you sure you want to skip the onboarding? You can restart it later from the help menu.')) {
            console.log('[PUNTWORK] [ONBOARDING-SKIP] Onboarding skipped by user');
            this.closeModal();
        } else {
            console.log('[PUNTWORK] [ONBOARDING-SKIP] Onboarding skip canceled');
        }
    }

    completeOnboarding() {
        console.log('[PUNTWORK] [ONBOARDING-COMPLETE] completeOnboarding() called');
        // Mark as completed
        const apiKey = puntworkOnboarding.api_key;
        const apiUrl = `${window.location.origin}/wp-json/puntwork/v1/onboarding/complete?api_key=${encodeURIComponent(apiKey)}`;

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            console.log('[PUNTWORK] [ONBOARDING-COMPLETE] REST API response received:', data);
            if (data.success) {
                this.showCompletionMessage();
            } else {
                console.error('[PUNTWORK] [ONBOARDING-COMPLETE] REST API response indicates failure:', data);
                this.showCompletionMessage(); // Still close even if API fails
            }
        })
        .catch(error => {
            console.error('[PUNTWORK] [ONBOARDING-COMPLETE] Error during REST API request:', error);
            this.showCompletionMessage(); // Still close even if API fails
        });
    }

    showCompletionMessage() {
        console.log('[PUNTWORK] [ONBOARDING-COMPLETE] showCompletionMessage() called');
        const content = document.getElementById('onboarding-step-content');
        content.innerHTML = `
            <div class="step-icon" style="background: linear-gradient(135deg, #34c759 0%, #30d158 100%);">
                <i class="fas fa-check"></i>
            </div>
            <h2 class="step-title">${puntworkOnboardingL10n.completed || 'Setup Complete!'}</h2>
            <p class="step-description">${puntworkOnboardingL10n.completedDesc || 'You\'re all set! puntWork is ready to start importing job feeds.'}</p>
        `;

        // Update navigation
        document.getElementById('onboarding-skip').style.display = 'none';
        document.getElementById('onboarding-prev').style.display = 'none';
        document.getElementById('onboarding-next').innerHTML = `${puntworkOnboardingL10n.startExploring || 'Start Exploring'} <i class="fas fa-rocket"></i>`;

        // Change next button to close and redirect
        document.getElementById('onboarding-next').onclick = () => {
            this.closeModal();
            // Optional: redirect to main dashboard or first step
            setTimeout(() => {
                window.location.reload(); // Refresh to show completed state
            }, 500);
        };

        console.log('[PUNTWORK] [ONBOARDING-COMPLETE] Completion message displayed');
    }
}

// Add localized strings
const puntworkOnboardingL10n = {
    confirmSkip: 'Are you sure you want to skip the onboarding? You can restart it later from the help menu.',
    completed: 'Setup Complete!',
    completedDesc: 'You\'re all set! puntWork is ready to start importing job feeds.',
    startExploring: 'Start Exploring'
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    console.log('[PUNTWORK] [DOM-CONTENT-LOADED] Onboarding script loaded, checking for modal element');
    console.log('[PUNTWORK] [DOM-CONTENT-LOADED] puntworkOnboarding object:', typeof puntworkOnboarding, puntworkOnboarding);

    if (document.getElementById('puntwork-onboarding-modal')) {
        console.log('[PUNTWORK] [DOM-CONTENT-LOADED] Modal element found, initializing PuntworkOnboarding');
        new PuntworkOnboarding();
    } else {
        console.warn('[PUNTWORK] [DOM-CONTENT-LOADED] Modal element not found, onboarding not initialized');
    }
});

// Export for global access
window.PuntworkOnboarding = PuntworkOnboarding;