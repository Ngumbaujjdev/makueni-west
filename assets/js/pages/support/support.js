/**
 * ============================================================================
 * SUPPORT PAGE - Help & Support System
 * ============================================================================
 * Diocese Management System - Makueni West
 * Features: Support ticket submission for authenticated and guest users
 * ============================================================================
 */

(function () {
  ("use strict");

  // ========================================================================
  // CONFIGURATION & CONSTANTS
  // ========================================================================

  const API_BASE =
    window.AppConfig.API_BASE_URL;

  const STORAGE_KEYS = {
    AUTH_TOKEN: "mwd_auth_token",
    USER_DATA: "mwd_user_data",
  };

  // ========================================================================
  // STATE MANAGEMENT
  // ========================================================================

  let authToken = null;
  let currentUser = null;
  let isAuthenticated = false;
  let quillEditor = null;
  let filePondInstance = null;

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  /**
   * Main Initialization Function
   */
  document.addEventListener("DOMContentLoaded", function () {
    console.log("🚀 SUPPORT PAGE: Starting initialization...");

    // Check authentication status
    checkAuthentication();

    // Initialize Quill Editor
    initializeQuillEditor();

    // Initialize FilePond
    initializeFilePond();

    // Fetch system admin details
    fetchSystemAdminContact();

    // Setup form handler
    setupFormHandler();

    console.log("✅ SUPPORT PAGE: Initialization complete!");
  });

  // ========================================================================
  // AUTHENTICATION CHECK
  // ========================================================================

  /**
   * Check if user is authenticated
   */
  function checkAuthentication() {
    authToken = localStorage.getItem(STORAGE_KEYS.AUTH_TOKEN);
    const userData = localStorage.getItem(STORAGE_KEYS.USER_DATA);

    if (authToken && userData) {
      isAuthenticated = true;
      currentUser = JSON.parse(userData);
      console.log("✅ User authenticated:", currentUser);
      preFillUserForm();
    } else {
      isAuthenticated = false;
      console.log("ℹ️ Guest user (not authenticated)");
      showGuestNotice();
    }
  }

  /**
   * Pre-fill form with user data (authenticated users)
   */
  function preFillUserForm() {
    // Build full name from firstname + lastname
    const fullName = `${currentUser.firstname || ""} ${
      currentUser.lastname || ""
    }`.trim();

    // Pre-fill fields
    document.getElementById("supportName").value =
      fullName || currentUser.username || "";
    document.getElementById("supportEmail").value = currentUser.email || "";
    document.getElementById("supportPhone").value = currentUser.phone || "";

    // ✅ IMPORTANT: Keep fields editable so values are sent to API
    // Just add a visual indicator that they're from the user's profile
    document.getElementById("supportName").classList.add("bg-light");
    document.getElementById("supportEmail").classList.add("bg-light");
    document.getElementById("supportPhone").classList.add("bg-light");

    // Show auth badge
    const authBadge = document.getElementById("authBadge");
    if (authBadge) {
      document.getElementById("authUserName").textContent =
        fullName || currentUser.username;
      document.getElementById("authUserPosition").textContent =
        currentUser.position || "User";
      authBadge.classList.remove("d-none");
    }

    console.log("✅ Form pre-filled with user data:", {
      name: fullName,
      email: currentUser.email,
      phone: currentUser.phone,
    });
  }

  /**
   * Show guest notice (for non-authenticated users)
   */
  function showGuestNotice() {
    const guestNotice = document.getElementById("guestNotice");
    if (guestNotice) {
      guestNotice.classList.remove("d-none");
    }
    console.log("ℹ️ Guest notice displayed");
  }

  // ========================================================================
  // QUILL EDITOR INITIALIZATION
  // ========================================================================

  /**
   * Initialize Quill Rich Text Editor
   */
  function initializeQuillEditor() {
    const editorElement = document.getElementById("editor");

    if (!editorElement) {
      console.error("❌ Quill editor element not found!");
      return;
    }

    quillEditor = new Quill("#editor", {
      theme: "snow",
      placeholder: "Describe your issue in detail...",
      modules: {
        toolbar: [
          [{ header: [1, 2, false] }],
          ["bold", "italic", "underline"],
          [{ list: "ordered" }, { list: "bullet" }],
          ["link"],
          ["clean"],
        ],
      },
    });

    console.log("✅ Quill editor initialized");
  }

  // ========================================================================
  // FILEPOND INITIALIZATION
  // ========================================================================

  /**
   * Initialize FilePond File Uploader
   */
  function initializeFilePond() {
    const inputElement = document.querySelector(".multiple-filepond");

    if (!inputElement) {
      console.error("❌ FilePond input element not found!");
      return;
    }

    // Register FilePond plugins
    FilePond.registerPlugin(
      FilePondPluginImagePreview,
      FilePondPluginFileValidateSize,
      FilePondPluginFileValidateType
    );

    // Create FilePond instance
    filePondInstance = FilePond.create(inputElement, {
      allowMultiple: true,
      maxFiles: 5,
      maxFileSize: "5MB",
      acceptedFileTypes: [
        "image/jpeg",
        "image/png",
        "image/gif",
        "image/webp",
        "application/pdf",
        "application/msword",
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "application/vnd.ms-excel",
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      ],
      labelIdle:
        'Drag & Drop files or <span class="filepond--label-action">Browse</span>',
    });

    console.log("✅ FilePond initialized");
  }

  // ========================================================================
  // FETCH SYSTEM ADMIN CONTACT
  // ========================================================================

  /**
   * Fetch and display system administrator contact details
   */
  async function fetchSystemAdminContact() {
    try {
      console.log("📡 Fetching system admin contact...");

      const response = await fetch(`${API_BASE}/system-admin/contact`, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
      });

      console.log("📨 API Response Status:", response.status);

      if (!response.ok) {
        console.warn("⚠️ Could not fetch system admin contact");
        displayDefaultAdminContact();
        return;
      }

      const result = await response.json();
      console.log("📦 API Response Data:", result);

      if (result.success && result.data) {
        displayAdminContact(result.data);
      } else {
        displayDefaultAdminContact();
      }
    } catch (error) {
      console.error("❌ Error fetching system admin contact:", error);
      displayDefaultAdminContact();
    }
  }

  /**
   * Display admin contact details in sidebar
   */
  function displayAdminContact(admin) {
    const fullName =
      admin.name || `${admin.firstname || ""} ${admin.lastname || ""}`.trim();
    const initials = getInitials(fullName);

    document.getElementById("adminAvatar").textContent = initials;
    document.getElementById("adminName").textContent = fullName;
    document.getElementById("adminPosition").textContent =
      admin.position || "System Administrator";
    document.getElementById("adminEmail").textContent = admin.email;
    document.getElementById("adminPhone").textContent = admin.phone;

    console.log("✅ System admin contact displayed:", fullName);
  }

  /**
   * Display default admin contact if API fails
   */
  function displayDefaultAdminContact() {
    document.getElementById("adminName").textContent = "System Administrator";
    document.getElementById("adminEmail").textContent =
      "support@makueniwestdiocese.or.ke";
    document.getElementById("adminPhone").textContent = "+254 XXX XXX XXX";
    console.log("ℹ️ Default admin contact displayed");
  }

  // ========================================================================
  // FORM SUBMISSION
  // ========================================================================

  /**
   * Setup form submission handler
   */
  function setupFormHandler() {
    const form = document.getElementById("supportForm");
    const submitBtn = document.getElementById("submitSupportBtn");

    if (!form || !submitBtn) {
      console.error("❌ Form or submit button not found!");
      return;
    }

    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      await handleFormSubmit();
    });

    console.log("✅ Form handler setup complete");
  }

  /**
   * Handle form submission
   */
  async function handleFormSubmit() {
    const submitBtn = document.getElementById("submitSupportBtn");

    // ✅ CLIENT-SIDE VALIDATION FIRST
    if (!validateForm()) {
      return;
    }

    // Get Quill editor content
    const messageContent = quillEditor.root.innerHTML;

    if (!messageContent || messageContent.trim() === "<p><br></p>") {
      Toast.error("Please enter a message describing your issue");
      return;
    }

    // Show loading state
    const originalBtnText = submitBtn.innerHTML;
    showButtonSpinner(submitBtn, "Submitting...");

    try {
      // Prepare FormData
      const formData = new FormData();

      // ✅ ALWAYS ADD name, email, phone (whether auth or guest)
      formData.append(
        "name",
        document.getElementById("supportName").value.trim()
      );
      formData.append(
        "email",
        document.getElementById("supportEmail").value.trim()
      );
      formData.append(
        "phone",
        document.getElementById("supportPhone").value.trim()
      );

      // Add other form fields
      formData.append(
        "category",
        document.getElementById("supportCategory").value
      );
      formData.append(
        "priority",
        document.getElementById("supportPriority").value
      );
      formData.append(
        "subject",
        document.getElementById("supportSubject").value.trim()
      );
      formData.append("message", messageContent);

      // Add FilePond files
      const files = filePondInstance.getFiles();
      files.forEach((fileItem) => {
        formData.append("attachments[]", fileItem.file);
      });

      console.log("📤 Submitting form data...", {
        name: formData.get("name"),
        email: formData.get("email"),
        phone: formData.get("phone"),
        category: formData.get("category"),
        priority: formData.get("priority"),
        subject: formData.get("subject"),
        filesCount: files.length,
        isAuthenticated: isAuthenticated,
      });

      // Prepare headers
      const headers = {
        Accept: "application/json",
      };

      // Add auth token if authenticated
      if (isAuthenticated) {
        headers["Authorization"] = `Bearer ${authToken}`;
      }

      // Submit to API
      const response = await fetch(`${API_BASE}/support-tickets`, {
        method: "POST",
        headers: headers,
        body: formData,
      });

      const result = await response.json();
      console.log("📨 Submission response:", result);

      if (response.ok && result.success) {
        // Success!
        handleSubmitSuccess(result.data);
      } else {
        // Error - handle validation errors
        handleSubmitError(result, response.status);
      }
    } catch (error) {
      console.error("❌ Form submission error:", error);
      Toast.error("Network error. Please check your connection and try again.");
    } finally {
      hideButtonSpinner(submitBtn, originalBtnText);
    }
  }

  /**
   * Handle successful form submission
   */
  function handleSubmitSuccess(data) {
    Toast.success(
      `Support request submitted successfully! Ticket #${data.ticket_number}`
    );

    // Reset form
    document.getElementById("supportForm").reset();
    quillEditor.root.innerHTML = "";
    filePondInstance.removeFiles();

    // Re-apply pre-filled data if authenticated
    if (isAuthenticated) {
      setTimeout(() => {
        preFillUserForm();
      }, 100);
    }

    console.log("✅ Ticket submitted:", data.ticket_number);
  }

  /**
   * ✅ IMPROVED: Handle form submission error with detailed validation messages
   */
  function handleSubmitError(result, statusCode) {
    console.error("❌ Submission failed:", result);

    // Handle validation errors (422)
    if (statusCode === 422 && result.errors) {
      // Show first validation error
      const firstErrorKey = Object.keys(result.errors)[0];
      const firstError = result.errors[firstErrorKey][0];
      Toast.error(`Validation Error: ${firstError}`);

      // Log all errors for debugging
      console.error("Validation errors:", result.errors);
    }
    // Handle other errors
    else {
      const message =
        result.message || "Failed to submit support request. Please try again.";
      Toast.error(message);
    }
  }

  // ========================================================================
  // FORM VALIDATION
  // ========================================================================

  /**
   * ✅ IMPROVED: Client-side validation with better error messages
   */
  function validateForm() {
    const name = document.getElementById("supportName").value.trim();
    const email = document.getElementById("supportEmail").value.trim();
    const phone = document.getElementById("supportPhone").value.trim();
    const category = document.getElementById("supportCategory").value;
    const priority = document.getElementById("supportPriority").value;
    const subject = document.getElementById("supportSubject").value.trim();

    // Validate name
    if (!name) {
      Toast.error("Please enter your full name");
      document.getElementById("supportName").focus();
      return false;
    }

    if (name.length < 3) {
      Toast.error("Name must be at least 3 characters long");
      document.getElementById("supportName").focus();
      return false;
    }

    // Validate email
    if (!email) {
      Toast.error("Please enter your email address");
      document.getElementById("supportEmail").focus();
      return false;
    }

    if (!isValidEmail(email)) {
      Toast.error(
        "Please enter a valid email address (e.g., example@domain.com)"
      );
      document.getElementById("supportEmail").focus();
      return false;
    }

    // Validate phone
    if (!phone) {
      Toast.error("Please enter your phone number");
      document.getElementById("supportPhone").focus();
      return false;
    }

    if (phone.length < 10) {
      Toast.error("Please enter a valid phone number");
      document.getElementById("supportPhone").focus();
      return false;
    }

    // Validate category
    if (!category) {
      Toast.error("Please select a category for your support request");
      document.getElementById("supportCategory").focus();
      return false;
    }

    // Validate priority
    if (!priority) {
      Toast.error("Please select a priority level");
      document.getElementById("supportPriority").focus();
      return false;
    }

    // Validate subject
    if (!subject) {
      Toast.error("Please enter a subject for your request");
      document.getElementById("supportSubject").focus();
      return false;
    }

    if (subject.length < 5) {
      Toast.error("Subject must be at least 5 characters long");
      document.getElementById("supportSubject").focus();
      return false;
    }

    return true;
  }

  /**
   * Validate email format
   */
  function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
  }

  // ========================================================================
  // UTILITY FUNCTIONS
  // ========================================================================

  /**
   * Get initials from full name
   */
  function getInitials(fullName) {
    if (!fullName) return "?";
    const names = fullName.trim().split(" ");
    if (names.length === 1) return names[0].charAt(0).toUpperCase();
    return (
      names[0].charAt(0) + names[names.length - 1].charAt(0)
    ).toUpperCase();
  }

  /**
   * Show spinner on button
   */
  function showButtonSpinner(button, text) {
    if (!button) return;
    button.disabled = true;
    button.innerHTML = `
      <span class="spinner-border spinner-border-sm me-2" role="status"></span>
      ${text}
    `;
  }

  /**
   * Hide spinner on button
   */
  function hideButtonSpinner(button, originalText) {
    if (!button) return;
    button.disabled = false;
    button.innerHTML = originalText;
  }

  // ========================================================================
  // PUBLIC API (if needed)
  // ========================================================================

  window.SupportPage = {
    resetForm: function () {
      document.getElementById("supportForm").reset();
      quillEditor.root.innerHTML = "";
      filePondInstance.removeFiles();
      if (isAuthenticated) {
        preFillUserForm();
      }
    },
  };
})();
