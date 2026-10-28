"use strict";

document.addEventListener("DOMContentLoaded", function () {
    const container = document.getElementById("container");
    const registerButton = document.getElementById("register");
    const loginButton = document.getElementById("login");

    if (!container || !registerButton || !loginButton) {
        console.error(
            "RentCar: elemen container, register, atau login tidak ditemukan."
        );
        return;
    }

    registerButton.addEventListener("click", function (event) {
        event.preventDefault();
        container.classList.add("right-panel-active");
    });

    loginButton.addEventListener("click", function (event) {
        event.preventDefault();
        container.classList.remove("right-panel-active");
    });

    [registerButton, loginButton].forEach(function (button) {
        button.addEventListener("keydown", function (event) {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                button.click();
            }
        });
    });
});
