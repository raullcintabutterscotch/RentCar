"use strict";

document.addEventListener("DOMContentLoaded", function () {
    const reduceMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)"
    ).matches;

    const gsapReady =
        typeof window.gsap === "object" &&
        typeof window.ScrollTrigger === "function";

    let lenis = null;
    let gsapTickerCallback = null;

    /* =====================================================
       LENIS + GSAP SCROLLTRIGGER
    ===================================================== */

    if (gsapReady && !reduceMotion) {
        gsap.registerPlugin(ScrollTrigger);

        lenis = new Lenis({
            duration: 1.05,
            smoothWheel: true,
            syncTouch: false,
            autoRaf: false,
            anchors: false
        });

        lenis.on("scroll", function () {
            ScrollTrigger.update();
        });

        gsapTickerCallback = function (time) {
            lenis.raf(time * 1000);
        };

        gsap.ticker.add(gsapTickerCallback);
        gsap.ticker.lagSmoothing(0);

    } else if (
        !gsapReady &&
        !reduceMotion &&
        typeof window.Lenis === "function"
    ) {
        lenis = new Lenis({
            duration: 1.05,
            smoothWheel: true,
            autoRaf: true
        });
    }

    /* =====================================================
       GSAP HERO DAN SECTION COVER
    ===================================================== */

    if (gsapReady && !reduceMotion) {
        const hero = document.getElementById("beranda");
        const heroBackground = document.getElementById("heroBackground");
        const heroImage = heroBackground
            ? heroBackground.querySelector(".hero-background-image")
            : null;

        const catalog = document.getElementById("kendaraan");

        const mediaQuery = gsap.matchMedia();

        mediaQuery.add(
            {
                desktop: "(min-width: 651px)",
                mobile: "(max-width: 650px)",
                motion: "(prefers-reduced-motion: no-preference)"
            },
            function (context) {
                const isDesktop = context.conditions.desktop;

                /*
                 * Foto hero bergerak perlahan saat pengguna menggulir.
                 */

                if (hero && heroImage && isDesktop) {
                    gsap.to(heroImage, {
                        scale: 1.13,
                        yPercent: 9,
                        ease: "none",

                        scrollTrigger: {
                            trigger: hero,
                            start: "top top",
                            end: "bottom top",
                            scrub: 0.8,
                            invalidateOnRefresh: true
                        }
                    });
                }

                /*
                 * Section kendaraan bergerak ke atas dan menutupi
                 * bagian bawah gambar hero.
                 */

                if (hero && catalog) {
                    gsap.fromTo(
                        catalog,
                        {
                            y: isDesktop ? 85 : 45
                        },
                        {
                            y: 0,
                            ease: "none",

                            scrollTrigger: {
                                trigger: catalog,
                                start: "top bottom",
                                end: "top 42%",
                                scrub: 0.8,
                                invalidateOnRefresh: true
                            }
                        }
                    );
                }

                /*
                 * Animasi masuk section Tentang Kami.
                 */

                const aboutCards = document.querySelectorAll(
                    ".about-detail-card"
                );

                if (aboutCards.length) {
                    gsap.from(aboutCards, {
                        y: 24,
                        autoAlpha: 0,
                        stagger: 0.12,
                        duration: 0.65,
                        ease: "power2.out",

                        scrollTrigger: {
                            trigger: ".about-details",
                            start: "top 83%",
                            once: true
                        }
                    });
                }

                /*
                 * Animasi masuk kartu Cara Sewa.
                 */

                const processCards = document.querySelectorAll(
                    ".process-card"
                );

                if (processCards.length) {
                    gsap.from(processCards, {
                        y: 22,
                        autoAlpha: 0,
                        stagger: 0.12,
                        duration: 0.6,
                        ease: "power2.out",

                        scrollTrigger: {
                            trigger: ".process-grid",
                            start: "top 84%",
                            once: true
                        }
                    });
                }

                return function () {
                    /*
                     * GSAP menghapus tween dan trigger dari konteks
                     * media query saat kondisi berubah.
                     */
                };
            }
        );

        window.addEventListener("load", function () {
            ScrollTrigger.refresh();
        });
    }

    /* =====================================================
       NAVBAR MOBILE
    ===================================================== */

    const navWrapper = document.getElementById("siteNavWrapper");
    const navToggle = document.getElementById("navToggle");

    const navLinks = Array.from(
        document.querySelectorAll(".nav-link-item")
    );

    function closeMenu() {
        if (!navWrapper || !navToggle) {
            return;
        }

        navWrapper.classList.remove("menu-open");

        navToggle.setAttribute("aria-expanded", "false");
        navToggle.setAttribute(
            "aria-label",
            "Buka menu navigasi"
        );
    }

    if (navWrapper && navToggle) {
        navToggle.addEventListener("click", function () {
            const open = navWrapper.classList.toggle("menu-open");

            navToggle.setAttribute(
                "aria-expanded",
                String(open)
            );

            navToggle.setAttribute(
                "aria-label",
                open
                    ? "Tutup menu navigasi"
                    : "Buka menu navigasi"
            );
        });

        document.addEventListener("click", function (event) {
            if (
                navWrapper.classList.contains("menu-open") &&
                !navWrapper.contains(event.target)
            ) {
                closeMenu();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                closeMenu();
            }
        });

        window.addEventListener("resize", function () {
            if (window.innerWidth > 950) {
                closeMenu();
            }

            if (gsapReady) {
                ScrollTrigger.refresh();
            }
        });
    }

    /* =====================================================
       NAVBAR AKTIF MENGIKUTI SECTION
    ===================================================== */

    const sectionMap = navLinks
        .map(function (link) {
            const href = link.getAttribute("href");

            if (!href || !href.startsWith("#")) {
                return null;
            }

            const section = document.querySelector(href);

            return section
                ? {
                    link: link,
                    section: section,
                    id: section.id
                }
                : null;
        })
        .filter(Boolean);

    function setActiveNav(id) {
        sectionMap.forEach(function (item) {
            const active = item.id === id;

            item.link.classList.toggle("active", active);

            if (active) {
                item.link.setAttribute("aria-current", "location");
            } else {
                item.link.removeAttribute("aria-current");
            }
        });
    }

    function updateActiveNav() {
        if (!sectionMap.length) {
            return;
        }

        const header = document.querySelector(".site-header");

        const headerHeight = header
            ? header.getBoundingClientRect().height
            : 0;

        const marker =
            window.scrollY +
            headerHeight +
            Math.min(window.innerHeight * 0.28, 180);

        let current = sectionMap[0];

        sectionMap.forEach(function (item) {
            const top =
                item.section.getBoundingClientRect().top +
                window.scrollY;

            if (top <= marker) {
                current = item;
            }
        });

        /*
         * Pastikan Kontak aktif ketika pengguna berada
         * di bagian paling bawah halaman.
         */

        const nearBottom =
            window.scrollY + window.innerHeight >=
            document.documentElement.scrollHeight - 8;

        const footerLink = sectionMap.find(function (item) {
            return item.id === "footer";
        });

        if (nearBottom && footerLink) {
            current = footerLink;
        }

        setActiveNav(current.id);
    }

    let navFrame = false;

    function scheduleActiveNav() {
        if (navFrame) {
            return;
        }

        navFrame = true;

        requestAnimationFrame(function () {
            updateActiveNav();
            navFrame = false;
        });
    }

    window.addEventListener(
        "scroll",
        scheduleActiveNav,
        { passive: true }
    );

    navLinks.forEach(function (link) {
        link.addEventListener("click", function (event) {
            const href = link.getAttribute("href");

            if (!href || !href.startsWith("#")) {
                return;
            }

            const target = document.querySelector(href);

            if (!target) {
                return;
            }

            event.preventDefault();

            closeMenu();
            setActiveNav(target.id);

            /*
             * Kontak mengarah ke footer.
             * Tidak membuka halaman baru.
             */

            const header = document.querySelector(".site-header");

            const offset = -(
                header
                    ? header.getBoundingClientRect().height
                    : 70
            ) - 10;

            if (lenis) {
                lenis.scrollTo(target, {
                    offset: offset,
                    duration: 1.05
                });
            } else {
                const targetPosition =
                    window.scrollY +
                    target.getBoundingClientRect().top +
                    offset;

                window.scrollTo({
                    top: targetPosition,
                    behavior: reduceMotion ? "auto" : "smooth"
                });
            }

            const url = new URL(window.location.href);
            url.hash = target.id;

            history.replaceState(null, "", url);

            window.setTimeout(
                scheduleActiveNav,
                reduceMotion ? 0 : 150
            );
        });
    });

    scheduleActiveNav();

    /* =====================================================
       SKELETON FOTO
    ===================================================== */

    function setupImage(image, frame) {
        function loaded() {
            frame.classList.remove("has-error");
            frame.classList.add("is-loaded");
        }

        function failed() {
            frame.classList.remove("is-loaded");
            frame.classList.add("has-error");
        }

        image.addEventListener("load", loaded);
        image.addEventListener("error", failed);

        if (image.complete) {
            if (image.naturalWidth > 0) {
                loaded();
            } else {
                failed();
            }
        }
    }

    document.querySelectorAll("img[data-skeleton]").forEach(
        function (image) {
            const frame = image.closest(".skeleton-media");

            if (frame) {
                setupImage(image, frame);
            }
        }
    );

    /* =====================================================
       PENCARIAN REALTIME
    ===================================================== */

    const catalog = document.getElementById("kendaraan");
    const searchForm = document.getElementById("catalogSearchForm");
    const searchInput = document.getElementById("catalogSearchInput");

    if (!catalog || !searchInput) {
        return;
    }

    let grid = document.getElementById("vehicleGrid");
    let emptyState = document.getElementById("catalogEmpty");

    const pagination = document.getElementById("catalogPagination");
    const resultCount = document.getElementById("vehicleResultCount");
    const resultLabel = document.getElementById("vehicleResultLabel");
    const liveMessage = document.getElementById("catalogLiveMessage");

    const accountType = catalog.dataset.accountType || "guest";

    let timer = null;
    let controller = null;

    let lastQuery = searchInput.value.trim();

    if (lastQuery !== "") {
        performSearch(lastQuery);
    }

    function makeElement(tag, className, text) {
        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined && text !== null) {
            element.textContent = String(text);
        }

        return element;
    }

    function ensureGrid() {
        if (grid) {
            return grid;
        }

        grid = makeElement("div", "vehicle-grid");
        grid.id = "vehicleGrid";

        if (emptyState) {
            emptyState.before(grid);
        } else if (pagination) {
            pagination.before(grid);
        } else {
            catalog.appendChild(grid);
        }

        return grid;
    }

    function ensureEmpty() {
        if (emptyState) {
            return emptyState;
        }

        emptyState = makeElement("div", "catalog-empty");
        emptyState.id = "catalogEmpty";

        const vehicleGrid = ensureGrid();

        vehicleGrid.after(emptyState);

        return emptyState;
    }

    function showEmpty(message, error = false) {
        const empty = ensureEmpty();

        empty.replaceChildren();

        const iconBox = makeElement("div", "empty-icon");

        const icon = makeElement(
            "i",
            error
                ? "bi bi-exclamation-circle"
                : "bi bi-car-front"
        );

        icon.setAttribute("aria-hidden", "true");

        iconBox.appendChild(icon);

        const title = makeElement(
            "h3",
            "",
            error
                ? "Pencarian gagal"
                : "Kendaraan tidak ditemukan"
        );

        const description = makeElement("p", "", message);

        empty.append(iconBox, title, description);
        empty.hidden = false;

        if (grid) {
            grid.hidden = true;
        }
    }

    function showSkeletons(number = 4) {
        const vehicleGrid = ensureGrid();

        vehicleGrid.replaceChildren();
        vehicleGrid.hidden = false;

        if (emptyState) {
            emptyState.hidden = true;
        }

        if (pagination) {
            pagination.hidden = true;
        }

        if (liveMessage) {
            liveMessage.textContent = "";
        }

        for (let i = 0; i < number; i++) {
            const card = makeElement("article", "search-skeleton");

            const picture = makeElement(
                "div",
                "search-skeleton-image skeleton-block"
            );

            const body = makeElement(
                "div",
                "search-skeleton-body"
            );

            body.append(
                makeElement(
                    "div",
                    "search-skeleton-line short skeleton-block"
                ),
                makeElement(
                    "div",
                    "search-skeleton-line long skeleton-block"
                ),
                makeElement(
                    "div",
                    "search-skeleton-line medium skeleton-block"
                )
            );

            card.append(picture, body);
            vehicleGrid.appendChild(card);
        }
    }

    function createVehicleCard(item) {
        const card = makeElement("article", "vehicle-card");

        const hasPhoto = Boolean(item.foto_url);

        const media = makeElement(
            "div",
            "vehicle-media" +
            (hasPhoto ? " skeleton-media" : " no-image")
        );

        const placeholder = makeElement(
            "div",
            "image-placeholder"
        );

        const placeholderIcon = makeElement(
            "i",
            "bi bi-car-front"
        );

        placeholderIcon.setAttribute("aria-hidden", "true");

        const placeholderText = makeElement(
            "span",
            "",
            "Foto belum tersedia"
        );

        placeholder.append(placeholderIcon, placeholderText);
        media.appendChild(placeholder);

        if (hasPhoto) {
            const image = makeElement("img", "media-image");

            image.alt = item.nama || "Kendaraan";
            image.width = 600;
            image.height = 400;
            image.loading = "lazy";
            image.decoding = "async";
            image.dataset.skeleton = "true";

            setupImage(image, media);

            image.src = item.foto_url;

            media.prepend(image);
        }

        const type = makeElement(
            "span",
            "vehicle-type",
            item.tipe || item.jenis || "Kendaraan"
        );

        const status = item.status || "Tersedia";
        const statusClass = status === "Disewa"
            ? "status-rented"
            : status === "Perawatan"
                ? "status-maintenance"
                : "status-available";

        const availability = makeElement(
            "span",
            "availability-badge " + statusClass
        );

        const dot = makeElement("span", "availability-dot");
        const availabilityText = makeElement("span", "availability-label", status);

        availability.append(dot, availabilityText);
        media.append(type, availability);

        const body = makeElement("div", "vehicle-body");

        const makeText = [
            item.merk || "",
            item.tahun || ""
        ].filter(Boolean).join(" · ");

        const make = makeElement("div", "vehicle-make", makeText);

        const name = makeElement(
            "h3",
            "vehicle-name",
            item.nama || "Kendaraan"
        );

        const divider = makeElement("div", "vehicle-divider");
        const footer = makeElement("div", "vehicle-footer");
        const price = makeElement("div", "vehicle-price");

        const priceStrong = makeElement(
            "strong",
            "",
            item.harga || "Rp 0"
        );

        const perDay = makeElement("span", "", "/ hari");

        price.append(priceStrong, document.createTextNode(" "), perDay);

        let action;

        if (accountType !== "staff" && status !== "Tersedia") {
            action = makeElement("span", "vehicle-action vehicle-action-disabled");
            action.title = "Kendaraan sedang " + status.toLowerCase();
            action.setAttribute(
                "aria-label",
                "Kendaraan sedang " + status.toLowerCase()
            );
            action.appendChild(makeElement("i", "bi bi-lock-fill"));
        } else {
            action = makeElement("a", "vehicle-action");
            action.href = accountType === "staff"
                ? "./admin/kendaraan.php"
                : accountType === "customer"
                    ? "#cara-sewa"
                    : "login.php";

            action.setAttribute(
                "aria-label",
                (accountType === "staff" ? "Kelola " : "Lihat ") +
                (item.nama || "kendaraan")
            );

            action.appendChild(makeElement("i", "bi bi-arrow-up-right"));
        }

        footer.append(price, action);
        body.append(make, name, divider, footer);

        if (accountType === "guest" && status === "Tersedia") {
            const loginLink = makeElement(
                "a",
                "vehicle-login-link",
                "Masuk untuk melanjutkan"
            );

            loginLink.href = "login.php";
            body.appendChild(loginLink);
        }

        card.append(media, body);

        return card;
    }

    function updateSearchUrl(query) {
        const url = new URL(window.location.href);

        url.searchParams.delete("ajax");
        url.searchParams.delete("hal");

        if (query !== "") {
            url.searchParams.set("cari", query);
        } else {
            url.searchParams.delete("cari");
        }

        history.replaceState(null, "", url);
    }

    async function performSearch(query) {
        if (controller) {
            controller.abort();
        }

        controller = new AbortController();

        showSkeletons(4);

        if (searchForm) {
            searchForm.setAttribute("aria-busy", "true");
        }

        const url = new URL(window.location.href);

        url.searchParams.set("ajax", "vehicles");
        url.searchParams.set("q", query);
        url.searchParams.delete("hal");

        try {
            const response = await fetch(
                url.pathname + url.search,
                {
                    method: "GET",
                    headers: {
                        "X-Requested-With": "XMLHttpRequest"
                    },
                    signal: controller.signal
                }
            );

            if (!response.ok) {
                throw new Error("Permintaan pencarian gagal.");
            }

            const data = await response.json();

            if (
                !data.success ||
                !Array.isArray(data.items)
            ) {
                throw new Error(
                    data.message || "Data kendaraan tidak valid."
                );
            }

            grid = ensureGrid();
            emptyState = ensureEmpty();

            grid.replaceChildren();

            if (pagination) {
                pagination.hidden = true;
            }

            if (resultCount) {
                resultCount.textContent = Number(
                    data.count || 0
                ).toLocaleString("id-ID");
            }

            if (resultLabel) {
                resultLabel.textContent = "kendaraan";
            }

            if (data.items.length === 0) {
                grid.hidden = true;

                showEmpty(
                    "Coba nama kendaraan, merek, atau tipe lainnya."
                );
            } else {
                data.items.forEach(function (item) {
                    grid.appendChild(createVehicleCard(item));
                });

                grid.hidden = false;
                emptyState.hidden = true;
            }

            if (liveMessage) {
                if (data.count > data.shown) {
                    liveMessage.textContent =
                        "Menampilkan " +
                        Number(data.shown).toLocaleString("id-ID") +
                        " dari " +
                        Number(data.count).toLocaleString("id-ID") +
                        " kendaraan. Gunakan kata pencarian yang lebih spesifik.";
                } else {
                    liveMessage.textContent = "";
                }
            }

            updateSearchUrl(query);

            if (lenis) {
                lenis.resize();
            }

            if (gsapReady) {
                ScrollTrigger.refresh();
            }

        } catch (error) {
            if (error.name === "AbortError") {
                return;
            }

            grid = ensureGrid();
            grid.hidden = true;

            showEmpty(
                "Periksa koneksi database dan coba lagi.",
                true
            );

            console.error("RentCar search:", error);

        } finally {
            if (searchForm) {
                searchForm.removeAttribute("aria-busy");
            }
        }
    }

    function scheduleSearch(query) {
        clearTimeout(timer);

        const value = query.trim();

        /*
         * Ketika input dibersihkan, muat ulang katalog server
         * supaya pagination dan daftar lengkap kembali tersedia.
         */

        if (value === "" && lastQuery !== "") {
            const url = new URL(window.location.href);

            url.searchParams.delete("cari");
            url.searchParams.delete("hal");
            url.searchParams.delete("ajax");
            url.hash = "kendaraan";

            window.location.assign(url.pathname + url.search + url.hash);
            return;
        }

        lastQuery = value;

        if (value === "") {
            return;
        }

        timer = window.setTimeout(function () {
            performSearch(value);
        }, 250);
    }

    searchInput.addEventListener("input", function () {
        scheduleSearch(this.value);
    });

    if (searchForm) {
        searchForm.addEventListener("submit", function (event) {
            event.preventDefault();

            clearTimeout(timer);

            const value = searchInput.value.trim();

            if (value === "") {
                return;
            }

            lastQuery = value;
            performSearch(value);
        });
    }

    /*
     * Jika pengguna mengklik tombol filter, tautannya tetap
     * memuat katalog dengan kondisi jenis yang dipilih.
     */

    /* =====================================================
       TUTUP LENIS SAAT HALAMAN DITINGGALKAN
    ===================================================== */

    window.addEventListener("pagehide", function () {
        if (gsapReady && gsapTickerCallback) {
            gsap.ticker.remove(gsapTickerCallback);
        }

        if (lenis) {
            lenis.destroy();
            lenis = null;
        }
    });
});