/**
 * Trust Procurement System — point-of-sale terminal
 *
 * The till is built to work without JavaScript: every control is a real form
 * posting to admin/pos/cart.php, which answers with the freshly rendered cart
 * panel. This file is the progressive enhancement on top of that — it submits
 * those same forms in the background and swaps the panel in place, so an
 * operator can scan and add items without the page reloading between them.
 *
 * Two details worth knowing:
 *
 * - Submission is handled by one delegated listener on the document, not by
 *   listeners on each form. The panel's HTML is replaced on every change, which
 *   would otherwise discard any listener attached to the markup inside it.
 * - The "Complete sale" confirmation is re-bound after each swap. The shared
 *   script.js binds [data-confirm] once at load, and the button inside the
 *   checkout panel is new markup each time.
 */
document.addEventListener("DOMContentLoaded", function () {
  var CART_ACTION = "/admin/pos/cart.php";

  var panel = document.getElementById("pos-cart-panel");
  var panelBody = panel ? panel.querySelector(".panel") : null;
  if (!panel || !panelBody) return; // not the till; nothing to enhance.

  // A quiet status line above the panel. The page reloads on every error path
  // in the no-JavaScript version, which is how it reports problems; in place,
  // the message is announced here instead of in an alert dialog.
  var status = document.createElement("div");
  status.className = "pos-status";
  status.setAttribute("role", "status");
  status.setAttribute("aria-live", "polite");
  panel.insertBefore(status, panelBody);

  var inFlight = false;
  var statusTimer = null;

  var setStatus = function (message, kind) {
    window.clearTimeout(statusTimer);
    status.textContent = message || "";
    status.className = "pos-status" + (kind ? " is-" + kind : "");

    if (message) {
      statusTimer = window.setTimeout(function () {
        status.textContent = "";
        status.className = "pos-status";
      }, 4000);
    }
  };

  function isCartForm(form) {
    return (form.getAttribute("action") || "").indexOf(CART_ACTION) !== -1;
  }

  // --- Customer details ----------------------------------------------------
  // These fields sit inside the panel that every cart change re-renders, so
  // unsaved typing has to be carried along with whatever the operator does next
  // rather than being wiped by the swap.
  var savedContact = { name: "", phone: "", email: "" };

  var readContact = function () {
    var form = panelBody.querySelector(".pos-contact-form");
    if (!form) return { name: "", phone: "", email: "" };
    return {
      name: (form.querySelector('input[name="contact_name"]') || {}).value || "",
      phone: (form.querySelector('input[name="contact_phone"]') || {}).value || "",
      email: (form.querySelector('input[name="contact_email"]') || {}).value || "",
    };
  };

  var rememberContact = function () {
    savedContact = readContact();
    syncSaveButton();
  };

  var contactIsDirty = function (current) {
    return (
      current.name !== savedContact.name ||
      current.phone !== savedContact.phone ||
      current.email !== savedContact.email
    );
  };

  // Nothing to save when both fields that identify a walk-in are empty and
  // nothing has been typed since the last save.
  var syncSaveButton = function () {
    var button = panelBody.querySelector("#posContactSave");
    if (!button) return;
    var current = readContact();
    var hasSomething = current.name.trim() !== "" || current.phone.trim() !== "" || current.email.trim() !== "";
    button.disabled = !hasSomething && !contactIsDirty(current);
  };

  var looksLikeEmail = function (value) {
    return value === "" || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  };

  // The form that saves the details carries them itself, so it is left alone
  // rather than being re-packed from the fields.
  var isContactForm = function (form) {
    return form.classList.contains("pos-contact-form");
  };

  // A barcode scanner types into whatever has focus and presses Enter, so the
  // quick-add box has to keep focus across the panel swap.
  var focusQuickAdd = function () {
    var quickAdd = document.querySelector('#posScanForm input[name="code"]');
    if (quickAdd) quickAdd.focus();
  };

  var bindCheckoutConfirm = function () {
    var button = document.querySelector("#posCheckoutForm button[data-confirm]");
    if (!button || button.dataset.confirmBound === "true") return;

    button.dataset.confirmBound = "true";
    button.addEventListener("click", function (event) {
      if (!window.confirm(button.getAttribute("data-confirm") || "Are you sure?")) {
        event.preventDefault();
      }
    });
  };

  var applyResult = function (result) {
    if (result.cart_html) {
      panelBody.innerHTML = result.cart_html;
    }
    if (result.message) {
      setStatus(result.message, result.success ? "ok" : "error");
    }
    bindCheckoutConfirm();
    focusQuickAdd();
    // The panel is new markup, so the saved state has to be re-read from it.
    rememberContact();
  };

  document.addEventListener("submit", function (event) {
    var form = event.target;
    // The checkout form posts elsewhere and must navigate to the receipt.
    if (!form || form.tagName !== "FORM" || !isCartForm(form)) return;

    event.preventDefault();
    if (inFlight) return;

    // Destructive actions confirm before they go, so "Clear" cannot be a slip of
    // the finger that throws away a half-typed customer.
    var confirmMessage = form.getAttribute("data-confirm");
    if (confirmMessage && !window.confirm(confirmMessage)) return;

    // The scan box lives in the toolbar, not inside the panel we swap, so it
    // keeps whatever was last typed into it. A scanner types at the cursor
    // rather than replacing the field, so a stale barcode left behind would be
    // prefixed to the next one and match nothing.
    var codeField =
      form.id === "posScanForm" ? form.querySelector('input[name="code"]') : null;

    var submitButton = event.submitter || form.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = true;
    inFlight = true;
    panel.setAttribute("aria-busy", "true");
    setStatus("");

    var tokenField = form.querySelector('input[name="csrf_token"]');
    var payload = new FormData(form);

    // Carry any unsaved customer details with this action, so a cart change
    // never silently discards what was just typed. A malformed email is left
    // behind rather than blocking the sale, and the operator is told.
    var contact = readContact();
    var emailRejected = false;
    if (!isContactForm(form) && contactIsDirty(contact)) {
      payload.set("contact_name", contact.name);
      payload.set("contact_phone", contact.phone);
      if (looksLikeEmail(contact.email)) {
        payload.set("contact_email", contact.email);
      } else {
        payload.set("contact_email", savedContact.email);
        emailRejected = true;
      }
    }

    fetch(form.getAttribute("action") || window.location.href, {
      method: "POST",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": tokenField ? tokenField.value : "",
      },
      body: payload,
    })
      .then(function (response) {
        // The till's session or CSRF token has gone; only a reload recovers.
        if (response.status === 419) {
          window.location.reload();
          return null;
        }
        return response.json().catch(function () {
          return null;
        });
      })
      .then(function (result) {
        if (!result) {
          window.location.reload();
          return;
        }
        applyResult(result);
        if (emailRejected) {
          setStatus(
            "Cart updated. That email address looks wrong, so it was left as it was.",
            "error"
          );
        }
        if (codeField) {
          if (result.success) {
            // Cleared outright, ready for the next scan.
            codeField.value = "";
          } else {
            // Left in place so it can be corrected, but selected so the next
            // keystroke or scan replaces it instead of appending to it.
            codeField.select();
          }
        }
      })
      .catch(function () {
        setStatus("The till could not reach the server. Check the connection and try again.", "error");
      })
      .finally(function () {
        inFlight = false;
        panel.removeAttribute("aria-busy");
        if (submitButton) submitButton.disabled = false;
      });
  });

  // No bindCheckoutConfirm() call here on purpose. script.js binds every
  // [data-confirm] once at startup, and the checkout button it binds is the one
  // already in the page — binding again would show the operator two confirm
  // dialogs. applyResult() re-binds after each swap, because that markup is new
  // and script.js has already been and gone by then.
  bindCheckoutConfirm();
  rememberContact();

  // The panel is re-rendered on every change, so the Save button's state is kept
  // up to date by delegation rather than by re-binding after each swap.
  panelBody.addEventListener("input", function (event) {
    var field = event.target;
    if (field && field.name && field.name.indexOf("contact_") === 0) {
      syncSaveButton();
    }
  });
});
