"use strict";

document.addEventListener("DOMContentLoaded", function () {
    const data = window.RentCarDashboard || {};

    /* DATA DASHBOARD DARI DATABASE, diperbarui tanpa reload penuh. */
    const liveStatNodes = document.querySelectorAll('[data-live-stat]');
    let liveRequestRunning = false;

    const numberFormat = (value) => new Intl.NumberFormat('id-ID', {
        maximumFractionDigits: 0
    }).format(Number(value) || 0);

    const refreshLiveDashboard = async () => {
        if (liveRequestRunning || document.visibilityState === 'hidden') return;
        liveRequestRunning = true;
        try {
            const response = await fetch('dashboard-live.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) return;
            const payload = await response.json();
            if (!payload?.success || !payload.stats) return;

            liveStatNodes.forEach((node) => {
                const key = node.dataset.liveStat;
                if (!(key in payload.stats)) return;
                const value = Number(payload.stats[key]) || 0;
                node.textContent = node.dataset.money === '1'
                    ? `Rp ${numberFormat(value)}`
                    : numberFormat(value);
            });

            const dot = document.getElementById('notificationDot');
            if (dot) {
                const shouldShow = Number(payload.stats.overdue || 0) > 0
                    || Number(payload.stats.pendingPayments || 0) > 0;
                dot.hidden = !shouldShow;
            }
            document.documentElement.dataset.dashboardUpdatedAt = payload.updatedAt || '';
        } catch (_) {
            /* Dashboard tetap memakai data server terakhir jika jaringan sementara gagal. */
        } finally {
            liveRequestRunning = false;
        }
    };

    refreshLiveDashboard();
    window.setInterval(refreshLiveDashboard, 15000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refreshLiveDashboard();
    });

    /* LENIS SMOOTH SCROLL */
    const scrollWrapper = document.getElementById("dashboardScroll");
    const scrollContent = document.getElementById("dashboardContent");

    let lenis = null;

    if (
        scrollWrapper &&
        scrollContent &&
        typeof window.Lenis === "function"
    ) {
        lenis = new window.Lenis({
            wrapper: scrollWrapper,
            content: scrollContent,
            duration: 1,
            smoothWheel: true,
            syncTouch: false,
            autoRaf: true,
            anchors: true
        });
    }

    /* GRAFIK TRANSAKSI */
    const salesCanvas = document.getElementById("salesChart");

    if (salesCanvas && typeof window.Chart === "function") {
        const context = salesCanvas.getContext("2d");
        const gradient = context.createLinearGradient(0, 0, 0, 220);

        gradient.addColorStop(0, "rgba(227, 38, 54, 0.27)");
        gradient.addColorStop(1, "rgba(227, 38, 54, 0)");

        new Chart(context, {
            type: "line",

            data: {
                labels: data.chartLabels || [],

                datasets: [{
                    label: "Jumlah transaksi",
                    data: data.chartValues || [],
                    borderColor: "#e32636",
                    backgroundColor: gradient,
                    borderWidth: 2,
                    pointBackgroundColor: "#ff3547",
                    pointBorderColor: "#ff3547",
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    tension: 0.32,
                    fill: true
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                interaction: {
                    intersect: false,
                    mode: "index"
                },

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        backgroundColor: "#1a1d21",
                        borderColor: "#383d44",
                        borderWidth: 1,
                        titleColor: "#f5f6f7",
                        bodyColor: "#c7ccd2",
                        padding: 10
                    }
                },

                scales: {
                    x: {
                        grid: {
                            color: "rgba(166, 173, 183, 0.07)"
                        },

                        ticks: {
                            color: "#a6adb7",
                            maxRotation: 0,
                            autoSkip: true,

                            font: {
                                family: "Poppins",
                                size: 10
                            }
                        },

                        border: {
                            color: "#29313b"
                        }
                    },

                    y: {
                        beginAtZero: true,

                        ticks: {
                            precision: 0,
                            color: "#a6adb7",

                            font: {
                                family: "Poppins",
                                size: 10
                            }
                        },

                        grid: {
                            color: "rgba(166, 173, 183, 0.08)"
                        },

                        border: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    /* GRAFIK TIPE KENDARAAN */
    const typeCanvas = document.getElementById("typeChart");

    if (typeCanvas && typeof window.Chart === "function") {
        const values = data.typeValues || [];
        const labels = data.typeLabels || [];
        const hasData = values.some(function (value) {
            return Number(value) > 0;
        });

        new Chart(typeCanvas, {
            type: "doughnut",

            data: {
                labels: labels,

                datasets: [{
                    data: hasData ? values : [1],

                    backgroundColor: hasData
                        ? [
                            "#e32636",
                            "#c7ccd2",
                            "#687587",
                            "#8e98a8",
                            "#454f5e"
                        ]
                        : ["#303844"],

                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "74%",

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        enabled: hasData,
                        backgroundColor: "#1a1d21",
                        titleColor: "#f5f6f7",
                        bodyColor: "#c7ccd2"
                    }
                }
            }
        });
    }

    /* PENCARIAN */
    const searchInput = document.getElementById("globalSearch");

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            const keyword = this.value.trim().toLowerCase();

            document.querySelectorAll(".searchable-item").forEach(function (item) {
                const content = (
                    item.dataset.search || item.textContent
                ).toLowerCase();

                item.hidden = !content.includes(keyword);
            });

            if (lenis) {
                requestAnimationFrame(function () {
                    lenis.resize();
                });
            }
        });
    }

    /* SIDEBAR MOBILE */
    const sidebarToggle = document.getElementById("sidebarToggle");
    const sidebarBackdrop = document.getElementById("sidebarBackdrop");
    const sidebarLinks = document.querySelectorAll(".sidebar-nav a");

    function closeSidebar() {
        document.body.classList.remove("sidebar-open");

        if (sidebarToggle) {
            sidebarToggle.setAttribute("aria-expanded", "false");
        }
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener("click", function () {
            const isOpen = document.body.classList.toggle("sidebar-open");

            sidebarToggle.setAttribute(
                "aria-expanded",
                String(isOpen)
            );
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener("click", closeSidebar);
    }

    sidebarLinks.forEach(function (link) {
        link.addEventListener("click", closeSidebar);
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeSidebar();
        }
    });

    /* PERBARUI UKURAN SCROLL SAAT LAYAR BERUBAH */
    window.addEventListener("resize", function () {
        if (lenis) {
            lenis.resize();
        }
    });
});