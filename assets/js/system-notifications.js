/**
 * Shared flash-message and persisted-notification popups.
 */
(function () {
  function init() {
    var toastStack = document.getElementById("systemToastStack");
    if (!toastStack) {
      toastStack = document.createElement("div");
      toastStack.id = "systemToastStack";
      toastStack.className = "system-toast-stack";
      toastStack.setAttribute("role", "status");
      toastStack.setAttribute("aria-live", "polite");
      toastStack.setAttribute("aria-relevant", "additions");
      document.body.appendChild(toastStack);
    }

    function dismiss(toast) {
      toast.classList.add("is-dismissing");
      window.setTimeout(function () {
        toast.remove();
      }, 220);
    }

    function showToast(title, message, type, link) {
      var allowedTypes = ["success", "error", "danger", "warning", "info"];
      var safeType = allowedTypes.indexOf(type) >= 0 ? type : "info";
      var toast = document.createElement("div");

      if (link) {
        try {
          var target = new URL(link, window.location.href);
          if (target.origin === window.location.origin) {
            toast = document.createElement("a");
            toast.href = target.href;
          }
        } catch (error) {
          console.warn("Ignoring an invalid notification link.", error);
        }
      }

      toast.className = "system-toast system-toast-" + safeType;
      var content = document.createElement("span");
      content.className = "system-toast-content";
      var heading = document.createElement("strong");
      heading.textContent = title;
      var body = document.createElement("span");
      body.textContent = message;
      content.appendChild(heading);
      content.appendChild(body);

      var close = document.createElement("button");
      close.type = "button";
      close.className = "system-toast-close";
      close.setAttribute("aria-label", "Dismiss notification");
      close.textContent = "\u00d7";
      close.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        dismiss(toast);
      });

      toast.appendChild(content);
      toast.appendChild(close);
      toastStack.appendChild(toast);
      window.setTimeout(function () {
        dismiss(toast);
      }, 7000);
    }

    document.querySelectorAll(".alert").forEach(function (alert) {
      var type = "info";
      alert.classList.forEach(function (className) {
        if (className.indexOf("alert-") === 0) {
          type = className.slice("alert-".length);
        }
      });
      showToast("Notice", alert.textContent.trim(), type);
      alert.remove();
    });

    var feed = document.getElementById("systemNotificationFeed");
    if (!feed) return;

    var feedUrl = feed.getAttribute("data-feed-url");
    var inboxUrl = feed.getAttribute("data-inbox-url");
    var cursor = parseInt(feed.getAttribute("data-cursor") || "0", 10);
    var polling = false;

    function poll() {
      if (polling || document.visibilityState === "hidden") return;
      polling = true;
      var url = new URL(feedUrl, window.location.href);
      url.searchParams.set("after", String(cursor));

      fetch(url.href, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        cache: "no-store",
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("Notification feed returned " + response.status);
          }
          return response.json();
        })
        .then(function (data) {
          if (!data || !data.success || !Array.isArray(data.items)) {
            throw new Error("Notification feed response was invalid");
          }
          cursor = Math.max(cursor, parseInt(data.next_cursor, 10) || cursor);
          data.items.forEach(function (item) {
            if (!item.is_read) {
              showToast(
                item.title || "Notification",
                item.message || "",
                item.type,
                item.link || inboxUrl,
              );
            }
          });
        })
        .catch(function (error) {
          console.warn("Could not refresh notifications.", error);
        })
        .finally(function () {
          polling = false;
        });
    }

    poll();
    window.setInterval(poll, 15000);
    document.addEventListener("visibilitychange", poll);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
