/**
 * Trust Procurement System — shared front-end behaviour
 */

document.addEventListener("DOMContentLoaded", function () {
  // Confirm before any destructive action (delete links/buttons).
  document.querySelectorAll("[data-confirm]").forEach(function (el) {
    el.addEventListener("click", function (e) {
      var message = el.getAttribute("data-confirm") || "Are you sure?";
      if (!window.confirm(message)) {
        e.preventDefault();
      }
    });
  });

  // Live deposit / balance calculator on the product detail and request pages.
  var qtyInput = document.getElementById("quantity");
  var depositBox = document.getElementById("deposit-calc");
  if (qtyInput && depositBox) {
    var unitPrice = parseFloat(depositBox.dataset.price || "0");
    var depositPct = parseFloat(depositBox.dataset.depositPct || "70");

    var format = function (value) {
      return "UGX " + Math.round(value).toLocaleString("en-US");
    };

    var recalc = function () {
      var qty = Math.max(1, parseInt(qtyInput.value || "1", 10));
      var total = unitPrice * qty;
      var deposit = total * (depositPct / 100);
      var balance = total - deposit;

      document.getElementById("calc-total").textContent = format(total);
      document.getElementById("calc-deposit").textContent = format(deposit);
      document.getElementById("calc-balance").textContent = format(balance);
    };

    qtyInput.addEventListener("input", recalc);
    var cartQuantityInput = document.getElementById("cart-quantity");
    if (cartQuantityInput) {
      qtyInput.addEventListener("input", function () {
        cartQuantityInput.value = Math.max(
          1,
          parseInt(qtyInput.value || "1", 10),
        );
      });
    }
    recalc();
  }

  // Shared mobile sidebar behaviour for authenticated layouts.
  var sidebarBreakpoint = 960;
  var sidebarToggle = document.getElementById("sidebarToggle");
  var sidebar = document.getElementById("appSidebar");
  var sidebarBackdrop = document.getElementById("sidebarBackdrop");

  if (sidebarToggle && sidebar) {
    var closeSidebar = function (restoreFocus) {
      sidebar.classList.remove("open");
      document.body.classList.remove("sidebar-open");
      if (sidebarBackdrop) {
        sidebarBackdrop.classList.remove("visible");
      }
      sidebarToggle.setAttribute("aria-expanded", "false");
      if (restoreFocus) {
        sidebarToggle.focus();
      }
    };

    var openSidebar = function () {
      sidebar.classList.add("open");
      document.body.classList.add("sidebar-open");
      if (sidebarBackdrop) {
        sidebarBackdrop.classList.add("visible");
      }
      sidebarToggle.setAttribute("aria-expanded", "true");
    };

    sidebarToggle.addEventListener("click", function () {
      if (sidebar.classList.contains("open")) {
        closeSidebar(false);
      } else {
        openSidebar();
      }
    });

    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener("click", function () {
        closeSidebar(true);
      });
    }

    sidebar.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        closeSidebar(false);
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && sidebar.classList.contains("open")) {
        closeSidebar(true);
      }
    });

    window.addEventListener("resize", function () {
      if (window.innerWidth > sidebarBreakpoint && sidebar.classList.contains("open")) {
        closeSidebar(false);
      }
    });
  }

  // Homepage hero — promotional slides rotate (dot navigation + auto-rotate).
  var bannerSlides = document.querySelectorAll(".banner-slide");
  var bannerDots = document.querySelectorAll(".banner-dot");
  if (bannerSlides.length > 1) {
    var currentBannerSlide = 0;
    var bannerTimer;
    var showBannerSlide = function (index) {
      bannerSlides.forEach(function (slide, i) {
        var isActive = i === index;
        slide.classList.toggle("is-active", isActive);
        slide.classList.toggle("banner-slide-hidden", !isActive);
        slide.setAttribute("aria-hidden", isActive ? "false" : "true");
      });
      bannerDots.forEach(function (dot, i) {
        dot.classList.toggle("active", i === index);
      });
    };
    var resetBannerTimer = function () {
      clearInterval(bannerTimer);
      bannerTimer = setInterval(function () {
        currentBannerSlide = (currentBannerSlide + 1) % bannerSlides.length;
        showBannerSlide(currentBannerSlide);
      }, 6000);
    };
    bannerDots.forEach(function (dot) {
      dot.addEventListener("click", function () {
        currentBannerSlide = parseInt(dot.dataset.bannerDot, 10);
        showBannerSlide(currentBannerSlide);
        resetBannerTimer();
      });
    });
    showBannerSlide(0);
    resetBannerTimer();
  }

  // Legacy homepage hero slides rotate (dot navigation + auto-rotate).
  var heroSlides = document.querySelectorAll(".hero-slide");
  var heroDots = document.querySelectorAll(".hero-dot");
  if (heroSlides.length > 1) {
    var showHeroSlide = function (index) {
      heroSlides.forEach(function (slide, i) {
        var isActive = i === index;
        slide.classList.toggle("is-active", isActive);
        slide.setAttribute("aria-hidden", isActive ? "false" : "true");
      });
      heroDots.forEach(function (dot, i) {
        dot.classList.toggle("is-active", i === index);
      });
    };
    heroDots.forEach(function (dot) {
      dot.addEventListener("click", function () {
        showHeroSlide(parseInt(dot.dataset.heroDot, 10));
      });
    });
    var currentHeroSlide = 0;
    setInterval(function () {
      currentHeroSlide = (currentHeroSlide + 1) % heroSlides.length;
      showHeroSlide(currentHeroSlide);
    }, 6000);
  }

  // Product gallery — clicking a thumbnail swaps the main image.
  var thumbs = document.querySelectorAll(".pd-thumb");
  var mainImageBox = document.getElementById("pd-main-image");
  if (thumbs.length && mainImageBox) {
    var mainImg = mainImageBox.querySelector("img");
    thumbs.forEach(function (thumb) {
      thumb.addEventListener("click", function () {
        thumbs.forEach(function (t) {
          t.classList.remove("active");
        });
        thumb.classList.add("active");
        if (mainImg) {
          mainImg.src = thumb.dataset.full;
        }
      });
    });
  }

  // Simple client-side password confirmation check on the registration form.
  var regForm = document.getElementById("register-form");
  if (regForm) {
    regForm.addEventListener("submit", function (e) {
      var pass = document.getElementById("password");
      var confirm = document.getElementById("confirm_password");
      if (pass && confirm && pass.value !== confirm.value) {
        e.preventDefault();
        window.alert("Passwords do not match.");
      }
    });
  }

  // Add accessible show/hide controls to every password field.
  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    var wrapper = input.parentElement;
    if (!wrapper || wrapper.querySelector(".password-toggle")) return;
    wrapper.classList.add("password-field");

    var toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "password-toggle";
    toggle.setAttribute("aria-label", "Show password");
    toggle.setAttribute("title", "Show password");
    toggle.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';

    toggle.addEventListener("click", function () {
      var showing = input.type === "text";
      input.type = showing ? "password" : "text";
      toggle.setAttribute(
        "aria-label",
        showing ? "Show password" : "Hide password",
      );
      toggle.setAttribute("title", showing ? "Show password" : "Hide password");
      toggle.innerHTML = showing
        ? '<i class="bi bi-eye" aria-hidden="true"></i>'
        : '<i class="bi bi-eye-slash" aria-hidden="true"></i>';
    });

    wrapper.appendChild(toggle);
  });

  // Toggle the payment method's reference-note placeholder text.
  var methodSelect = document.getElementById("method");
  var refInput = document.getElementById("reference_note");
  if (methodSelect && refInput) {
    methodSelect.addEventListener("change", function () {
      var placeholders = {
        "Mobile Money": "e.g. MTN transaction ID",
        "Bank Transfer": "e.g. Bank reference number",
        Cash: "e.g. Received by / branch",
      };
      refInput.placeholder =
        placeholders[methodSelect.value] || "Reference note";
    });
  }

  // Shared AJAX form submission for multi-step procurement flows.
  document.querySelectorAll("form[data-ajax-submit]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      if (form.dataset.ajaxInFlight === "true") return;
      event.preventDefault();

      var submitButton = event.submitter || form.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
      }
      form.dataset.ajaxInFlight = "true";
      form.setAttribute("aria-busy", "true");

      var formData = new FormData(form);
      formData.set("ajax", "1");

      fetch(form.getAttribute("action") || window.location.href, {
        method: form.getAttribute("method") || "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
        body: formData,
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("Request failed");
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

          if (result.redirect) {
            window.location.href = result.redirect;
            return;
          }

          if (result.message) {
            window.alert(result.message);
          }

          if (result.reload) {
            window.location.reload();
          }
        })
        .catch(function (error) {
          window.alert(error.message || "The request could not be completed.");
        })
        .finally(function () {
          if (submitButton) {
            submitButton.disabled = false;
          }
          form.dataset.ajaxInFlight = "false";
          form.removeAttribute("aria-busy");
        });
    });
  });

  // Refresh the products grid without a full page navigation.
  var catalogResults = document.getElementById("catalog-results");
  var catalogFilterForm = document.querySelector(".catalog-filter-form");
  var catalogSortForm = document.querySelector(".catalog-sort-form");
  var catalogResultCount = document.getElementById("catalog-result-count");
  var catalogChips = document.querySelectorAll(".catalog-category-chips a");
  if (catalogResults && catalogFilterForm && catalogSortForm) {
    var catalogUrl = catalogFilterForm.getAttribute("action") || window.location.pathname;
    var refreshCatalog = function (params, targetUrl) {
      params.set("ajax", "1");
      catalogResults.setAttribute("aria-busy", "true");
      catalogResults.classList.add("is-loading");
      fetch(catalogUrl + "?" + params.toString(), {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      })
        .then(function (response) {
          if (!response.ok) throw new Error("Catalog refresh failed");
          return response.json();
        })
        .then(function (result) {
          catalogResults.innerHTML = result.html;
          if (catalogResultCount) {
            catalogResultCount.textContent =
              result.count + (result.count === 1 ? " product" : " products");
          }
          params.delete("ajax");
          window.history.pushState(
            {},
            "",
            targetUrl || catalogUrl + "?" + params.toString(),
          );
          catalogChips.forEach(function (chip) {
            var chipUrl = new URL(chip.href, window.location.origin);
            var isActive =
              chipUrl.searchParams.get("category") ===
              (params.get("category") || "0");
            chip.classList.toggle("active", isActive);
            if (isActive) {
              chip.setAttribute("aria-current", "page");
            } else {
              chip.removeAttribute("aria-current");
            }
          });
        })
        .catch(function () {
          window.location.href =
            targetUrl || catalogUrl + "?" + params.toString();
        })
        .finally(function () {
          catalogResults.removeAttribute("aria-busy");
          catalogResults.classList.remove("is-loading");
        });
    };

    catalogFilterForm.addEventListener("submit", function (event) {
      event.preventDefault();
      var params = new URLSearchParams(new FormData(catalogFilterForm));
      params.set("sort", new FormData(catalogSortForm).get("sort") || "newest");
      refreshCatalog(params);
    });

    catalogSortForm.addEventListener("submit", function (event) {
      event.preventDefault();
      var params = new URLSearchParams(new FormData(catalogFilterForm));
      params.set("sort", new FormData(catalogSortForm).get("sort") || "newest");
      refreshCatalog(params);
    });

    catalogSortForm
      .querySelector("select")
      .addEventListener("change", function () {
        catalogSortForm.dispatchEvent(
          new Event("submit", { cancelable: true }),
        );
      });

    catalogChips.forEach(function (chip) {
      chip.addEventListener("click", function (event) {
        event.preventDefault();
        var chipUrl = new URL(chip.href, window.location.origin);
        var params = new URLSearchParams(new FormData(catalogFilterForm));
        params.set("category", chipUrl.searchParams.get("category") || "0");
        params.set(
          "sort",
          new FormData(catalogSortForm).get("sort") || "newest",
        );
        refreshCatalog(params);
      });
    });
  }
});
