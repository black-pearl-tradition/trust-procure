(function () {
  var sidebarBreakpoint = 960;

  var publicMenuToggle = document.getElementById("publicMenuToggle");
  var publicNav = document.getElementById("publicNav");

  if (publicMenuToggle && publicNav) {
    var publicMenuIcon = publicMenuToggle.querySelector("i");
    var closePublicMenu = function (restoreFocus) {
      publicNav.classList.remove("open");
      publicMenuToggle.setAttribute("aria-expanded", "false");
      publicMenuToggle.setAttribute("aria-label", "Open site navigation");
      if (publicMenuIcon) {
        publicMenuIcon.className = "bi bi-list";
      }
      if (restoreFocus) {
        publicMenuToggle.focus();
      }
    };

    publicMenuToggle.addEventListener("click", function () {
      var isOpen = publicNav.classList.toggle("open");
      publicMenuToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
      publicMenuToggle.setAttribute(
        "aria-label",
        isOpen ? "Close site navigation" : "Open site navigation",
      );
      if (publicMenuIcon) {
        publicMenuIcon.className = isOpen ? "bi bi-x-lg" : "bi bi-list";
      }
    });

    publicNav.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        closePublicMenu(false);
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && publicNav.classList.contains("open")) {
        closePublicMenu(true);
      }
    });

    window.addEventListener("resize", function () {
      if (window.innerWidth > sidebarBreakpoint && publicNav.classList.contains("open")) {
        closePublicMenu(false);
      }
    });
  }

  var userMenuWrap = document.querySelector(".user-menu-wrap");
  var userMenuButton = document.querySelector(".header-user-button");

  if (userMenuWrap && userMenuButton) {
    var closeUserMenu = function (restoreFocus) {
      userMenuWrap.classList.remove("open");
      userMenuButton.setAttribute("aria-expanded", "false");
      if (restoreFocus) {
        userMenuButton.focus();
      }
    };

    userMenuButton.addEventListener("click", function (event) {
      event.stopPropagation();
      var isOpen = userMenuWrap.classList.toggle("open");
      userMenuButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });

    document.addEventListener("click", function (event) {
      if (!userMenuWrap.contains(event.target)) {
        closeUserMenu(false);
      }
    });

    userMenuWrap.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        closeUserMenu(false);
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && userMenuWrap.classList.contains("open")) {
        closeUserMenu(true);
      }
    });
  }
})();
