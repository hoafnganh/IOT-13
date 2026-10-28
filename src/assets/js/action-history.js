/* Action History — loc PHIA SERVER qua GET /actions, UX giu nguyen flow cu:
   1. "Loại tra cứu" chon Thiết bị / Action / Status -> hien THEM dropdown
      gia tri ben canh, chon gia tri la loc NGAY (param device/action/status).
   2. Thanh tim kiem chuoi luon hien, loc theo Thời gian (param time).
   3. Hai dieu kien ket hop AND. Bam "Đặt lại" xoa het.
   Bang tu cap nhat moi 5 giay (thay PENDING doi thanh SUCCESS/TIMEOUT). */

const filterType = document.getElementById("filterType");
const valueItem = document.getElementById("valueItem");
const valueSelect = document.getElementById("valueSelect");
const typeSearch = document.getElementById("typeSearch");
const resetBtn = document.querySelector(".reset-btn");
const recordLimit = document.getElementById("recordLimit");
const tableBody = document.querySelector(".data-table tbody");
const lastResponse = document.getElementById("lastResponse");
const paginationNav = document.getElementById("pagination");

/* Gia tri cua dropdown thu 2 cho tung loai tra cuu */
const valueOptions = {
    device: ["LED 1", "LED 2"],
    action: ["ON", "OFF"],
    status: ["PENDING", "SUCCESS", "FAILED", "TIMEOUT"]
};

let currentPage = 1;
let searchDebounce = null;
let requestGen = 0; /* bo qua fetch cu tra ve muon (doi filter lien tuc) */

function buildParams() {
    const params = {page: currentPage, limit: Number(recordLimit.value)};
    const type = filterType.value;

    if (type in valueOptions && valueSelect.value !== "") params[type] = valueSelect.value;

    const text = typeSearch.value.trim();
    if (text !== "") params.time = text;

    return params;
}

async function render() {
    const gen = ++requestGen;
    try {
        const result = await window.api.getActions(buildParams());
        if (gen !== requestGen) return;

        const pagination = result.pagination;
        if (currentPage > pagination.totalPages) currentPage = pagination.totalPages;

        renderActions(tableBody, result.data);
        renderPagination(paginationNav, pagination, currentPage, (page) => {
            currentPage = page;
            render();
        });
    } catch (error) {
        window.toast.error(error.message);
    }
}

/* Chon loai tra cuu: hien/an dropdown gia tri + nap danh sach gia tri.
   Chon xong bang chua loc cho den khi chon gia tri cu the (mac dinh "Tất cả"). */
filterType.addEventListener("change", () => {
    const type = filterType.value;

    if (type in valueOptions) {
        valueSelect.replaceChildren(
            ...["", ...valueOptions[type]].map((value) => {
                const option = document.createElement("option");
                option.value = value;
                option.textContent = value === "" ? "Tất cả" : value;
                return option;
            })
        );
        valueItem.style.display = "flex";
    } else {
        valueItem.style.display = "none";
    }

    currentPage = 1;
    render();
});

/* Chon gia tri -> loc ngay, khong can nhan nut tim kiem */
valueSelect.addEventListener("change", () => {
    currentPage = 1;
    render();
});

/* Go chuoi: loc theo thoi gian sau 300ms (chong go lien tuc) */
typeSearch.addEventListener("input", () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        currentPage = 1;
        render();
    }, 300);
});

resetBtn.addEventListener("click", () => {
    filterType.value = "";
    valueItem.style.display = "none";
    typeSearch.value = "";
    recordLimit.value = "10";
    currentPage = 1;
    render();
});

recordLimit.addEventListener("change", () => {
    currentPage = 1;
    render();
});

/* Tu lam tuoi moi 5 giay: thay duoc PENDING -> SUCCESS/TIMEOUT ngay tren bang */
setInterval(render, 5000);

/* "Phản hồi mới nhất" = do tre cua ban ghi moi nhat (khong theo bo loc) */
(async () => {
    try {
        const result = await window.api.getActions({page: 1, limit: 1});
        const newest = result.data[0];
        lastResponse.textContent = newest && newest.latencyMs != null ? `${newest.latencyMs} ms` : "--";
    } catch (error) {
        /* chua co du lieu thi de "--" */
    }
})();

render();
