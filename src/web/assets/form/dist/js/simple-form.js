(function () {
    "use strict";

    function initForm(form) {
        if (form.dataset.simpleFormBound === "1") {
            return;
        }
        form.dataset.simpleFormBound = "1";

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var formData = new FormData(form);
            fetch(form.action, {
                method: "POST",
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        alert(data.message || "Form submitted successfully!");
                        form.reset();
                    } else if (data.errors) {
                        Object.keys(data.errors).forEach(function (fieldKey) {
                            var errorMessages = data.errors[fieldKey];
                            var fieldElement = form.querySelector("[name=\"" + fieldKey + "\"]");
                            if (fieldElement) {
                                var errorDiv = document.createElement("div");
                                errorDiv.className = "form-error";
                                errorDiv.innerHTML = errorMessages.join("<br>");
                                fieldElement.parentNode.appendChild(errorDiv);
                            }
                        });
                    }
                })
                .catch(function (error) { console.error("Form submission error:", error); });
        });
    }

    function init() {
        document.querySelectorAll(".simple-form").forEach(initForm);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
