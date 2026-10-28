/* Ve bang du lieu cho 2 trang Data Sensor + Action History.
   (Action History co them cot User — 10 cot.) */

function renderEmptyRow(tableBody, message, colSpan) {
    tableBody.replaceChildren();
    const cell = document.createElement("td");
    cell.colSpan = colSpan;
    cell.textContent = message;
    const row = document.createElement("tr");
    row.className = "empty-row";
    row.appendChild(cell);
    tableBody.appendChild(row);
}

function appendCell(row, value, className = "") {
    const cell = document.createElement("td");
    cell.textContent = value;
    cell.className = className;
    row.appendChild(cell);
    return cell;
}

/* Bang Data Sensor: ID, Sensor ID, Ten, Gia tri, Don vi, Thoi gian */
function renderSensorData(tableBody, items) {
    tableBody.replaceChildren();

    if (!items.length) {
        renderEmptyRow(tableBody, "Không tìm thấy dữ liệu phù hợp với điều kiện lọc", 6);
        return;
    }

    items.forEach((item) => {
        const row = document.createElement("tr");
        appendCell(row, `#${String(item.id).padStart(4, "0")}`);
        appendCell(row, item.sensorId || "--");
        appendCell(row, item.sensorName || "--");
        appendCell(row, Number(item.value).toLocaleString("vi-VN"), "value-cell");
        appendCell(row, item.unit || "--");
        appendCell(row, formatDateTime(item.recordedAt));
        tableBody.appendChild(row);
    });
}

/* Bang Action History: 9 cot (bo Request ID, co cot User), badge trang thai */
function renderActions(tableBody, items) {
    tableBody.replaceChildren();

    if (!items.length) {
        renderEmptyRow(tableBody, "Không tìm thấy dữ liệu lịch sử nào khớp với bộ lọc", 9);
        return;
    }

    items.forEach((item) => {
        const row = document.createElement("tr");
        const status = String(item.status || "PENDING").toLowerCase();

        appendCell(row, `#${String(item.id).padStart(4, "0")}`);
        appendCell(row, item.deviceId || "--");
        appendCell(row, item.deviceName || "--");
        appendCell(row, item.action || "--", `action-cell ${String(item.action || "").toLowerCase()}`);

        const statusCell = appendCell(row, "");
        const badge = document.createElement("span");
        badge.className = `status-badge status-${status}`;
        badge.textContent = String(item.status || "PENDING").toUpperCase();
        statusCell.appendChild(badge);

        appendCell(row, item.user || "--");
        appendCell(row, formatDateTime(item.requestedAt));
        appendCell(row, formatDateTime(item.respondedAt));
        appendCell(row, item.latencyMs == null ? "--" : `${item.latencyMs} ms`);

        tableBody.appendChild(row);
    });
}
