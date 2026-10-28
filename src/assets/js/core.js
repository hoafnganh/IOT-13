/* Tien ich dung chung moi trang: toast, dinh dang thoi gian, dieu huong
   sidebar, phan trang, lop goi API /api/v1 va dang nhap that (session PHP). */

/* Thong bao nho goc tren man hinh: window.toast.success/error/info */
(function () {
    const stack = document.createElement("div");
    stack.className = "toast-stack";
    document.body.appendChild(stack);

    function show(message, kind, duration) {
        const item = document.createElement("div");
        item.className = `toast toast-${kind}`;
        item.textContent = message;
        stack.appendChild(item);
        setTimeout(() => item.remove(), duration || 3000);
    }

    window.toast = {
        success: (m, d) => show(m, "success", d),
        error: (m, d) => show(m, "error", d || 4000),
        info: (m, d) => show(m, "info", d)
    };
})();

/* Dinh dang thoi gian YYYY-MM-DD HH:mm:ss cho cac bang du lieu.
   Locale "sv-SE" cua trinh duyet tra dung dinh dang nay.
   Gia tri null (VD: respondedAt cua ban PENDING) hien "--". */
function formatDateTime(value) {
    if (!value) return "--";
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? "--" : d.toLocaleString("sv-SE");
}

/* Lop goi API duy nhat: moi request di qua /api/v1, kem cookie session.
   server tra {success:false,message} kem ma loi -> nem Error de trang bat. */
async function request(path, options = {}) {
    const response = await fetch(`/api/v1${path}`, {
        ...options,
        headers: {"Content-Type": "application/json", ...options.headers}
    });

    let result = {};
    try {
        result = await response.json();
    } catch (error) {
        result = {success: false, message: "Mất kết nối máy chủ"};
    }

    if (!response.ok) throw new Error(result.message || "Lỗi máy chủ");
    return result;
}

function buildQuery(params) {
    const query = new URLSearchParams(params).toString();
    return query ? `?${query}` : "";
}

window.api = {
    login: (username, password) =>
        request("/auth/login", {method: "POST", body: JSON.stringify({username, password})}),
    logout: () => request("/auth/logout", {method: "POST"}),
    getCurrentUser: () => request("/auth/me"),
    getDashboard: () => request("/dashboard"),
    getChart: (minutes = 30) => request(`/dashboard/chart?minutes=${minutes}`),
    getDevices: () => request("/devices"),
    getDevice: (id) => request(`/devices/${encodeURIComponent(id)}`),
    controlDevice: (id, action) =>
        request(`/devices/${encodeURIComponent(id)}/actions`, {method: "POST", body: JSON.stringify({action})}),
    getSensorData: (params = {}) => request(`/sensor-data${buildQuery(params)}`),
    getActions: (params = {}) => request(`/actions${buildQuery(params)}`)
};

/* Gan click cho 4 nut sidebar -> chuyen sang trang tuong ung */
function bindNav() {
    const paths = ["dashboard.html", "datasensor.html", "action-history.html", "profile.html"];
    document.querySelectorAll(".nav-btn").forEach((btn) => {
        btn.onclick = () => {
            const page = paths[btn.dataset.page];
            if (!window.location.pathname.includes(page)) window.location.href = page;
        };
    });
}

/* Ve day nut phan trang ‹ 1 2 3 4 5 › ; click so trang -> goTo(page).
   pagination la ket qua tu API {totalPages} hoac so trang (ban tinh cung duoc). */
function renderPagination(nav, pagination, currentPage, goTo) {
    nav.replaceChildren();
    const totalPages = (typeof pagination === "object" ? pagination.totalPages : pagination) || 1;

    const pageButton = (label, target, options = {}) => {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.textContent = label;
        if (options.active) btn.classList.add("page-active");
        if (options.disabled) btn.disabled = true;
        else btn.onclick = () => goTo(target);
        nav.appendChild(btn);
    };

    pageButton("‹", currentPage - 1, {disabled: currentPage <= 1});

    let start = Math.max(1, currentPage - 2);
    let end = Math.min(totalPages, start + 4);
    start = Math.max(1, end - 4);
    for (let p = start; p <= end; p++) {
        pageButton(String(p), p, {active: p === currentPage});
    }

    pageButton("›", currentPage + 1, {disabled: currentPage >= totalPages});
}

/* ---------------- Dang nhap / dang xuat (session PHP that) ---------------- */

const loginBtn = document.querySelector(".submit-btn");
const logoutBtn = document.querySelector(".logout-btn");

/* Nap ten + ma sinh vien vao khoi user duoi sidebar (va trang Profile) */
function fillSidebarUser(user) {
    const avatar = document.querySelector(".footer-sidebar .avatar");
    const userName = document.querySelector(".footer-sidebar .user b");
    const userCode = document.querySelector(".footer-sidebar .user span");
    const profileName = document.getElementById("profileUsername");

    if (user && avatar) {
        avatar.textContent = (user.username || "?").charAt(0).toUpperCase();
        userName.textContent = user.fullName || user.username;
        userCode.textContent = user.studentCode || user.role || "user";
    }
    if (user && profileName) profileName.textContent = user.username;
}

if (loginBtn) {
    /* Trang login: goi POST /auth/login, server tao session (cookie IOTSESSID) */
    loginBtn.onclick = async (event) => {
        event.preventDefault();
        const statusBox = document.querySelector(".false-status");
        statusBox.textContent = "";

        const username = document.getElementById("username").value.trim();
        const password = document.getElementById("password").value;
        if (!username || !password) {
            statusBox.textContent = "Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu";
            return;
        }

        loginBtn.disabled = true;
        loginBtn.textContent = "Đang đăng nhập...";
        try {
            await window.api.login(username, password);
            sessionStorage.setItem("loginsuccess", "true");
            window.location.href = "dashboard.html";
        } catch (error) {
            statusBox.textContent = error.message;
        } finally {
            loginBtn.disabled = false;
            loginBtn.textContent = "Đăng nhập";
        }
    };
} else {
    /* Trang noi bo: hoi /auth/me, chua dang nhap -> quay ve index.html */
    (async () => {
        try {
            const result = await window.api.getCurrentUser();
            fillSidebarUser(result.data.user);
        } catch (error) {
            window.location.href = "index.html";
        }
    })();

    bindNav();

    if (logoutBtn) {
        logoutBtn.onclick = async () => {
            try {
                await window.api.logout();
            } catch (error) {
                window.toast.error(error.message);
            }
            window.location.href = "index.html";
        };
    }

    /* Pill ESP8266: dashboard.js tu poll /dashboard nen chi cac trang khac
       can poll nhe GET /devices/esp01 moi 5 giay. */
    window.addEventListener("DOMContentLoaded", () => {
        if (window.hasDashboardPolling) return;

        const pollEspStatus = async () => {
            const pill = document.querySelector(".system-online");
            const text = document.getElementById("espStatusText");
            if (!pill || !text) return;
            try {
                const result = await window.api.getDevice("esp01");
                const online = result.data.connectionStatus === "online";
                pill.classList.toggle("is-online", online);
                pill.classList.toggle("is-offline", !online);
                text.textContent = online ? "1/1 ESP8266 Online" : "ESP8266 Offline";
            } catch (error) {
                pill.classList.remove("is-online");
                pill.classList.add("is-offline");
                text.textContent = "Mất kết nối máy chủ";
            }
        };

        pollEspStatus();
        setInterval(pollEspStatus, 5000);
    });
}
