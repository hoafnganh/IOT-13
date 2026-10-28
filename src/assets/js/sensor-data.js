/* Data Sensor — loc PHIA SERVER qua GET /sensor-data, UX giu nguyen flow cu:
   - "Loại tra cứu" chon cam bien/thoi gian -> thanh tim kiem xuat hien.
   - Loai cam bien chi nhan so/toan tu (>15, >=15.5) -> goi API param
     sensor + value; sai dinh dang -> vien do + hint, KHONG goi API.
   - Loai Thoi gian nhan chuoi (VD: 2026-10-24 hoac 20:15) -> param time.
   Phan trang cung phia server (page + limit). */

const nodeFilter = document.getElementById("nodeFilter");
const searchItem = document.getElementById("searchItem");
const typeSearchLabel = document.getElementById("typeSearchLabel");
const typeSearch = document.getElementById("typeSearch");
const searchHint = document.getElementById("searchHint");
const resetBtn = document.querySelector(".reset-btn");
const recordLimit = document.getElementById("recordLimit");
const tableBody = document.querySelector(".data-table tbody");
const lastUpdate = document.getElementById("lastUpdate");
const paginationNav = document.getElementById("pagination");

const typeMeta = {
    temperature: {label: "Nhiệt độ", placeholder: "VD: 15 hoặc >15, <20, >=15.5"},
    humidity: {label: "Độ ẩm", placeholder: "VD: 60 hoặc >60, <80"},
    light: {label: "Ánh sáng", placeholder: "VD: 50 hoặc >50, <90"},
    time: {label: "Thời gian", placeholder: "VD: 2026-10-24 hoặc 20:15"}
};

const NUMBER_PATTERN = /^(>=|<=|>|<|=)?\s*(-?\d+(?:\.\d+)?)$/;

let currentPage = 1;
let searchDebounce = null;
let requestGen = 0; /* bo qua fetch cu tra ve muon (doi filter lien tuc) */

/* Dong goi bo loc hien tai thanh query string cua API */
function buildParams() {
    const params = {page: currentPage, limit: Number(recordLimit.value)};
    const type = nodeFilter.value;
    const text = typeSearch.value.trim();

    if (type !== "" && text !== "") {
        if (type === "time") params.time = text;
        else {
            params.sensor = type;
            params.value = text;
        }
    }
    return params;
}

async function render() {
    /* Validate phia client truoc khi goi API (giong flow ban tinh cu) */
    const type = nodeFilter.value;
    const text = typeSearch.value.trim();
    if (type !== "" && type !== "time" && text !== "" && !NUMBER_PATTERN.test(text)) {
        typeSearch.classList.add("input-invalid");
        searchHint.textContent = "Chỉ nhập số hoặc toán tử so sánh: > < >= <= = (VD: >15, >=15.5)";
        return;
    }
    typeSearch.classList.remove("input-invalid");
    searchHint.textContent = "";

    const gen = ++requestGen;
    try {
        const result = await window.api.getSensorData(buildParams());
        if (gen !== requestGen) return;

        const pagination = result.pagination;
        if (currentPage > pagination.totalPages) currentPage = pagination.totalPages;

        renderSensorData(tableBody, result.data);
        renderPagination(paginationNav, pagination, currentPage, (page) => {
            currentPage = page;
            render();
        });
    } catch (error) {
        window.toast.error(error.message);
    }
}

/* Chon loai tra cuu -> hien thanh tim kiem phu hop; bang chua loc */
nodeFilter.addEventListener("change", () => {
    const type = nodeFilter.value;

    typeSearch.value = "";
    typeSearch.classList.remove("input-invalid");
    searchHint.textContent = "";

    if (type === "") {
        searchItem.style.display = "none";
    } else {
        typeSearchLabel.textContent = typeMeta[type].label;
        typeSearch.placeholder = typeMeta[type].placeholder;
        searchItem.style.display = "flex";
    }

    currentPage = 1;
    render();
});

/* Go/dan chuoi: loc sau 300ms (chong go lien tuc) */
typeSearch.addEventListener("input", () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        currentPage = 1;
        render();
    }, 300);
});

resetBtn.addEventListener("click", () => {
    nodeFilter.value = "";
    typeSearch.value = "";
    typeSearch.classList.remove("input-invalid");
    searchHint.textContent = "";
    searchItem.style.display = "none";
    recordLimit.value = "10";
    currentPage = 1;
    render();
});

recordLimit.addEventListener("change", () => {
    currentPage = 1;
    render();
});

/* "Cập nhật gần nhất" = ban ghi moi nhat (khong theo bo loc) */
(async () => {
    try {
        const result = await window.api.getSensorData({page: 1, limit: 1});
        if (result.data.length) lastUpdate.textContent = formatDateTime(result.data[0].recordedAt);
    } catch (error) {
        /* chua co du lieu thi de "--" */
    }
})();

render();
