(function () {
	document.querySelectorAll("[data-pca-copy]").forEach(function (button) {
		button.addEventListener("click", function () {
			var selector = button.getAttribute("data-pca-copy");
			var field = selector ? document.querySelector(selector) : null;
			if (!field) {
				return;
			}
			var value = field.value || field.textContent || "";
			var done = function () {
				var original = button.textContent;
				button.textContent = "Копирано";
				window.setTimeout(function () {
					button.textContent = original;
				}, 1500);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(value).then(done).catch(function () {
					field.focus();
					field.select();
				});
				return;
			}
			field.focus();
			field.select();
			document.execCommand("copy");
			done();
		});
	});

	document.querySelectorAll(".pca-revoke").forEach(function (link) {
		link.addEventListener("click", function (event) {
			var message = link.getAttribute("data-confirm") || "Да се продължи ли?";
			if (!window.confirm(message)) {
				event.preventDefault();
			}
		});
	});
})();
