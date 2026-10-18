(function () {
	function showError(button, message) {
		var root = button.closest(".pca-paywall");
		var error = root ? root.querySelector("[data-pca-error]") : null;
		if (!error) {
			window.alert(message);
			return;
		}
		error.hidden = false;
		error.textContent = message;
	}

	function bind(button) {
		if (button.getAttribute("data-pca-bound") === "1") {
			return;
		}
		button.setAttribute("data-pca-bound", "1");
		button.addEventListener("click", function () {
			var config = window.pcaPaywall || {};
			var original = button.textContent;
			var root = button.closest(".pca-paywall");
			var errorNode = root ? root.querySelector("[data-pca-error]") : null;
			if (errorNode) {
				errorNode.hidden = true;
				errorNode.textContent = "";
			}
			button.disabled = true;
			button.setAttribute("aria-busy", "true");

			var body = new window.URLSearchParams();
			body.set("action", "pca_create_checkout");
			body.set("nonce", config.nonce || "");
			body.set("post_id", button.getAttribute("data-post-id") || "");

			window.fetch(config.ajaxUrl, {
				method: "POST",
				credentials: "same-origin",
				headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
				body: body.toString()
			}).then(function (response) {
				return response.json().then(function (data) {
					return { ok: response.ok, data: data };
				});
			}).then(function (result) {
				var payload = result.data || {};
				if (!result.ok || !payload.success || !payload.data || !payload.data.url) {
					var message = payload.data && payload.data.message ? payload.data.message : (config.genericError || "Error");
					throw new Error(message);
				}
				var url = new window.URL(payload.data.url);
				if (url.protocol !== "https:" || !/(^|\.)stripe\.com$/.test(url.hostname)) {
					throw new Error(config.genericError || "Error");
				}
				window.location.assign(url.toString());
			}).catch(function (error) {
				button.disabled = false;
				button.removeAttribute("aria-busy");
				button.textContent = original;
				showError(button, error && error.message ? error.message : (config.genericError || "Error"));
			});
		});
	}

	function init() {
		document.querySelectorAll(".pca-buy").forEach(bind);
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
