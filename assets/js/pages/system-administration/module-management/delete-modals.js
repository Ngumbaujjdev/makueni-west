/**
 * ============================================================================
 * MODULE DELETE CONFIRMATION MODALS
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Handles delete confirmation modals for:
 * - Modules
 * - Submodules
 * - Sub-submodules
 *
 * Dependencies: Bootstrap 5, Toast.js, APIHandler, ModuleManagement
 * ============================================================================
 */

(function () {
    'use strict';

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    let pendingDelete = {
        type: null,
        id: null,
        moduleId: null,
        submoduleId: null,
        name: null
    };

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    /**
     * Initialize delete modal handlers
     */
    function initializeDeleteModals() {
        console.log('🗑️ Initializing Delete Modals...');

        // Override original delete functions
        overrideDeleteFunctions();

        // Setup confirmation button handlers
        setupConfirmationHandlers();

        console.log('✅ Delete Modals initialized');
    }

    // ========================================================================
    // OVERRIDE DELETE FUNCTIONS
    // ========================================================================

    /**
     * Override the original ModuleManagement delete functions to use modals
     */
    function overrideDeleteFunctions() {
        // Store original functions
        const originalDeleteModule = window.ModuleManagement.deleteModule;
        const originalDeleteSubmodule = window.ModuleManagement.deleteSubmodule;

        // Override deleteModule
        window.ModuleManagement.deleteModule = function (moduleId, moduleName) {
            pendingDelete = {
                type: 'module',
                id: moduleId,
                name: moduleName
            };

            document.getElementById('deleteModuleName').textContent = moduleName;
            const modal = new bootstrap.Modal(document.getElementById('deleteModuleModal'));
            modal.show();
        };

        // Override deleteSubmodule
        window.ModuleManagement.deleteSubmodule = function (moduleId, submoduleId, submoduleName) {
            pendingDelete = {
                type: 'submodule',
                id: submoduleId,
                moduleId: moduleId,
                name: submoduleName
            };

            document.getElementById('deleteSubmoduleName').textContent = submoduleName;
            const modal = new bootstrap.Modal(document.getElementById('deleteSubmoduleModal'));
            modal.show();
        };

        // Override or add deleteSubSubmodule
        window.ModuleManagement.deleteSubSubmodule = function (moduleId, submoduleId, subSubmoduleId, subSubmoduleName) {
            pendingDelete = {
                type: 'subsubmodule',
                id: subSubmoduleId,
                moduleId: moduleId,
                submoduleId: submoduleId,
                name: subSubmoduleName
            };

            document.getElementById('deleteSubSubmoduleName').textContent = subSubmoduleName;
            const modal = new bootstrap.Modal(document.getElementById('deleteSubSubmoduleModal'));
            modal.show();
        };
    }

    // ========================================================================
    // CONFIRMATION HANDLERS
    // ========================================================================

    /**
     * Setup confirmation button event handlers
     */
    function setupConfirmationHandlers() {
        // Confirm delete module
        const confirmDeleteModuleBtn = document.getElementById('confirmDeleteModuleBtn');
        if (confirmDeleteModuleBtn) {
            confirmDeleteModuleBtn.addEventListener('click', handleConfirmDeleteModule);
        }

        // Confirm delete submodule
        const confirmDeleteSubmoduleBtn = document.getElementById('confirmDeleteSubmoduleBtn');
        if (confirmDeleteSubmoduleBtn) {
            confirmDeleteSubmoduleBtn.addEventListener('click', handleConfirmDeleteSubmodule);
        }

        // Confirm delete sub-submodule
        const confirmDeleteSubSubmoduleBtn = document.getElementById('confirmDeleteSubSubmoduleBtn');
        if (confirmDeleteSubSubmoduleBtn) {
            confirmDeleteSubSubmoduleBtn.addEventListener('click', handleConfirmDeleteSubSubmodule);
        }
    }

    /**
     * Handle module deletion confirmation
     */
    async function handleConfirmDeleteModule() {
        try {
            console.log(`🗑️ Deleting module: ${pendingDelete.name} (ID: ${pendingDelete.id})`);

            const response = await APIHandler.deleteModule(pendingDelete.id);

            if (response.success) {
                Toast.success(`Module "${pendingDelete.name}" deleted successfully`);

                // Hide modal
                const modalElement = document.getElementById('deleteModuleModal');
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }

                // Reload modules
                if (typeof ModuleManagement !== 'undefined' && typeof ModuleManagement.loadModulesTab === 'function') {
                    ModuleManagement.loadModulesTab();
                }
            } else {
                throw new Error(response.message || 'Failed to delete module');
            }
        } catch (error) {
            console.error('❌ Error deleting module:', error);
            Toast.error(error.message || 'Failed to delete module');
        }
    }

    /**
     * Handle submodule deletion confirmation
     */
    async function handleConfirmDeleteSubmodule() {
        try {
            console.log(`🗑️ Deleting submodule: ${pendingDelete.name} (ID: ${pendingDelete.id})`);

            const response = await APIHandler.deleteSubmodule(pendingDelete.moduleId, pendingDelete.id);

            if (response.success) {
                Toast.success(`Submodule "${pendingDelete.name}" deleted successfully`);

                // Hide modal
                const modalElement = document.getElementById('deleteSubmoduleModal');
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }

                // Reload modules
                if (typeof ModuleManagement !== 'undefined' && typeof ModuleManagement.loadModulesTab === 'function') {
                    ModuleManagement.loadModulesTab();
                }
            } else {
                throw new Error(response.message || 'Failed to delete submodule');
            }
        } catch (error) {
            console.error('❌ Error deleting submodule:', error);
            Toast.error(error.message || 'Failed to delete submodule');
        }
    }

    /**
     * Handle sub-submodule deletion confirmation
     */
    async function handleConfirmDeleteSubSubmodule() {
        try {
            console.log(`🗑️ Deleting sub-submodule: ${pendingDelete.name} (ID: ${pendingDelete.id})`);

            const response = await APIHandler.deleteSubSubmodule(pendingDelete.moduleId, pendingDelete.submoduleId, pendingDelete.id);

            if (response.success) {
                Toast.success(`Sub-submodule "${pendingDelete.name}" deleted successfully`);

                // Hide modal
                const modalElement = document.getElementById('deleteSubSubmoduleModal');
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }

                // Reload modules
                if (typeof ModuleManagement !== 'undefined' && typeof ModuleManagement.loadModulesTab === 'function') {
                    ModuleManagement.loadModulesTab();
                }
            } else {
                throw new Error(response.message || 'Failed to delete sub-submodule');
            }
        } catch (error) {
            console.error('❌ Error deleting sub-submodule:', error);
            Toast.error(error.message || 'Failed to delete sub-submodule');
        }
    }

    // ========================================================================
    // AUTO-INITIALIZE
    // ========================================================================

    document.addEventListener('DOMContentLoaded', function () {
        // Wait for ModuleManagement to be initialized first
        setTimeout(function () {
            if (typeof ModuleManagement !== 'undefined') {
                initializeDeleteModals();
            } else {
                console.error('❌ ModuleManagement not found - delete modals cannot be initialized');
            }
        }, 200); // Wait a bit longer than the main initialization
    });

})();
