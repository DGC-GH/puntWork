/**
 * Onboarding Wizard JavaScript
 * Interactive functionality for the puntWork onboarding experience
 */

class PuntworkOnboarding {
    constructor() {
        this.currentStep = 0;
        this.steps = puntworkOnboarding.steps;
        this.modal = null;
        this.overlay = null;
        this.isAnimating = false;

        this.init();
    }

    init() {
        this.createModal();
        this.bindEvents();
        this.showModal();
        this.renderCurrentStep();
    }

    createModal() {
        // Modal is already created in PHP, just get references
        this.modal = document.getElementById('puntwork-onboarding-modal');
        this.overlay = document.querySelector('.onboarding-overlay');
    }

    bindEvents() {
        // Close button
        document.getElementById('onboarding-close').addEventListener('click', () => {
            this.closeModal();
        });

        // Skip button
        document.getElementById('onboarding-skip').addEventListener('click', () => {
            this.skipOnboarding();
        });

        // Navigation buttons
        document.getElementById('onboarding-prev').addEventListener('click', () => {
            this.previousStep();
        });

        document.getElementById('onboarding-next').addEventListener('click', () => {
            this.nextStep();
        });

        // Step indicators
        document.querySelectorAll('.step-indicator').forEach((indicator, index) => {
            indicator.addEventListener('click', () => {
                if (!this.isAnimating) {
                    this.goToStep(index);
                }
            });
        });

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (!this.modal || this.modal.style.display === 'none') return;

            switch (e.key) {
                case 'Escape':
                    this.closeModal();
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    this.previousStep();
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    this.nextStep();
                    break;
                case 'Enter':
                    e.preventDefault();
                    this.nextStep();
                    break;
            }
        });

        // Click outside to close
        this.overlay.addEventListener('click', () => {
            this.closeModal();
        });
    }

    showModal() {
        this.overlay.style.display = 'block';
        this.modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Focus management
        this.modal.setAttribute('aria-hidden', 'false');
        document.getElementById('onboarding-close').focus();
    }

    closeModal() {
        this.modal.style.animation = 'slideInUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) reverse';
        setTimeout(() => {
            this.overlay.style.display = 'none';
            this.modal.style.display = 'none';
            document.body.style.overflow = '';
            this.modal.setAttribute('aria-hidden', 'true');
        }, 400);
    }

    renderCurrentStep() {
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
    }

    updateProgress() {
        const progress = ((this.currentStep + 1) / this.steps.length) * 100;
        document.getElementById('onboarding-progress-fill').style.width = `${progress}%`;
    }

    updateNavigation() {
        const prevBtn = document.getElementById('onboarding-prev');
        const nextBtn = document.getElementById('onboarding-next');
        const skipBtn = document.getElementById('onboarding-skip');

        // Previous button
        if (this.currentStep === 0) {
            prevBtn.style.display = 'none';
        } else {
            prevBtn.style.display = 'flex';
        }

        // Next button text
        const step = this.steps[this.currentStep];
        nextBtn.innerHTML = `${step.action} <i class="fas fa-arrow-right"></i>`;

        // Skip button visibility
        if (this.currentStep === this.steps.length - 1) {
            skipBtn.style.display = 'none';
            nextBtn.innerHTML = `${step.action} <i class="fas fa-check"></i>`;
        } else {
            skipBtn.style.display = 'block';
        }
    }

    updateStepIndicators() {
        document.querySelectorAll('.step-indicator').forEach((indicator, index) => {
            indicator.classList.remove('active', 'completed');

            if (index < this.currentStep) {
                indicator.classList.add('completed');
            } else if (index === this.currentStep) {
                indicator.classList.add('active');
            }
        });
    }

    nextStep() {
        if (this.isAnimating || this.currentStep >= this.steps.length - 1) {
            // Handle completion
            this.completeOnboarding();
            return;
        }

        this.goToStep(this.currentStep + 1);
    }

    previousStep() {
        if (this.isAnimating || this.currentStep <= 0) return;
        this.goToStep(this.currentStep - 1);
    }

    goToStep(stepIndex) {
        if (this.isAnimating || stepIndex === this.currentStep) return;

        this.isAnimating = true;
        const content = document.getElementById('onboarding-step-content');
        const direction = stepIndex > this.currentStep ? 'right' : 'left';

        // Animate out current step
        content.classList.add('leaving');
        setTimeout(() => {
            this.currentStep = stepIndex;
            this.renderCurrentStep();
            content.classList.remove('leaving');
            content.classList.add('entering');

            setTimeout(() => {
                content.classList.remove('entering');
                this.isAnimating = false;
            }, 300);
        }, 150);
    }

    skipOnboarding() {
        if (confirm(puntworkOnboardingL10n.confirmSkip || 'Are you sure you want to skip the onboarding? You can restart it later from the help menu.')) {
            this.closeModal();
        }
    }

    completeOnboarding() {
        // Mark as completed
        fetch(puntworkOnboarding.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'puntwork_complete_onboarding',
                nonce: puntworkOnboarding.nonce
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showCompletionMessage();
            }
        })
        .catch(error => {
            console.error('Error completing onboarding:', error);
            this.showCompletionMessage(); // Still close even if AJAX fails
        });
    }

    showCompletionMessage() {
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
    if (document.getElementById('puntwork-onboarding-modal')) {
        new PuntworkOnboarding();
    }
});

// Export for global access
window.PuntworkOnboarding = PuntworkOnboarding;